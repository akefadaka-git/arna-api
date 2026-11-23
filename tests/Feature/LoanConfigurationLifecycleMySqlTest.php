<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanProduct;
use MaaleArna\MASES\Core\Domains\Loan\Services\LoanConfigurationService;
use MaaleArna\MASES\Core\Policy\Models\VersionedPolicy;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\Concerns\LoanDatabaseMigrations;
use Tests\TestCase;

final class LoanConfigurationLifecycleMySqlTest extends TestCase
{
    use LoanDatabaseMigrations;

    private Tenant $tenant;
    private Tenant $otherTenant;
    private User $author;
    private User $reviewer;
    private User $otherTenantReviewer;
    private LoanConfigurationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->tenant = $this->makeTenant('loan-config-a', 'የብድር ውቅር ሀ');
        $this->otherTenant = $this->makeTenant('loan-config-b', 'የብድር ውቅር ለ');
        $this->author = $this->makeUser($this->tenant, 'author');
        $this->reviewer = $this->makeUser($this->tenant, 'reviewer');
        $this->otherTenantReviewer = $this->makeUser($this->otherTenant, 'other-reviewer');

        foreach ([$this->author, $this->reviewer, $this->otherTenantReviewer] as $user) {
            $this->grantConfigurationPermissions($user);
        }

        Cache::flush();
        $this->service = app(LoanConfigurationService::class);
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Carbon::setTestNow();
        Cache::flush();
        parent::tearDown();
    }

    public function test_mysql_full_lifecycle_publishes_immutable_policy_then_activates_only_after_effective_date(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-11-23 10:00:00');
        $configuration = $this->validConfiguration();
        $draft = $this->service->createDraft((int) $this->tenant->id, $this->author, $configuration);

        $this->assertSame('draft', $draft->status);
        $this->assertDatabaseHas('loan_configuration_audit_logs', [
            'submission_id' => $draft->id,
            'actor_id' => $this->author->id,
            'event' => 'draft_created',
        ]);

        $configuration['product']['name_en'] = 'Revised test product';
        $draft = $this->service->updateDraft((int) $this->tenant->id, (int) $draft->id, $this->author, $configuration);
        $this->assertSame('Revised test product', $draft->configuration['product']['name_en']);

        $submitted = $this->service->submit((int) $this->tenant->id, (int) $draft->id, $this->author);
        $this->assertSame('submitted', $submitted->status);
        $this->assertNotNull($submitted->submitted_at);

        $approved = $this->service->review(
            (int) $this->tenant->id,
            (int) $submitted->id,
            $this->reviewer,
            true,
            'Independent review passed',
        );

        $this->assertSame('approved', $approved->status);
        $this->assertSame($this->reviewer->id, $approved->reviewed_by);
        $this->assertNotNull($approved->published_policy_version_id);
        $this->assertNotNull($approved->published_product_id);

        $policy = VersionedPolicy::query()->findOrFail($approved->published_policy_version_id);
        $product = LoanProduct::query()->findOrFail($approved->published_product_id);
        $this->assertSame('loan_product_rules', $policy->policy_type);
        $this->assertSame($configuration['financial_policy'], $policy->payload);
        $this->assertSame('2026-12-01', $policy->effective_from->toDateString());
        $this->assertFalse((bool) $product->is_active);
        $this->assertSame($policy->id, $product->product_rules_policy_version_id);

        try {
            $policy->payload = ['interest' => ['annual_rate' => '0.99']];
            $policy->save();
            $this->fail('Published policy versions must be immutable.');
        } catch (LogicException) {
            $this->assertSame($configuration['financial_policy'], $policy->fresh()->payload);
        }

        try {
            $this->service->activate((int) $this->tenant->id, (int) $approved->id, $this->reviewer);
            $this->fail('Activation before effective_from must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('effective_from', $exception->errors());
        }
        $this->assertFalse((bool) $product->fresh()->is_active);

        \Illuminate\Support\Carbon::setTestNow('2026-12-01 00:00:00');
        $active = $this->service->activate((int) $this->tenant->id, (int) $approved->id, $this->reviewer);
        $this->assertSame('active', $active->status);
        $this->assertNotNull($active->activated_at);
        $this->assertTrue((bool) $product->fresh()->is_active);

        $events = DB::table('loan_configuration_audit_logs')
            ->where('submission_id', $draft->id)
            ->orderBy('id')
            ->pluck('event')
            ->all();
        $this->assertSame([
            'draft_created',
            'draft_updated',
            'submitted',
            'approved_and_published_inactive',
            'product_activated',
        ], $events);

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_author_cannot_self_review_and_submitted_configuration_cannot_be_edited(): void
    {
        $draft = $this->service->createDraft((int) $this->tenant->id, $this->author, $this->validConfiguration());

        try {
            $this->service->updateDraft((int) $this->tenant->id, (int) $draft->id, $this->reviewer, $this->validConfiguration());
            $this->fail('A non-author must not edit another user\'s draft.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('submission', $exception->errors());
        }

        try {
            $this->service->review((int) $this->tenant->id, (int) $draft->id, $this->reviewer, true);
            $this->fail('A draft cannot be reviewed before submission.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('submission', $exception->errors());
        }

        $submitted = $this->service->submit((int) $this->tenant->id, (int) $draft->id, $this->author);

        try {
            $this->service->review((int) $this->tenant->id, (int) $submitted->id, $this->author, true);
            $this->fail('An author must not approve their own submission.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reviewer', $exception->errors());
        }

        try {
            $this->service->updateDraft((int) $this->tenant->id, (int) $submitted->id, $this->author, $this->validConfiguration());
            $this->fail('Submitted configuration must not be editable.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('submission', $exception->errors());
        }

        $this->assertSame('submitted', $submitted->fresh()->status);
        $this->assertSame(0, VersionedPolicy::query()->where('policy_type', 'loan_product_rules')->count());
        $this->assertSame(0, LoanProduct::query()->count());
    }

    public function test_rejection_and_tenant_scoping_do_not_publish_or_expose_another_tenant_submission(): void
    {
        $draft = $this->service->createDraft((int) $this->tenant->id, $this->author, $this->validConfiguration());
        $submitted = $this->service->submit((int) $this->tenant->id, (int) $draft->id, $this->author);

        try {
            $this->service->review((int) $this->otherTenant->id, (int) $submitted->id, $this->otherTenantReviewer, true);
            $this->fail('A tenant reviewer must not access another tenant submission.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->assertSame(0, LoanProduct::query()->count());
        }

        $rejected = $this->service->review(
            (int) $this->tenant->id,
            (int) $submitted->id,
            $this->reviewer,
            false,
            'Returned for revision',
        );
        $this->assertSame('rejected', $rejected->status);
        $this->assertNull($rejected->published_policy_version_id);
        $this->assertNull($rejected->published_product_id);
        $this->assertSame(0, VersionedPolicy::query()->where('policy_type', 'loan_product_rules')->count());
        $this->assertSame(0, LoanProduct::query()->count());
        $this->assertSame(1, $this->service->listForTenant((int) $this->tenant->id, $this->reviewer)->total());
        $this->assertSame(0, $this->service->listForTenant((int) $this->otherTenant->id, $this->otherTenantReviewer)->total());
    }

    public function test_configuration_rejects_policy_terms_not_enforced_by_current_loan_consumers(): void
    {
        $unsupported = [
            ['term', 'interest_grace_months', 1],
            ['collateral', 'required', true],
        ];

        foreach ($unsupported as [$section, $key, $value]) {
            $configuration = $this->validConfiguration();
            $configuration['financial_policy'][$section][$key] = $value;

            try {
                $this->service->createDraft((int) $this->tenant->id, $this->author, $configuration);
                $this->fail("Unsupported {$section}.{$key} must be rejected.");
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }

        $configuration = $this->validConfiguration();
        $configuration['financial_policy']['fees'] = [[
            'code' => 'APPLICATION_FEE',
            'amount' => '25.00',
            'component' => 'fee',
        ]];

        try {
            $this->service->createDraft((int) $this->tenant->id, $this->author, $configuration);
            $this->fail('Non-empty fees must be rejected until disbursement records fee obligations.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('financial_policy.fees', $exception->errors());
        }
    }

    private function validConfiguration(): array
    {
        return [
            'effective_from' => '2026-12-01T00:00:00Z',
            'product' => [
                'code' => 'TEST_LOAN',
                'name_en' => 'Test loan product',
                'currency' => 'ETB',
                'min_principal' => '100.0000',
                'max_principal' => '10000.0000',
                'min_term_months' => 1,
                'max_term_months' => 12,
            ],
            'financial_policy' => [
                'interest' => [
                    'basis' => 'declining_balance',
                    'rate_representation' => 'annual_percentage',
                    'computation_method' => 'annuity',
                    'periodicity' => 'monthly',
                    'day_count' => 'actual_365',
                    'rounding' => 'half_up',
                    'precision' => 4,
                    'annual_rate' => '0.12',
                ],
                'term' => [
                    'min_months' => 1,
                    'max_months' => 12,
                    'frequency' => 'monthly',
                    'principal_grace_months' => 0,
                    'interest_grace_months' => 0,
                ],
                'arrears' => [
                    'enabled' => true,
                    'penalty_rate' => '0.05',
                    'grace_days' => 5,
                    'basis' => 'overdue_installment',
                    'cap' => null,
                ],
                'collateral' => [
                    'required' => false,
                    'permitted_types' => [],
                    'coverage_ratio' => null,
                ],
                'fees' => [],
            ],
        ];
    }

    private function makeTenant(string $slug, string $nameAm): Tenant
    {
        return Tenant::create([
            'slug' => $slug,
            'name_am' => $nameAm,
            'name_en' => ucfirst(str_replace('-', ' ', $slug)),
            'domain' => $slug . '.example.test',
            'is_active' => true,
        ]);
    }

    private function makeUser(Tenant $tenant, string $label): User
    {
        return User::factory()->create([
            'email' => $label . '-' . uniqid() . '@example.test',
            'default_tenant_id' => $tenant->id,
        ]);
    }

    private function grantConfigurationPermissions(User $user): void
    {
        foreach ([
            'loan.configuration.view',
            'loan.configuration.manage',
            'loan.configuration.submit',
            'loan.configuration.approve',
            'loan.configuration.activate',
        ] as $slug) {
            $permission = Permission::query()->firstOrCreate(
                ['slug' => $slug],
                [
                    'name_am' => 'የብድር ውቅር',
                    'name_en' => 'Loan configuration',
                    'category' => 'loan',
                    'scope' => 'platform',
                    'tenant_id' => null,
                    'application_id' => null,
                    'is_active' => true,
                    'is_system' => true,
                ],
            );
            $user->permissions()->attach($permission->getKey(), [
                'assigned_at' => now(),
                'assigned_by' => $user->getKey(),
            ]);
        }
    }
}
