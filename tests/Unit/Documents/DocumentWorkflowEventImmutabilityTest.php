<?php

declare(strict_types=1);

namespace Tests\Unit\Documents;

use LogicException;
use MaaleArna\MASES\Core\Domains\Documents\Models\DocumentWorkflowEvent;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DocumentWorkflowEventImmutabilityTest extends TestCase
{
    #[Test]
    public function workflow_events_cannot_be_updated_through_eloquent(): void
    {
        $event = new DocumentWorkflowEvent();
        $event->exists = true;
        $event->reason = 'attempted mutation';

        $this->expectException(LogicException::class);
        $event->save();
    }

    #[Test]
    public function workflow_events_cannot_be_deleted_through_eloquent(): void
    {
        $event = new DocumentWorkflowEvent();
        $event->exists = true;

        $this->expectException(LogicException::class);
        $event->delete();
    }
}
