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
}
