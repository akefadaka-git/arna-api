<?php
declare(strict_types=1);
namespace MaaleArna\MASES\Core\Domains\People\Enums;
enum PersonParticipationType: string {
    case Membership = 'membership'; case Ministry = 'ministry'; case Volunteer = 'volunteer'; case Committee = 'committee'; case EventAttendance = 'event_attendance'; case Training = 'training'; case Qualification = 'qualification'; case Other = 'other';
    /** @return list<string> */ public static function values(): array { return array_column(self::cases(), 'value'); }
}
