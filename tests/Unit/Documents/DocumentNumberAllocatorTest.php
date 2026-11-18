<?php

declare(strict_types=1);

namespace Tests\Unit\Documents;

use LogicException;
use MaaleArna\MASES\Core\Domains\Documents\Services\DocumentNumberAllocator;
use MaaleArna\MASES\Core\Domains\Documents\Services\DocumentNumberFormatter;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class DocumentNumberAllocatorTest extends TestCase
{
    #[Test]
    public function it_refuses_to_allocate_outside_the_issuance_transaction(): void
    {
        $allocator = new DocumentNumberAllocator(new DocumentNumberFormatter());

        $this->expectException(LogicException::class);
        $allocator->allocate(1, 1, 1, '11', 'LET', 2019);
    }
}
