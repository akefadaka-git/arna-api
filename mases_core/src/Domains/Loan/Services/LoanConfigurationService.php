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

    public function listForTenant(int $tenantId, User $actor)
    {
        $this->authorize('loan.configuration.view', $actor, $tenantId);
        return LoanConfigurationSubmission::query()
            ->where('tenant_id', $tenantId)
            ->with(['creator', 'reviewer', 'publishedProduct', 'publishedPolicy'])
            ->orderByDesc('id')
            ->paginate(25);
    }

    public function updateDraft(int $tenantId, int $submissionId, User $actor, array $configuration): LoanConfigurationSubmission
    {
        $this->authorize('loan.configuration.manage', $actor, $tenantId);
        $this->validateConfiguration($configuration);

        return DB::transaction(function () use ($tenantId, $submissionId, $actor, $configuration): LoanConfigurationSubmission {
            $submission = $this->findForTenant($tenantId, $submissionId);
            if ($submission->status !== 'draft' || (int) $submission->created_by !== (int) $actor->getKey()) {
                throw ValidationException::withMessages(['submission' => ['Only the author may edit their own draft submission.']]);
            }
            $submission->forceFill(['configuration' => $configuration])->save();
            $this->audit($submission, $actor, 'draft_updated');
            return $submission->refresh();
        });
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
            $effectiveFrom = CarbonImmutable::parse($submission->configuration['effective_from']);
            if ($effectiveFrom->isFuture()) {
                throw ValidationException::withMessages(['effective_from' => ['A product cannot be activated before its approved policy effective date.']]);
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
                 bccomp((string) $product['min_principal'], (string) $product['max_principal'], 4) > 0 ||
                 bccomp((string) $product['min_principal'], '0', 4) <= 0)) {
                $errors['product.max_principal'][] = 'Principal bounds must be positive and maximum must be >= minimum.';
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
                if (!isset($policy[$section]) || !is_array($policy[$section])) {
                    $errors['financial_policy.' . $section][] = 'This policy section is required.';
                }
            }

            $interest = $policy['interest'] ?? [];
            $interestKeys = ['basis', 'rate_representation', 'computation_method', 'periodicity', 'day_count', 'rounding', 'precision', 'annual_rate'];
            foreach ($interestKeys as $key) {
                if (!array_key_exists($key, $interest)) {
                    $errors['financial_policy.interest.' . $key][] = 'This interest rule is required.';
                }
            }
            $fixedInterestRules = [
                'basis' => 'declining_balance',
                'rate_representation' => 'annual_percentage',
                'computation_method' => 'annuity',
                'periodicity' => 'monthly',
                'day_count' => 'actual_365',
                'rounding' => 'half_up',
                'precision' => 4,
            ];
            foreach ($fixedInterestRules as $key => $expected) {
                if (array_key_exists($key, $interest) && $interest[$key] !== $expected) {
                    $errors['financial_policy.interest.' . $key][] = 'This calculation method is not supported by the current Loan engine.';
                }
            }
            if (isset($interest['annual_rate']) &&
                (!is_string($interest['annual_rate']) || !preg_match('/^(0|[0-9]+)(\.[0-9]{1,8})?$/', $interest['annual_rate']))) {
                $errors['financial_policy.interest.annual_rate'][] = 'Annual rate must be a non-negative decimal string.';
            }

            $term = $policy['term'] ?? [];
            foreach (['min_months', 'max_months', 'frequency', 'principal_grace_months', 'interest_grace_months'] as $key) {
                if (!array_key_exists($key, $term)) {
                    $errors['financial_policy.term.' . $key][] = 'This term rule is required.';
                }
            }
            if (($term['frequency'] ?? null) !== 'monthly') {
                $errors['financial_policy.term.frequency'][] = 'The current Loan engine supports monthly repayment only.';
            }
            foreach (['min_months', 'max_months', 'principal_grace_months', 'interest_grace_months'] as $key) {
                if (isset($term[$key]) && (!is_int($term[$key]) || $term[$key] < 0)) {
                    $errors['financial_policy.term.' . $key][] = 'This value must be a non-negative integer.';
                }
            }
            if (isset($term['min_months'], $term['max_months']) &&
                ($term['min_months'] < 1 || $term['max_months'] < $term['min_months'])) {
                $errors['financial_policy.term.max_months'][] = 'Maximum term must be >= minimum term and at least one month.';
            }
            if (is_array($product) && isset($term['min_months'], $term['max_months'], $product['min_term_months'], $product['max_term_months']) &&
                ($term['min_months'] !== $product['min_term_months'] || $term['max_months'] !== $product['max_term_months'])) {
                $errors['financial_policy.term'][] = 'Policy term bounds must match the product catalogue bounds.';
            }

            $arrears = $policy['arrears'] ?? [];
            if (!is_bool($arrears['enabled'] ?? null)) {
                $errors['financial_policy.arrears.enabled'][] = 'Arrears enablement must be explicitly true or false.';
            }
            if (($arrears['enabled'] ?? false) === true) {
                foreach (['penalty_rate', 'grace_days', 'basis'] as $key) {
                    if (!array_key_exists($key, $arrears)) {
                        $errors['financial_policy.arrears.' . $key][] = 'This enabled-arrears rule is required.';
                    }
                }
                if (isset($arrears['penalty_rate']) &&
                    (!is_string($arrears['penalty_rate']) || !preg_match('/^(0|[0-9]+)(\.[0-9]{1,8})?$/', $arrears['penalty_rate']))) {
                    $errors['financial_policy.arrears.penalty_rate'][] = 'Penalty rate must be a non-negative decimal string.';
                }
                if (isset($arrears['grace_days']) && (!is_int($arrears['grace_days']) || $arrears['grace_days'] < 0)) {
                    $errors['financial_policy.arrears.grace_days'][] = 'Grace days must be a non-negative integer.';
                }
                if (isset($arrears['basis']) && $arrears['basis'] !== 'overdue_installment') {
                    $errors['financial_policy.arrears.basis'][] = 'The current Loan engine supports overdue_installment basis only.';
                }
                if (isset($arrears['cap']) && $arrears['cap'] !== null &&
                    (!is_string($arrears['cap']) || !preg_match('/^(0|[0-9]+)(\.[0-9]{1,8})?$/', $arrears['cap']))) {
                    $errors['financial_policy.arrears.cap'][] = 'Penalty cap must be null or a non-negative decimal string.';
                }
            }

            $collateral = $policy['collateral'] ?? [];
            if (!is_bool($collateral['required'] ?? null)) {
                $errors['financial_policy.collateral.required'][] = 'Collateral requirement must be explicitly true or false.';
            }
            if (!isset($collateral['permitted_types']) || !is_array($collateral['permitted_types']) || !array_is_list($collateral['permitted_types'])) {
                $errors['financial_policy.collateral.permitted_types'][] = 'Permitted collateral types must be a list.';
            }
            if (($collateral['required'] ?? false) && empty($collateral['permitted_types'])) {
                $errors['financial_policy.collateral.permitted_types'][] = 'At least one collateral type is required for secured products.';
            }
            if (isset($collateral['coverage_ratio']) && $collateral['coverage_ratio'] !== null &&
                (!is_string($collateral['coverage_ratio']) || !preg_match('/^(0|[0-9]+)(\.[0-9]{1,8})?$/', $collateral['coverage_ratio']))) {
                $errors['financial_policy.collateral.coverage_ratio'][] = 'Coverage ratio must be null or a non-negative decimal string.';
            }

            $fees = $policy['fees'] ?? null;
            if (!is_array($fees) || !array_is_list($fees)) {
                $errors['financial_policy.fees'][] = 'Fees must be a list; use an empty list when no fees apply.';
            } else {
                foreach ($fees as $index => $fee) {
                    if (!is_array($fee) || empty($fee['code']) || empty($fee['amount']) || ($fee['component'] ?? null) !== 'fee' ||
                        !is_string($fee['amount']) || !preg_match('/^[0-9]+(\.[0-9]{1,8})?$/', $fee['amount'])) {
                        $errors['financial_policy.fees.' . $index][] = 'Each fee requires a code, non-negative decimal amount, and fee component.';
                    }
                }
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
