<?php

declare(strict_types=1);

namespace Tests\Unit\Documents;

use LogicException;
use MaaleArna\MASES\Core\Domains\Documents\Services\DocumentNumberAllocator;
use MaaleArna\MASES\Core\Domains\Documents\Services\DocumentNumberFormatter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DocumentNumberAllocatorTransactionGuardTest extends TestCase
{
    #[Test]
    public function it_refuses_to_allocate_outside_the_issuance_transaction(): void
    {
        $this->assertSame(0, app('db')->connection()->transactionLevel());

        $this->expectException(LogicException::class);
        (new DocumentNumberAllocator(new DocumentNumberFormatter))
            ->allocate(1, 1, 1, 'LET', 2019);
    }
}
