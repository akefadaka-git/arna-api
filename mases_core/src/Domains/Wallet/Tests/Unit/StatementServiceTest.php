<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Domains\Wallet\Enums\ObligationComponent;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Obligation;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Payment;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;
use MaaleArna\MASES\Core\Domains\Wallet\Models\WalletEntry;
use MaaleArna\MASES\Core\Domains\Wallet\Services\ObligationService;
use MaaleArna\MASES\Core\Domains\Wallet\Services\PaymentService;
use MaaleArna\MASES\Core\Domains\Wallet\Services\StatementService;
use MaaleArna\MASES\Core\Domains\Wallet\Services\WalletService;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * StatementServiceTest
 *
 * Unit tests for StatementService (2B.19).
 *
 * =============================================================================
 * Scope
 * =============================================================================
 * Exercises the single public method of StatementService in isolation:
 *
 *     generate()  — produce a statement projection for a wallet and a
 *                   period.
 *
 * The service is READ-ONLY: it opens no transaction, writes no rows, and
 * dispatches no events. Its only inputs are a persisted wallet and two
 * Carbon instances; its only output is a plain array with the frozen
 * schema documented at 2B.19 §"generate".
 *
 * =============================================================================
 * RefreshDatabase, not DatabaseMigrations
 * =============================================================================
 * Unlike 2B.57–2B.60, this suite uses RefreshDatabase. The reasoning:
 *
 *   - StatementService dispatches NO events. There is nothing to assert
 *     against a `DB::afterCommit()` callback, so the DatabaseMigrations
 *     precondition does not apply.
 *
 *   - The service writes NO rows. There is no "operation that must
 *     commit" to preserve across the test boundary.
 *
 *   - The fixture-building services (WalletService, ObligationService,
 *     PaymentService) DO register after-commit callbacks on the outer
 *     transaction, but those callbacks are never asserted against and
 *     are discarded when the wrapper transaction is rolled back. Their
 *     non-firing has no effect on the statement projections the tests
 *     examine.
 *
 * RefreshDatabase is therefore the correct choice under the §2.13
 * convention ("RefreshDatabase unless asserting on DB::afterCommit
 * events"), and is materially faster than DatabaseMigrations for a
 * read-only suite of this size.
 *
 * =============================================================================
 * Period semantics `[from, to)`
 * =============================================================================
 * The half-open period is the service's most consequential behavioural
 * decision (2B.19 §"Period semantics"). Four boundary tests explicitly
 * verify:
 *
 *   - An entry whose `occurred_at` equals `from` is INCLUDED.
 *   - An entry whose `occurred_at` equals `to` is EXCLUDED.
 *   - An entry strictly before `from` is excluded from `period_entries`
 *     but reflected in `opening_balance`.
 *   - An entry strictly after `to` is excluded everywhere.
 *
 * =============================================================================
 * Read-only invariant
 * =============================================================================
 * Two tests verify that generating a statement does not mutate anything:
 *
 *   test_generate_does_not_write_any_rows — row counts across four
 *     tables are identical before and after.
 *   test_generate_does_not_change_wallet_balance — the wallet's
 *     `current_balance` is unchanged.
 *
 * =============================================================================
 * Fixture helper naming (v1.0.1 — collision fix)
 * =============================================================================
 * The helper that creates a Tenant is named `makeTenant()` — not
 * `createTenant()`. The base class `Tests\TestCase` declares a protected
 * method `createTenant(array $attributes = [], User|array|null $users = null)`;
 * redeclaring it in a child class with a different signature produces
 * either a fatal access-level error or a `TypeError` at the call site.
 * Renaming to `makeTenant()` avoids the collision.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   D113                         — Statement model
 *   Architecture v1.2.0 §3.7    — WalletEntry ledger table
 *   Architecture v1.2.0 §4.2    — Sign convention
 *   Architecture v1.2.0 §4.6    — Balance projection invariant
 *   Architecture v1.2.0 §5.1    — Entry-type vocabulary
 *   Architecture v1.2.0 §11     — Statement projection boundary
 *   Architecture v1.2.0 §11.1   — Projection, not materialization
 *   Architecture v1.2.0 §11.2   — Content (per D113)
 *   Architecture v1.2.0 §11.4   — Debit / credit column definitions
 *   Step 5 (manifest) 2B.61     — This file
 *   §2.13 (conventions)         — Test conventions
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Tests\Unit
 * @version 1.0.1
 */
final class StatementServiceTest extends TestCase
{
    use RefreshDatabase;

    private StatementService $service;
    private WalletService $walletService;
    private ObligationService $obligationService;
    private PaymentService $paymentService;

    private Tenant $tenant;
    private Shareholder $shareholder;
    private Wallet $wallet;

    // =========================================================================
    // Lifecycle
    // =========================================================================

    protected function setUp(): void
    {
        gc_collect_cycles();

        parent::setUp();

        $this->service           = app(StatementService::class);
        $this->walletService     = app(WalletService::class);
        $this->obligationService = app(ObligationService::class);
        $this->paymentService    = app(PaymentService::class);

        $this->tenant      = $this->makeTenant('main-tenant', 'ዋና ቴናንት');
        $this->shareholder = $this->createShareholder($this->tenant);
        $this->wallet      = $this->openWallet();

        gc_collect_cycles();
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();

        parent::tearDown();

        gc_collect_cycles();
    }

    // =========================================================================
    // Empty period
    // =========================================================================

    public function test_empty_period_has_zero_opening_and_closing(): void
    {
        $statement = $this->service->generate(
            wallet: $this->wallet,
            from: Carbon::create(2026, 1, 1),
            to: Carbon::create(2026, 2, 1),
        );

        $this->assertSame('0.0000', $statement['opening_balance']);
        $this->assertSame('0.0000', $statement['closing_balance']);
        $this->assertSame('0.0000', $statement['debit_total']);
        $this->assertSame('0.0000', $statement['credit_total']);
        $this->assertSame([], $statement['period_entries']);
    }

    public function test_empty_period_after_prior_entries_has_prior_closing_balance(): void
    {
        $this->recordCredit('500.0000', Carbon::create(2026, 1, 5, 10));

        $statement = $this->service->generate(
            wallet: $this->wallet,
            from: Carbon::create(2026, 2, 1),
            to: Carbon::create(2026, 3, 1),
        );

        $this->assertSame('500.0000', $statement['opening_balance']);
        $this->assertSame('500.0000', $statement['closing_balance']);
        $this->assertSame([], $statement['period_entries']);
    }

    // =========================================================================
    // Period content
    // =========================================================================

    public function test_single_debit_entry_produces_debit_total_only(): void
    {
        $this->recordDebit('300.0000', Carbon::create(2026, 1, 15, 9));

        $statement = $this->service->generate(
            wallet: $this->wallet,
            from: Carbon::create(2026, 1, 1),
            to: Carbon::create(2026, 2, 1),
        );

        $this->assertSame('300.0000', $statement['debit_total']);
        $this->assertSame('0.0000', $statement['credit_total']);
        $this->assertCount(1, $statement['period_entries']);
    }

    public function test_single_credit_entry_produces_credit_total_only(): void
    {
        $this->recordCredit('200.0000', Carbon::create(2026, 1, 15, 9));

        $statement = $this->service->generate(
            wallet: $this->wallet,
            from: Carbon::create(2026, 1, 1),
            to: Carbon::create(2026, 2, 1),
        );

        $this->assertSame('0.0000', $statement['debit_total']);
        $this->assertSame('200.0000', $statement['credit_total']);
        $this->assertCount(1, $statement['period_entries']);
    }

    public function test_multiple_entries_sum_into_totals(): void
    {
        $this->recordDebit('300.0000', Carbon::create(2026, 1, 5, 9));
        $this->recordCredit('200.0000', Carbon::create(2026, 1, 10, 9));
        $this->recordDebit('100.0000', Carbon::create(2026, 1, 15, 9));
        $this->recordCredit('400.0000', Carbon::create(2026, 1, 20, 9));

        $statement = $this->service->generate(
            wallet: $this->wallet,
            from: Carbon::create(2026, 1, 1),
            to: Carbon::create(2026, 2, 1),
        );

        $this->assertSame('400.0000', $statement['debit_total']);
        $this->assertSame('600.0000', $statement['credit_total']);
        $this->assertCount(4, $statement['period_entries']);
    }

    public function test_closing_balance_matches_last_entry(): void
    {
        $this->recordCredit('500.0000', Carbon::create(2026, 1, 5, 9));
        $this->recordDebit('300.0000', Carbon::create(2026, 1, 10, 9));

        $statement = $this->service->generate(
            wallet: $this->wallet,
            from: Carbon::create(2026, 1, 1),
            to: Carbon::create(2026, 2, 1),
        );

        $this->assertSame('200.0000', $statement['closing_balance']);
        $this->assertSame('0.0000', $statement['opening_balance']);
    }

    public function test_opening_balance_reflects_prior_entry(): void
    {
        $this->recordCredit('500.0000', Carbon::create(2026, 1, 5, 9));
        $this->recordCredit('300.0000', Carbon::create(2026, 1, 15, 9));

        $statement = $this->service->generate(
            wallet: $this->wallet,
            from: Carbon::create(2026, 1, 10),
            to: Carbon::create(2026, 1, 20),
        );

        $this->assertSame('500.0000', $statement['opening_balance']);
        $this->assertSame('800.0000', $statement['closing_balance']);
        $this->assertCount(1, $statement['period_entries']);
    }

    public function test_statement_carries_wallet_currency(): void
    {
        $statement = $this->service->generate(
            wallet: $this->wallet,
            from: Carbon::create(2026, 1, 1),
            to: Carbon::create(2026, 2, 1),
        );

        $this->assertSame('ETB', $statement['currency']);
    }

    // =========================================================================
    // Debit / credit split
    // =========================================================================

    public function test_obligation_entry_is_debit(): void
    {
        $this->recordDebit('300.0000', Carbon::create(2026, 1, 10, 9));

        $statement = $this->service->generate(
            wallet: $this->wallet,
            from: Carbon::create(2026, 1, 1),
            to: Carbon::create(2026, 2, 1),
        );

        $entry = $statement['period_entries'][0];
        $this->assertSame('300.0000', $entry['debit']);
        $this->assertSame('0.0000', $entry['credit']);
        $this->assertSame('-300.0000', $entry['signed_quantity']);
    }

    public function test_payment_entry_is_credit(): void
    {
        $this->recordCredit('200.0000', Carbon::create(2026, 1, 10, 9));

        $statement = $this->service->generate(
            wallet: $this->wallet,
            from: Carbon::create(2026, 1, 1),
            to: Carbon::create(2026, 2, 1),
        );

        $entry = $statement['period_entries'][0];
        $this->assertSame('0.0000', $entry['debit']);
        $this->assertSame('200.0000', $entry['credit']);
        $this->assertSame('200.0000', $entry['signed_quantity']);
    }

    public function test_positive_adjustment_is_credit(): void
    {
        $this->walletService->adjust($this->wallet, '+150.0000');

        $statement = $this->service->generate(
            wallet: $this->wallet,
            from: Carbon::now()->subDay(),
            to: Carbon::now()->addDay(),
        );

        $this->assertCount(1, $statement['period_entries']);
        $entry = $statement['period_entries'][0];
        $this->assertSame('150.0000', $entry['credit']);
        $this->assertSame('0.0000', $entry['debit']);
    }

    public function test_negative_adjustment_is_debit(): void
    {
        $this->walletService->adjust($this->wallet, '-150.0000');

        $statement = $this->service->generate(
            wallet: $this->wallet,
            from: Carbon::now()->subDay(),
            to: Carbon::now()->addDay(),
        );

        $entry = $statement['period_entries'][0];
        $this->assertSame('150.0000', $entry['debit']);
        $this->assertSame('0.0000', $entry['credit']);
    }

    public function test_reversal_appears_with_opposite_sign(): void
    {
        $original = $this->walletService->adjust($this->wallet, '+500.0000');
        $this->walletService->reverseEntry($original);

        $statement = $this->service->generate(
            wallet: $this->wallet,
            from: Carbon::now()->subDay(),
            to: Carbon::now()->addDay(),
        );

        $this->assertCount(2, $statement['period_entries']);

        $credit = $statement['period_entries'][0];
        $reversal = $statement['period_entries'][1];

        $this->assertSame('credit', $credit['debit'] === '0.0000' ? 'credit' : 'debit');
        $this->assertSame('500.0000', $credit['credit']);
        $this->assertSame('500.0000', $reversal['debit']);
        $this->assertSame('0.0000', $reversal['credit']);
    }

    // =========================================================================
    // Period boundary — [from, to)
    // =========================================================================

    public function test_entry_at_exactly_from_is_included(): void
    {
        $from = Carbon::create(2026, 1, 10, 12, 0, 0);
        $this->recordCredit('100.0000', $from);

        $statement = $this->service->generate(
            wallet: $this->wallet,
            from: $from,
            to: $from->copy()->addDay(),
        );

        $this->assertCount(1, $statement['period_entries']);
    }

    public function test_entry_at_exactly_to_is_excluded(): void
    {
        $from = Carbon::create(2026, 1, 10, 12, 0, 0);
        $to   = Carbon::create(2026, 1, 11, 12, 0, 0);

        $this->recordCredit('100.0000', $to);

        $statement = $this->service->generate(
            wallet: $this->wallet,
            from: $from,
            to: $to,
        );

        $this->assertCount(0, $statement['period_entries']);
        $this->assertSame('0.0000', $statement['credit_total']);
    }

    public function test_entry_before_from_is_excluded_but_reflected_in_opening(): void
    {
        $from = Carbon::create(2026, 1, 10, 12, 0, 0);
        $this->recordCredit('300.0000', $from->copy()->subDay());

        $statement = $this->service->generate(
            wallet: $this->wallet,
            from: $from,
            to: $from->copy()->addDay(),
        );

        $this->assertCount(0, $statement['period_entries']);
        $this->assertSame('300.0000', $statement['opening_balance']);
        $this->assertSame('300.0000', $statement['closing_balance']);
    }

    public function test_entry_after_to_is_excluded(): void
    {
        $to = Carbon::create(2026, 1, 10, 12, 0, 0);
        $this->recordCredit('300.0000', $to->copy()->addDay());

        $statement = $this->service->generate(
            wallet: $this->wallet,
            from: $to->copy()->subDay(),
            to: $to,
        );

        $this->assertCount(0, $statement['period_entries']);
        $this->assertSame('0.0000', $statement['opening_balance']);
    }

    // =========================================================================
    // Schema
    // =========================================================================

    public function test_statement_has_expected_top_level_keys(): void
    {
        $statement = $this->service->generate(
            wallet: $this->wallet,
            from: Carbon::create(2026, 1, 1),
            to: Carbon::create(2026, 2, 1),
        );

        foreach (
            [
                'wallet_id',
                'currency',
                'period',
                'opening_balance',
                'period_entries',
                'debit_total',
                'credit_total',
                'closing_balance',
                'generated_at',
                'generated_by',
                'provenance',
            ] as $key
        ) {
            $this->assertArrayHasKey($key, $statement);
        }

        $this->assertArrayHasKey('from', $statement['period']);
        $this->assertArrayHasKey('to', $statement['period']);
    }

    public function test_period_entry_has_expected_fields(): void
    {
        $this->recordDebit('300.0000', Carbon::create(2026, 1, 15, 9));

        $statement = $this->service->generate(
            wallet: $this->wallet,
            from: Carbon::create(2026, 1, 1),
            to: Carbon::create(2026, 2, 1),
        );

        $entry = $statement['period_entries'][0];

        foreach (
            [
                'id',
                'occurred_at',
                'entry_type',
                'component',
                'signed_quantity',
                'debit',
                'credit',
                'balance_after',
                'reference_type',
                'reference_id',
                'description',
            ] as $key
        ) {
            $this->assertArrayHasKey($key, $entry);
        }

        $this->assertSame('obligation_recorded', $entry['entry_type']);
        $this->assertSame('principal', $entry['component']);
        $this->assertSame('Obligation', $entry['reference_type']);
    }

    public function test_provenance_block_has_expected_fields(): void
    {
        $statement = $this->service->generate(
            wallet: $this->wallet,
            from: Carbon::create(2026, 1, 1),
            to: Carbon::create(2026, 2, 1),
        );

        $provenance = $statement['provenance'];

        foreach (
            [
                'wallet_id',
                'period_from',
                'period_to',
                'ledger_max_entry_id',
                'generator',
                'generator_version',
            ] as $key
        ) {
            $this->assertArrayHasKey($key, $provenance);
        }

        $this->assertSame((int) $this->wallet->id, $provenance['wallet_id']);
        $this->assertSame(StatementService::class, $provenance['generator']);
        $this->assertSame('1.0.0', $provenance['generator_version']);
    }

    public function test_provenance_ledger_max_entry_id_reflects_maximum(): void
    {
        $this->recordCredit('100.0000', Carbon::create(2026, 1, 5, 9));
        $this->recordCredit('200.0000', Carbon::create(2026, 1, 6, 9));

        $maxId = (int) WalletEntry::query()
            ->where('wallet_id', $this->wallet->id)
            ->max('id');

        $statement = $this->service->generate(
            wallet: $this->wallet,
            from: Carbon::create(2026, 1, 1),
            to: Carbon::create(2026, 2, 1),
        );

        $this->assertSame($maxId, $statement['provenance']['ledger_max_entry_id']);
    }

    // =========================================================================
    // Validation
    // =========================================================================

    public function test_generate_rejects_unpersisted_wallet(): void
    {
        $ghost = new Wallet();

        $this->expectException(ValidationException::class);

        $this->service->generate(
            wallet: $ghost,
            from: Carbon::create(2026, 1, 1),
            to: Carbon::create(2026, 2, 1),
        );
    }

	public function test_generate_rejects_malformed_wallet_currency(): void
	{
		// A distinct shareholder for the malformed wallet — the composite
		// UNIQUE on (tenant_id, shareholder_id) forbids a second wallet for
		// the shareholder the setUp() fixture already used. The malformed
		// currency is the point of the test; the shareholder choice is
		// incidental and only has to differ from the setUp() one.
		$otherShareholder = $this->createShareholder($this->tenant);

		$malformedWallet = Wallet::create([
			'tenant_id'        => $this->tenant->id,
			'shareholder_id'   => $otherShareholder->id,
			'currency'         => 'XX',
			'current_balance'  => '0.0000',
			'is_active'        => true,
			'opened_at'        => Carbon::now(),
		]);

		$this->expectException(ValidationException::class);

		$this->service->generate(
			wallet: $malformedWallet,
			from: Carbon::create(2026, 1, 1),
			to: Carbon::create(2026, 2, 1),
		);
	}

    public function test_generate_rejects_same_instant_period(): void
    {
        $moment = Carbon::create(2026, 1, 15, 12, 0, 0);

        $this->expectException(ValidationException::class);

        $this->service->generate(
            wallet: $this->wallet,
            from: $moment,
            to: $moment,
        );
    }

    public function test_generate_rejects_inverted_period(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->generate(
            wallet: $this->wallet,
            from: Carbon::create(2026, 2, 1),
            to: Carbon::create(2026, 1, 1),
        );
    }

    // =========================================================================
    // Read-only guarantees
    // =========================================================================

    public function test_generate_does_not_write_any_rows(): void
    {
        $this->recordCredit('500.0000', Carbon::create(2026, 1, 5, 9));

        $walletCount     = Wallet::query()->count();
        $entryCount      = WalletEntry::query()->count();
        $obligationCount = Obligation::query()->count();
        $paymentCount    = Payment::query()->count();

        $this->service->generate(
            wallet: $this->wallet,
            from: Carbon::create(2026, 1, 1),
            to: Carbon::create(2026, 2, 1),
        );

        $this->assertSame($walletCount, Wallet::query()->count());
        $this->assertSame($entryCount, WalletEntry::query()->count());
        $this->assertSame($obligationCount, Obligation::query()->count());
        $this->assertSame($paymentCount, Payment::query()->count());
    }

    public function test_generate_does_not_change_wallet_balance(): void
    {
        $this->recordCredit('500.0000', Carbon::create(2026, 1, 5, 9));

        $this->wallet->refresh();
        $before = (string) $this->wallet->current_balance;

        $this->service->generate(
            wallet: $this->wallet,
            from: Carbon::create(2026, 1, 1),
            to: Carbon::create(2026, 2, 1),
        );

        $this->wallet->refresh();
        $this->assertSame($before, (string) $this->wallet->current_balance);
    }

    // =========================================================================
    // Metadata
    // =========================================================================

    public function test_generated_at_is_iso8601(): void
    {
        $statement = $this->service->generate(
            wallet: $this->wallet,
            from: Carbon::create(2026, 1, 1),
            to: Carbon::create(2026, 2, 1),
        );

        $parsed = Carbon::parse($statement['generated_at']);
        $this->assertNotNull($parsed);
        $this->assertIsString($statement['generated_at']);
    }

    public function test_generated_by_is_null_when_not_supplied(): void
    {
        $statement = $this->service->generate(
            wallet: $this->wallet,
            from: Carbon::create(2026, 1, 1),
            to: Carbon::create(2026, 2, 1),
        );

        $this->assertNull($statement['generated_by']);
    }

    public function test_generated_by_carries_value_when_supplied(): void
    {
        $statement = $this->service->generate(
            wallet: $this->wallet,
            from: Carbon::create(2026, 1, 1),
            to: Carbon::create(2026, 2, 1),
            generatedBy: 42,
        );

        $this->assertSame(42, $statement['generated_by']);
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    /**
     * Create a tenant.
     *
     * Named `makeTenant()` — not `createTenant()` — to avoid a signature
     * collision with the protected method declared on the base class.
     */
    private function makeTenant(string $slug, string $nameAm): Tenant
    {
        return Tenant::create([
            'slug'      => $slug,
            'name_am'   => $nameAm,
            'name_en'   => ucfirst(str_replace('-', ' ', $slug)),
            'domain'    => $slug . '.example.test',
            'is_active' => true,
        ]);
    }

    private function createShareholder(Tenant $tenant): Shareholder
    {
        return Shareholder::create([
            'tenant_id'        => $tenant->id,
            'shareholder_type' => 'individual',
            'first_name_am'    => 'አበበ',
            'father_name_am'   => 'ከበደ',
            'is_active'        => true,
        ]);
    }

    private function openWallet(): Wallet
    {
        return $this->walletService->open(
            tenantId: (int) $this->tenant->id,
            shareholder: $this->shareholder,
            currency: 'ETB',
        );
    }

    private function recordDebit(string $amount, Carbon $occurredAt): void
    {
        $this->obligationService->record(
            wallet: $this->wallet,
            component: ObligationComponent::Principal,
            amount: $amount,
            occurredAt: $occurredAt,
        );
    }

    private function recordCredit(string $amount, Carbon $receivedAt): void
    {
        $this->paymentService->record(
            wallet: $this->wallet,
            amount: $amount,
            receivedAt: $receivedAt,
        );
    }
}