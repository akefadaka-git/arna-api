<?php

declare(strict_types=1);

namespace Tests\Unit\Documents;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MaaleArna\MASES\Core\Domains\Documents\Services\DocumentIdentityLookupService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DocumentIdentityLookupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('documents');
        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('file_id');
            $table->uuid('uuid');
            $table->string('canonical_number', 200);
            $table->string('document_type_code', 8);
            $table->char('document_type_number', 2)->nullable();
            $table->string('status', 16);
            $table->timestamp('issued_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('documents');
        parent::tearDown();
    }

    #[Test]
    public function it_returns_the_persisted_latest_identity_for_the_same_tenant_file(): void
    {
        DB::table('documents')->insert([
            [
                'tenant_id' => 7,
                'file_id' => 41,
                'uuid' => '10000000-0000-4000-8000-000000000001',
                'canonical_number' => 'ዓርና/12/01/0001/19',
                'document_type_code' => 'LET',
                'document_type_number' => '01',
                'status' => 'voided',
                'issued_at' => '2026-10-01 08:00:00',
            ],
            [
                'tenant_id' => 7,
                'file_id' => 41,
                'uuid' => '10000000-0000-4000-8000-000000000002',
                'canonical_number' => 'ዓርና/12/01/0002/19',
                'document_type_code' => 'LET',
                'document_type_number' => '01',
                'status' => 'issued',
                'issued_at' => '2026-10-02 08:00:00',
            ],
            [
                'tenant_id' => 8,
                'file_id' => 41,
                'uuid' => '10000000-0000-4000-8000-000000000003',
                'canonical_number' => 'ዓርና/99/11/9999/26',
                'document_type_code' => 'POL',
                'document_type_number' => '11',
                'status' => 'issued',
                'issued_at' => '2026-10-03 08:00:00',
            ],
        ]);

        $identity = (new DocumentIdentityLookupService())->findForFile(41, 7);

        $this->assertSame('ዓርና/12/01/0002/19', $identity['canonical_number']);
        $this->assertSame('LET', $identity['document_type_code']);
        $this->assertSame('01', $identity['document_type_number']);
        $this->assertSame('issued', $identity['status']);
        $this->assertSame('10000000-0000-4000-8000-000000000002', $identity['uuid']);
    }

    #[Test]
    public function it_batches_file_lookups_and_never_crosses_tenant_boundaries(): void
    {
        DB::table('documents')->insert([
            [
                'tenant_id' => 7,
                'file_id' => 41,
                'uuid' => '20000000-0000-4000-8000-000000000001',
                'canonical_number' => 'ዓርና/12/01/0001/19',
                'document_type_code' => 'LET',
                'document_type_number' => '01',
                'status' => 'issued',
                'issued_at' => '2026-10-01 08:00:00',
            ],
            [
                'tenant_id' => 8,
                'file_id' => 42,
                'uuid' => '20000000-0000-4000-8000-000000000002',
                'canonical_number' => 'ዓርና/99/11/0001/26',
                'document_type_code' => 'POL',
                'document_type_number' => '11',
                'status' => 'issued',
                'issued_at' => '2026-10-02 08:00:00',
            ],
        ]);

        $identities = (new DocumentIdentityLookupService())->findForFiles([41, 42], 7);

        $this->assertArrayHasKey('41', $identities);
        $this->assertArrayNotHasKey('42', $identities);
        $this->assertSame('ዓርና/12/01/0001/19', $identities['41']['canonical_number']);
    }
}
