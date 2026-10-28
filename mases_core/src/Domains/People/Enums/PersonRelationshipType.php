<?php
declare(strict_types=1);
namespace MaaleArna\MASES\Core\Domains\People\Enums;
enum PersonRelationshipType: string {
    case Parent = 'parent'; case Child = 'child'; case Guardian = 'guardian'; case Dependent = 'dependent'; case Spouse = 'spouse'; case Sibling = 'sibling'; case EmergencyContact = 'emergency_contact'; case HouseholdMember = 'household_member'; case Other = 'other';
    /** @return list<string> */ public static function values(): array { return array_column(self::cases(), 'value'); }
}
