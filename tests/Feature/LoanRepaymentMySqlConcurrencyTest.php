<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Domains\Loan\Enums\LoanStatus;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanInstallment;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanProduct;
use MaaleArna\MASES\Core\Domains\Loan\Services\LoanRepaymentService;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Domains\Wallet\Enums\ObligationComponent;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Obligation;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Payment;
use MaaleArna\MASES\Core\Domains\Wallet\Models\PaymentAllocation;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;
use MaaleArna\MASES\Core\Policy\Models\VersionedPolicy;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use RuntimeException;
use Throwable;
use Tests\TestCase;

/**
 * Real MySQL concurrency regression for the loan repayment transaction.
 *
 * Fixtures are committed before workers start. Each worker is an independent
 * OS process with a purged Laravel DB connection, so the test exercises real
 * InnoDB transactions and row locks rather than SQLite or a shared PDO handle.
 */
final class LoanRepaymentMySqlConcurrencyTest extends TestCase
{
    public function test_distinct_concurrent_repayments_serialize_and_preserve_financial_totals(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql'
            || ! function_exists('pcntl_fork')
            || getenv('ARNA_RUN_MYSQL_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Requires the dedicated MySQL concurrency workflow and pcntl.');
        }

        $suffix = bin2hex(random_bytes(5));
        $tenant = Tenant::query()->create([
            'slug' => 'loan-repay-concurrency-'.$suffix,
            'name_am' => 'የብድር ሙከራ',
            'name_en' => 'Loan Repayment Concurrency',
            'domain' => $suffix.'.loan-concurrency.test',
            'is_active' => true,
        ]);
        $borrower = Shareholder::query()->create([
            'tenant_id' => $tenant->id,
            'shareholder_type' => 'individual',
            'first_name_am' => 'አበበ',
            'father_name_am' => 'ከበደ',
            'is_active' => true,
        ]);
        $wallet = Wallet::query()->create([
            'tenant_id' => $tenant->id,
            'shareholder_id' => $borrower->id,
            'currency' => 'ETB',
            'current_balance' => '0.0000',
            'is_active' => true,
            'opened_at' => now(),
        ]);
        $policy = VersionedPolicy::query()->create([
            'policy_type' => 'loan_product_rules',
            'payload' => [
                'interest' => ['annual_rate' => '0.12'],
                'term' => ['principal_grace_months' => 0, 'interest_grace_months' => 0],
            ],
        ]);
        $product = LoanProduct::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'CONC-'.$suffix,
            'name_am' => 'የሙከራ ምርት',
            'name_en' => 'Concurrency Product',
            'currency' => 'ETB',
            'min_principal' => '1000.0000',
            'max_principal' => '100000.0000',
            'min_term_months' => 2,
            'max_term_months' => 24,
            'default_installment_frequency' => 'monthly',
            'is_active' => true,
            'product_rules_policy_version_id' => $policy->id,
        ]);
        $loan = new Loan();
        $loan->tenant_id = $tenant->id;
        $loan->borrower_shareholder_id = $borrower->id;
        $loan->loan_product_id = $product->id;
        $loan->principal_amount = '10000.0000';
        $loan->term_months = 2;
        $loan->status = LoanStatus::Active;
        $loan->application_date = now();
        $loan->decision_date = now();
        $loan->disbursement_date = now()->subMonth();
        $loan->currency = 'ETB';
        $loan->settled_amount = '0.0000';
        $loan->outstanding_principal = '10000.0000';
        $loan->outstanding_interest = '0.0000';
        $loan->outstanding_penalty = '0.0000';
        $loan->total_scheduled_interest = '0.0000';
        $loan->total_scheduled_repayment = '10000.0000';
        $loan->monthly_installment_amount = '0.0000';
        $loan->maturity_date = now()->addMonths(2);
        $loan->frozen_policy_version_id = $policy->id;
        $loan->save();

        foreach ([1, 2] as $number) {
            LoanInstallment::query()->create([
                'loan_id' => $loan->id,
                'installment_number' => $number,
                'due_date' => now()->addMonths($number)->toDateString(),
                'expected_principal' => '5000.0000',
                'expected_interest' => '0.0000',
                'expected_total' => '5000.0000',
            ]);
        }
        $obligation = Obligation::query()->create([
            'wallet_id' => $wallet->id,
            'component' => ObligationComponent::Principal->value,
            'currency' => 'ETB',
            'original_amount' => '10000.0000',
            'outstanding_amount' => '10000.0000',
            'reference_type' => 'Loan',
            'reference_id' => (string) $loan->id,
            'occurred_at' => now(),
        ]);

        $releasePath = tempnam(sys_get_temp_dir(), 'arna-loan-release-');
        $readyPaths = [
            tempnam(sys_get_temp_dir(), 'arna-loan-ready-'),
            tempnam(sys_get_temp_dir(), 'arna-loan-ready-'),
        ];
        $resultPaths = [
            tempnam(sys_get_temp_dir(), 'arna-loan-result-'),
            tempnam(sys_get_temp_dir(), 'arna-loan-result-'),
        ];
        @unlink($releasePath);
        foreach (array_merge($readyPaths, $resultPaths) as $path) {
            @unlink($path);
        }

        try {
            // Do not let child processes inherit a live parent PDO connection.
            DB::disconnect();
            $children = [];

            foreach ([0, 1] as $index) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Unable to fork a loan repayment worker.');
                }

                if ($pid === 0) {
                    try {
                        DB::purge();
                        file_put_contents($readyPaths[$index], 'ready');
                        $deadline = microtime(true) + 20;
                        while (! is_file($releasePath) && microtime(true) < $deadline) {
                            usleep(10000);
                        }
                        if (! is_file($releasePath)) {
                            throw new RuntimeException('Timed out waiting for concurrent worker release.');
                        }

                        $freshLoan = Loan::query()->findOrFail((int) $loan->id);
                        $amount = '500.0000';
                        $updated = app(LoanRepaymentService::class)->record(
                            $freshLoan,
                            $amount,
                            [['obligation_id' => (int) $obligation->id, 'amount' => $amount]],
                            'mysql-concurrency-'.$suffix.'-'.$index,
                            static fn (Loan $result): array => [
                                'success' => true,
                                'loan_id' => (int) $result->id,
                                'settled_amount' => (string) $result->settled_amount,
                            ],
                        );
                        file_put_contents($resultPaths[$index], 'success:'.(string) $updated->settled_amount);
                        exit(0);
                    } catch (Throwable $exception) {
                        file_put_contents(
                            $resultPaths[$index],
                            'error:'.$exception::class.':'.$exception->getMessage(),
                        );
                        exit(1);
                    }
                }

                $children[] = $pid;
            }

            // Both independently connected processes wait at the same barrier.
            $readyDeadline = microtime(true) + 20;
            while ((! is_file($readyPaths[0]) || ! is_file($readyPaths[1]))
                && microtime(true) < $readyDeadline) {
                usleep(10000);
            }
            $this->assertFileExists($readyPaths[0], 'First MySQL worker did not become ready.');
            $this->assertFileExists($readyPaths[1], 'Second MySQL worker did not become ready.');
            file_put_contents($releasePath, 'go');

            $exitCodes = [];
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $exitCodes[] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 99;
            }
            $outcomes = array_map(
                static fn (string $path): string => is_file($path) ? trim((string) file_get_contents($path)) : 'missing-result',
                $resultPaths,
            );

            $this->assertSame([0, 0], $exitCodes, 'Both independent repayment transactions must commit: '.json_encode($outcomes));
            $this->assertCount(2, array_filter($outcomes, static fn (string $value): bool => str_starts_with($value, 'success:')));

            DB::purge();
            $freshLoan = Loan::query()->findOrFail((int) $loan->id);
            $freshObligation = Obligation::query()->findOrFail((int) $obligation->id);

            $this->assertSame('1000.0000', (string) $freshLoan->settled_amount);
            $this->assertSame('9000.0000', (string) $freshObligation->outstanding_amount);
            $this->assertSame(2, Payment::query()->where('wallet_id', $wallet->id)->count());
            $this->assertSame(2, PaymentAllocation::query()->where('wallet_id', $wallet->id)->count());
            $this->assertSame(
                2,
                DB::table('loan_repayment_idempotencies')
                    ->where('tenant_id', $tenant->id)
                    ->where('loan_id', $loan->id)
                    ->count(),
            );
            $this->assertSame(
                0,
                bccomp((string) Payment::query()->where('wallet_id', $wallet->id)->sum('amount'), '1000.0000', 4),
            );
            $this->assertSame(
                0,
                bccomp(
                    (string) PaymentAllocation::query()->where('wallet_id', $wallet->id)->sum('amount'),
                    '1000.0000',
                    4,
                ),
            );
        } finally {
            DB::purge();
            @unlink($releasePath);
            foreach (array_merge($readyPaths, $resultPaths) as $path) {
                @unlink($path);
            }
        }
    }
}
