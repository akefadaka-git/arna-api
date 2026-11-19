<?php

declare(strict_types=1);

namespace Tests\Unit\Documents;

use DateTimeImmutable;
use DateTimeZone;
use MaaleArna\MASES\Core\Domains\Documents\Services\EthiopianDocumentYearResolver;
use MaaleArna\MASES\Core\Localization\Calendar\EthiopianCalendar;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EthiopianDocumentYearResolverTest extends TestCase
{
    #[Test]
    public function it_uses_addis_ababa_local_date_at_the_ethiopian_year_boundary(): void
    {
        $resolver = new EthiopianDocumentYearResolver(new EthiopianCalendar());

        $beforeMidnightAddis = new DateTimeImmutable('2026-09-10T20:59:00+00:00');
        $atMidnightAddis = new DateTimeImmutable('2026-09-10T21:00:00+00:00');

        $this->assertSame(2018, $resolver->forInstant($beforeMidnightAddis));
        $this->assertSame(2019, $resolver->forInstant($atMidnightAddis));
    }

    #[Test]
    public function it_does_not_use_the_utc_calendar_year_as_the_document_year(): void
    {
        $resolver = new EthiopianDocumentYearResolver(new EthiopianCalendar());
        $instant = new DateTimeImmutable('2026-09-11T00:00:00', new DateTimeZone('Africa/Addis_Ababa'));

        $this->assertSame(2019, $resolver->forInstant($instant));
    }
}
