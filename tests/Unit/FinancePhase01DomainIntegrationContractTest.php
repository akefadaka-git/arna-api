<?php

declare(strict_types=1);

namespace Tests\Unit;

use MaaleArna\Arna\Core\Domains\Documents\Registries\DocumentTypeRegistry;
use MaaleArna\Arna\Core\Domains\Finance\Database\Seeders\FinancePermissionCatalogue;
use MaaleArna\Arna\Core\Domains\Finance\Database\Seeders\FinanceRolePermissionGrants;
use MaaleArna\Arna\Core\Domains\Finance\Services\FinanceDocumentLinkService;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FinancePhase01DomainIntegrationContractTest extends TestCase
{
    #[Test]
    public function finance_reuses_the_documents_registry_for_financial_evidence_types(): void
    {
        $registry = require base_path('arna_core/src/Domains/Finance/Config/phase01_domain_integrations.php');

        self::assertSame(DocumentTypeRegistry::CODES, $registry['documents']['supported_document_codes']);
        self::assertSame([], array_values(array_diff(array_keys($registry['documents']['uses']), DocumentTypeRegistry::CODES)));
        foreach ($registry['documents']['uses'] as $types) {
            self::assertNotEmpty($types);
        }
        self::assertSame('Documents', $registry['documents']['owner']);
        self::assertSame('Files', $registry['documents']['binary_owner']);
        self::assertContains('sales_invoice', $registry['documents']['uses']['INV']);
        self::assertContains('payment_receipt', $registry['documents']['uses']['RCT']);
        self::assertContains('purchase_order', $registry['documents']['uses']['PUR']);
        self::assertContains('share_certificate', $registry['documents']['uses']['CER']);
    }

    #[Test]
    public function finance_document_evidence_is_visible_only_to_the_finance_manager_role(): void
    {
        $permissions = FinancePermissionCatalogue::ROWS;
        $grants = FinanceRolePermissionGrants::MAP;

        self::assertContains('finance.document.view', array_column($permissions, 'slug'));
        self::assertContains('finance.document.view', $grants['finance_manager']);
        self::assertNotContains('finance.document.view', $grants['cashier']);
        self::assertNotContains('finance.document.view', $grants['board']);
    }

    #[Test]
    public function finance_document_link_service_rejects_unregistered_source_domains(): void
    {
        $this->expectException(ValidationException::class);

        app(FinanceDocumentLinkService::class)->linkIssuedDocument(
            1,
            1,
            1,
            'unregistered_domain',
            'sales_invoice',
            'source-1',
            'source_evidence',
        );
    }

    #[Test]
    public function finance_document_link_service_rejects_a_document_use_owned_by_another_domain(): void
    {
        $this->expectException(ValidationException::class);

        app(FinanceDocumentLinkService::class)->linkIssuedDocument(
            1,
            1,
            1,
            'finance',
            'sales_invoice',
            'source-1',
            'source_evidence',
        );
    }

    #[Test]
    public function every_source_domain_adapter_is_inactive_until_mapping_and_controls_are_approved(): void
    {
        $registry = require base_path('arna_core/src/Domains/Finance/Config/phase01_domain_integrations.php');

        self::assertNotEmpty($registry['domains']);
        foreach ($registry['domains'] as $domain => $definition) {
            self::assertNotSame('', $definition['record_owner'], $domain);
            self::assertSame('inactive', $definition['posting_adapter'], $domain);
        }

        self::assertContains('event_id', $registry['event_envelope']['required']);
        self::assertContains('idempotency_key', $registry['event_envelope']['required']);
        self::assertContains('document_ids', $registry['event_envelope']['evidence']);
    }
}
