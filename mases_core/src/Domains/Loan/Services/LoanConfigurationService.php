<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanConfigurationSubmission;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanProduct;
use MaaleArna\MASES\Core\Policy\Services\VersionedPolicyRegistry;

final class LoanConfigurationService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly VersionedPolicyRegistry $policies,
    ) {
    }

    public function createDraft(int $tenantId, User $actor, array $configuration): LoanConfigurationSubmission
    {
        $this->authorize('loan.configuration.manage', $actor, $tenantId);
        $this->validateConfiguration($configuration);

        return DB::transaction(function () use ($tenantId, $actor, $configuration): LoanConfigurationSubmission {
            $submission = LoanConfigurationSubmission::create([
                'tenant_id' => $tenantId,
                'created_by' => $actor->getKey(),
                'status' => 'draft',
                'configuration' => $configuration,
            ]);
            $this->audit($submission, $actor, 'draft_created');
            return $submission->refresh();
        });
    }

    public function submit(int $tenantId, int $submissionId, User $actor): LoanConfigurationSubmission
    {
        $this->authorize('loan.configuration.submit', $actor, $tenantId);

        return DB::transaction(function () use ($tenantId, $submissionId, $actor): LoanConfigurationSubmission {
            $submission = $this->findForTenant($tenantId, $submissionId);
            if ($submission->status !== 'draft' || (int) $submission->created_by !== (int) $actor->getKey()) {
                throw ValidationException::withMessages(['submission' => ['Only the draft author may submit a draft submission.']]);
            }
            $this->validateConfiguration($submission->configuration);
            $submission->forceFill(['status' => 'submitted', 'submitted_at' => now()])->save();
            $this->audit($submission, $actor, 'submitted');
            return $submission->refresh();
        });
    }

    public function review(int $tenantId, int $submissionId, User $actor, bool $approve, ?string $note = null): LoanConfigurationSubmission
    {
        $this->authorize('loan.configuration.approve', $actor, $tenantId);

        return DB::transaction(function () use ($tenantId, $submissionId, $actor, $approve, $note): LoanConfigurationSubmission {
            $submission = $this->findForTenant($tenantId, $submissionId);
            if ($submission->status !== 'submitted') {
                throw ValidationException::withMessages(['submission' => ['Only submitted configurations can be reviewed.']]);
            }
            if ((int) $submission->created_by === (int) $actor->getKey()) {
                throw ValidationException::withMessages(['reviewer' => ['The author cannot approve or reject their own configuration.']]);
            }

            $fields = [
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
                'review_note' => $note,
                'status' => $approve ? 'approved' : 'rejected',
            ];

            if ($approve) {
                $config = $submission->configuration;
                $effectiveFrom = CarbonImmutable::parse($config['effective_from']);
                $policy = $this->policies->create(
                    'loan_product_rules',
                    $config['financial_policy'],
                    $effectiveFrom,
                );
                $product = LoanProduct::create([
                    'tenant_id' => $tenantId,
                    'code' => $config['product']['code'],
                    'name_am' => $config['product']['name_am'] ?? null,
                    'name_en' => $config['product']['name_en'] ?? null,
                    'description_am' => $config['product']['description_am'] ?? null,
                    'description_en' => $config['product']['description_en'] ?? null,
                    'purpose' => $config['product']['purpose'] ?? null,
                    'currency' => strtoupper($config['product']['currency']),
                    'min_principal' => $config['product']['min_principal'],
                    'max_principal' => $config['product']['max_principal'],
                    'min_term_months' => $config['product']['min_term_months'],
                    'max_term_months' => $config['product']['max_term_months'],
                    'default_installment_frequency' => 'monthly',
                    'is_active' => false,
                    'product_rules_policy_version_id' => $policy->getKey(),
                ]);
                $fields['published_policy_version_id'] = $policy->getKey();
                $fields['published_product_id'] = $product->getKey();
            }

            $submission->forceFill($fields)->save();
            $this->audit($submission, $actor, $approve ? 'approved_and_published_inactive' : 'rejected', ['note' => $note]);
            return $submission->refresh();
        });
    }

    public function activate(int $tenantId, int $submissionId, User $actor): LoanConfigurationSubmission
    {
        $this->authorize('loan.configuration.activate', $actor, $tenantId);

        return DB::transaction(function () use ($tenantId, $submissionId, $actor): LoanConfigurationSubmission {
            $submission = $this->findForTenant($tenantId, $submissionId);
            if ($submission->status !== 'approved' || !$submission->published_product_id || !$submission->published_policy_version_id) {
                throw ValidationException::withMessages(['submission' => ['Only an approved and published product can be activated.']]);
            }
            $product = LoanProduct::query()->where('tenant_id', $tenantId)->whereKey($submission->published_product_id)->lockForUpdate()->firstOrFail();
            $product->forceFill(['is_active' => true])->save();
            $submission->forceFill(['status' => 'active', 'activated_at' => now()])->save();
            $this->audit($submission, $actor, 'product_activated', ['product_id' => $product->getKey()]);
            return $submission->refresh();
        });
    }

    private function validateConfiguration(array $configuration): void
    {
        $product = $configuration['product'] ?? null;
        $policy = $configuration['financial_policy'] ?? null;
        $errors = [];

        foreach (['code', 'currency', 'min_principal', 'max_principal', 'min_term_months', 'max_term_months'] as $key) {
            if (!is_array($product) || !array_key_exists($key, $product) || $product[$key] === '') {
                $errors['product.' . $key][] = 'This product field is required.';
            }
        }
        if (is_array($product)) {
            if (isset($product['code']) && (!is_string($product['code']) || !preg_match('/^[A-Z0-9_-]{2,32}$/', $product['code']))) {
                $errors['product.code'][] = 'Use 2–32 uppercase letters, digits, underscores or hyphens.';
            }
            if (isset($product['currency']) && (!is_string($product['currency']) || !preg_match('/^[A-Za-z]{3}$/', $product['currency']))) {
                $errors['product.currency'][] = 'Currency must be a three-letter ISO code.';
            }
            if (isset($product['min_principal'], $product['max_principal']) &&
                (!is_numeric($product['min_principal']) || !is_numeric($product['max_principal']) ||
                 bccomp((string) $product['min_principal'], (string) $product['max_principal'], 4) > 0)) {
                $errors['product.max_principal'][] = 'Maximum principal must be greater than or equal to minimum principal.';
            }
            if (isset($product['min_term_months'], $product['max_term_months']) &&
                (!is_int($product['min_term_months']) || !is_int($product['max_term_months']) ||
                 $product['min_term_months'] < 1 || $product['max_term_months'] < $product['min_term_months'])) {
                $errors['product.max_term_months'][] = 'Term bounds must be positive integers with maximum >= minimum.';
            }
            if (empty($product['name_am']) && empty($product['name_en'])) {
                $errors['product.name_am'][] = 'At least one product name is required.';
            }
        }

        $sections = ['interest', 'term', 'arrears', 'collateral', 'fees'];
        if (!is_array($policy)) {
            $errors['financial_policy'][] = 'Financial policy must be an object.';
        } else {
            foreach ($sections as $section) {
                if (!array_key_exists($section, $policy) || !is_array($policy[$section])) {
                    $errors['financial_policy.' . $section][] = 'This policy section is required.';
                }
            }
            if (isset($policy['interest']) && (!isset($policy['interest']['annual_rate']) ||
                !is_string($policy['interest']['annual_rate']) ||
                !preg_match('/^(0|[0-9]+)(\.[0-9]{1,8})?$/', $policy['interest']['annual_rate']))) {
                $errors['financial_policy.interest.annual_rate'][] = 'Annual rate must be a non-negative decimal string.';
            }
            if (isset($policy['term']) && (!isset($policy['term']['min_months'], $policy['term']['max_months']) ||
                !is_int($policy['term']['min_months']) || !is_int($policy['term']['max_months']) ||
                $policy['term']['min_months'] < 1 || $policy['term']['max_months'] < $policy['term']['min_months'])) {
                $errors['financial_policy.term'][] = 'Term policy bounds must be valid positive integers.';
            }
            if (isset($policy['arrears']) && ($policy['arrears']['enabled'] ?? null) === true &&
                (!isset($policy['arrears']['penalty_rate'], $policy['arrears']['grace_days']) ||
                 !is_string($policy['arrears']['penalty_rate']) || !is_int($policy['arrears']['grace_days']) ||
                 $policy['arrears']['grace_days'] < 0)) {
                $errors['financial_policy.arrears'][] = 'Enabled arrears requires a decimal penalty rate and non-negative grace days.';
            }
            if (isset($policy['collateral']) && !is_bool($policy['collateral']['required'] ?? null)) {
                $errors['financial_policy.collateral.required'][] = 'Collateral required must be explicitly true or false.';
            }
            if (isset($policy['fees']) && !array_is_list($policy['fees'])) {
                $errors['financial_policy.fees'][] = 'Fees must be a list; use an empty list when no fees apply.';
            }
        }

        if (!isset($configuration['effective_from']) || !is_string($configuration['effective_from']) ||
            strtotime($configuration['effective_from']) === false) {
            $errors['effective_from'][] = 'A valid effective-from timestamp is required.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function findForTenant(int $tenantId, int $submissionId): LoanConfigurationSubmission
    {
        return LoanConfigurationSubmission::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($submissionId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function authorize(string $permission, User $actor, int $tenantId): void
    {
        if (!$this->authorization->hasPermission($permission, $actor, $tenantId, 'loan')) {
            abort(403, 'You are not authorized to manage this loan configuration.');
        }
    }

    private function audit(LoanConfigurationSubmission $submission, User $actor, string $event, array $metadata = []): void
    {
        DB::table('loan_configuration_audit_logs')->insert([
            'submission_id' => $submission->getKey(),
            'tenant_id' => $submission->tenant_id,
            'actor_id' => $actor->getKey(),
            'event' => $event,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
