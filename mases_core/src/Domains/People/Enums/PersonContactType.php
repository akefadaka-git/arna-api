<?php
declare(strict_types=1);
namespace MaaleArna\MASES\Core\Domains\People\Enums;
enum PersonContactType: string {
    case Email = 'email'; case Phone = 'phone'; case Other = 'other';
    /** @return list<string> */ public static function values(): array { return array_column(self::cases(), 'value'); }
}
