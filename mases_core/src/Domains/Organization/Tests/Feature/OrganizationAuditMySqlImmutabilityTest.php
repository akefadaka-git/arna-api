<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Organization\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OrganizationAuditMySqlImmutabilityTest extends TestCase
{
    #[Test]
    public function mysql_rejects_update_and_delete_of_organization_audit_events(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql'
            || getenv('ARNA_RUN_MYSQL_AUDIT_IMMUTABILITY') !== '1') {
            $this->markTestSkipped('Requires the dedicated MySQL audit immutability workflow step.');
        }

        $tenant = Tenant::factory()->create(['is_active' => true]);
        $eventId = DB::table('organization_audit_events')->insertGetId([
            'tenant_id' => $tenant->getKey(),
            'actor_user_id' => null,
            'subject_type' => 'unit',
            'subject_id' => 1,
            'action' => 'created',
            'reason' => 'Verify append-only database enforcement',
            'before_state' => null,
            'after_state' => json_encode(['id' => 1], JSON_THROW_ON_ERROR),
            'request_id' => 'org-audit-mysql-'.bin2hex(random_bytes(8)),
            'created_at' => now(),
        ]);

        try {
            DB::table('organization_audit_events')->where('id', $eventId)->update([
                'reason' => 'Tamper attempt',
            ]);
            $this->fail('MySQL must reject updates to Organization audit events.');
        } catch (QueryException) {
            $this->assertDatabaseHas('organization_audit_events', [
                'id' => $eventId,
                'reason' => 'Verify append-only database enforcement',
            ]);
        }

        try {
            DB::table('organization_audit_events')->where('id', $eventId)->delete();
            $this->fail('MySQL must reject deletes from Organization audit events.');
        } catch (QueryException) {
            $this->assertDatabaseHas('organization_audit_events', ['id' => $eventId]);
        }
    }
}
