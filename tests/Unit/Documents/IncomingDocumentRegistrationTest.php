<?php

declare(strict_types=1);

namespace Tests\Unit\Documents;

use Database\Seeders\Data\PermissionCatalogue;
use MaaleArna\MASES\Core\Domains\Documents\Models\Document;
use MaaleArna\MASES\Core\Domains\Documents\Models\IncomingDocumentRegistration;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class IncomingDocumentRegistrationTest extends TestCase
{
    #[Test]
    public function incoming_registration_keeps_sender_reference_separate_from_its_internal_uuid(): void
    {
        $reference = '  EXT/ማጣቀሻ-0007  ';
        $registration = new IncomingDocumentRegistration([
            'registration_uuid' => '7b6d65a5-f7c7-4dd2-b2e6-16b0b1a18e2c',
            'sender_reference' => $reference,
        ]);

        $this->assertSame($reference, $registration->sender_reference);
        $this->assertSame('7b6d65a5-f7c7-4dd2-b2e6-16b0b1a18e2c', $registration->incoming_registration_id);
        $this->assertNotSame($registration->incoming_registration_id, $registration->sender_reference);
    }

    #[Test]
    public function document_workflow_permissions_are_present_in_the_seed_catalogue(): void
    {
        $slugs = array_column(PermissionCatalogue::ROWS, 'slug');

        foreach ([
            'documents.issue',
            'documents.view',
            'documents.void',
            'documents.revoke',
            'documents.receive',
            'documents.incoming.view',
        ] as $permission) {
            $this->assertContains($permission, $slugs);
        }
    }

    #[Test]
    public function document_display_number_is_the_persisted_canonical_value(): void
    {
        $canonical = 'ዓርና/12/01/0042/19';
        $document = new Document([
            'canonical_number' => $canonical,
            // Deliberately inconsistent source columns must not be used to rebuild it.
            'bb_code' => '99',
            'document_type_number' => '11',
            'sequence_number' => 9000,
            'ec_year' => 2026,
        ]);

        $this->assertSame($canonical, $document->display_number);
    }
}
