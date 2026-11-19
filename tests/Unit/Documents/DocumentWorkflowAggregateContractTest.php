<?php

declare(strict_types=1);

namespace Tests\Unit\Documents;

use MaaleArna\MASES\Core\Domains\Documents\Services\DocumentWorkflowAggregateService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class DocumentWorkflowAggregateContractTest extends TestCase
{
    #[Test]
    public function lifecycle_transitions_match_the_approved_documents_state_machine(): void
    {
        $constant = (new ReflectionClass(DocumentWorkflowAggregateService::class))
            ->getReflectionConstant('TRANSITIONS');
        self::assertNotFalse($constant);
        $transitions = $constant->getValue();

        self::assertSame(['draft'], $transitions['submit']['from']);
        self::assertSame('submitted', $transitions['submit']['to']);
        self::assertSame(['submitted'], $transitions['start_review']['from']);
        self::assertSame('in_review', $transitions['start_review']['to']);
        self::assertSame(['in_review'], $transitions['return']['from']);
        self::assertSame(['in_review'], $transitions['reject']['from']);
        self::assertSame(['in_review'], $transitions['approve']['from']);
        self::assertSame(['returned'], $transitions['return_to_draft']['from']);
        self::assertSame(['approved'], $transitions['begin_signing']['from']);
        self::assertSame(['signing'], $transitions['issue']['from']);
        self::assertSame(['submitted', 'in_review'], $transitions['withdraw']['from']);
        self::assertSame(['issued'], $transitions['revoke']['from']);
        self::assertSame(['issued'], $transitions['supersede']['from']);
    }

    #[Test]
    public function every_governed_transition_requires_an_explicit_governance_action(): void
    {
        $transitions = (new ReflectionClass(DocumentWorkflowAggregateService::class))
            ->getReflectionConstant('TRANSITIONS')->getValue();

        foreach ($transitions as $name => $transition) {
            if ($transition['governed']) {
                self::assertContains($transition['permission'], [
                    'documents.review',
                    'documents.return',
                    'documents.reject',
                    'documents.approve',
                    'documents.sign',
                    'documents.issue',
                    'documents.withdraw',
                    'documents.revoke',
                    'documents.supersede',
                ], $name);
            }
        }
    }

    #[Test]
    public function reasoned_transitions_require_a_reason(): void
    {
        $transitions = (new ReflectionClass(DocumentWorkflowAggregateService::class))
            ->getReflectionConstant('TRANSITIONS')->getValue();

        foreach (['return', 'reject', 'withdraw', 'revoke', 'supersede'] as $action) {
            self::assertTrue($transitions[$action]['reason'], $action);
        }
    }
}
