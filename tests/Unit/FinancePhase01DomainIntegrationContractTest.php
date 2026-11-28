<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FinancePhase01DomainIntegrationContractTest extends TestCase
{
    #[Test]
    public function finance_reuses_the_documents_registry_for_financial_evidence_types(): void
    {
        $registry = require base_path('arna_core/src/Domains/Finance/Config/phase01_domain_integrations.php');

        self::assertSame(DocumentTypeRegistry::CODES, $registry['documents']['supported_document_codes']);
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
        $permissions = \MaaleArna\Arna\Core\Domains\Finance\Database\Seeders\FinancePermissionCatalogue::ROWS;
        $grants = \MaaleArna\Arna\Core\Domains\Finance\Database\Seeders\FinanceRolePermissionGrants::MAP;

        self::assertContains('finance.document.view', array_column($permissions, 'slug'));
        self::assertContains('finance.document.view', $grants['finance_manager']);
        self::assertNotContains('finance.document.view', $grants['cashier']);
        self::assertNotContains('finance.document.view', $grants['board']);
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
