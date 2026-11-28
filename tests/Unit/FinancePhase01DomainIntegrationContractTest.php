<?php

declare(strict_types=1);

namespace Tests\\Unit;

use PHPUnit\\Framework\\Attributes\\Test;
use PHPUnit\\Framework\\TestCase;

final class FinancePhase01DomainIntegrationContractTest extends TestCase
{
    #[Test]
    public function finance_reuses_the_documents_registry_for_financial_evidence_types(): void
    {
        $registry = require base_path('arna_core/src/Domains/Finance/Config/phase01_domain_integrations.php');

        self::assertSame('Documents', $registry['documents']['owner']);
        self::assertSame('Files', $registry['documents']['binary_owner']);
        self::assertContains('sales_invoice', $registry['documents']['uses']['INV']);
        self::assertContains('payment_receipt', $registry['documents']['uses']['RCT']);
        self::assertContains('purchase_order', $registry['documents']['uses']['PUR']);
        self::assertContains('share_certificate', $registry['documents']['uses']['CER']);
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
