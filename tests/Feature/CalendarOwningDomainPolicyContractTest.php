<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Gate;
use MaaleArna\MASES\Core\Domains\Finance\Models\AccountingPeriod;
use MaaleArna\MASES\Core\Domains\Finance\Models\JournalEntry;
use MaaleArna\MASES\Core\Domains\Finance\Models\LedgerAccount;
use MaaleArna\MASES\Core\Domains\Finance\Models\LegalEntity;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceAppointment;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceMembership;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernancePosition;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceTerm;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanCollateral;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanProduct;
use MaaleArna\MASES\Core\Domains\Organization\Policies\OrganizationAssignmentPolicy;
use MaaleArna\MASES\Core\Domains\Organization\Policies\OrganizationUnitPolicy;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Domains\People\Policies\PersonPolicy;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareCertificate;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareClass;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareHolding;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Obligation;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Payment;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;
use Tests\TestCase;

final class CalendarOwningDomainPolicyContractTest extends TestCase
{
    public function test_gate_backed_calendar_link_types_have_registered_view_policies(): void
    {
        $models = [
            LegalEntity::class,
            LedgerAccount::class,
            AccountingPeriod::class,
            JournalEntry::class,
            GovernanceAppointment::class,
            GovernanceBody::class,
            GovernanceMembership::class,
            GovernancePosition::class,
            GovernanceTerm::class,
            Loan::class,
            LoanProduct::class,
            LoanCollateral::class,
            Shareholder::class,
            ShareHolding::class,
            ShareCertificate::class,
            ShareClass::class,
            Wallet::class,
            Obligation::class,
            Payment::class,
            Person::class,
        ];

        foreach ($models as $model) {
            $policy = Gate::getPolicyFor($model);
            $this->assertNotNull($policy, "No owning-domain Gate policy is registered for {$model}.");
            $this->assertTrue(method_exists($policy, 'view'), "The owning-domain policy for {$model} has no view ability.");
        }

        $peoplePolicy = Gate::getPolicyFor(Person::class);
        $this->assertInstanceOf(PersonPolicy::class, $peoplePolicy);
        $this->assertTrue(method_exists($peoplePolicy, 'viewAny'));
    }

    public function test_non_gate_owned_domains_keep_their_existing_direct_authorization_contracts(): void
    {
        $this->assertTrue(method_exists(app(OrganizationUnitPolicy::class), 'view'));
        $this->assertTrue(method_exists(app(OrganizationAssignmentPolicy::class), 'viewAny'));
    }
}
