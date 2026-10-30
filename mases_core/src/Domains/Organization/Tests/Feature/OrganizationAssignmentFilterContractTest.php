<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Organization\Tests\Feature;

use Illuminate\Support\Facades\Validator;
use MaaleArna\MASES\Core\Domains\Organization\Http\Requests\OrganizationRequestRules;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OrganizationAssignmentFilterContractTest extends TestCase
{
    #[Test]
    public function assignment_list_accepts_only_supported_filter_shapes(): void
    {
        $valid = Validator::make([
            'per_page' => 50,
            'status' => 'active',
            'person_id' => 27,
            'effective_on' => '2026-10-29',
        ], OrganizationRequestRules::listAssignments());

        $this->assertTrue($valid->passes(), 'A valid status, person, date, and page size should be accepted.');

        $invalidStatus = Validator::make([
            'status' => 'deleted',
        ], OrganizationRequestRules::listAssignments());
        $this->assertFalse($invalidStatus->passes(), 'Unknown lifecycle statuses must not be accepted.');

        $invalidDate = Validator::make([
            'effective_on' => 'not-a-date',
        ], OrganizationRequestRules::listAssignments());
        $this->assertFalse($invalidDate->passes(), 'Effective-date filters must be valid dates.');

        $invalidPageSize = Validator::make([
            'per_page' => 101,
        ], OrganizationRequestRules::listAssignments());
        $this->assertFalse($invalidPageSize->passes(), 'Pagination must remain bounded.');
    }

    #[Test]
    public function assignment_classifications_match_the_app_contract_and_reject_legacy_member_value(): void
    {
        foreach (['primary', 'acting', 'delegated', 'support'] as $type) {
            $validator = Validator::make([
                'person_id' => 1,
                'assignment_type' => $type,
                'starts_on' => '2026-10-30',
                'reason' => 'Validate assignment classification',
            ], OrganizationRequestRules::storeAssignment());

            $this->assertTrue($validator->passes(), "The {$type} assignment type must be accepted.");
        }

        $legacy = Validator::make([
            'person_id' => 1,
            'assignment_type' => 'member',
            'starts_on' => '2026-10-30',
            'reason' => 'Reject unlisted assignment classification',
        ], OrganizationRequestRules::storeAssignment());

        $this->assertFalse($legacy->passes(), 'Legacy or unsupported assignment classifications must be rejected.');
    }
}
