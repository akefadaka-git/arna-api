<?php
declare(strict_types=1);
return [
    'enabled' => (bool) env('ARNA_PEOPLE_ENABLED', true),
    'directory' => ['default_per_page' => (int) env('ARNA_PEOPLE_DEFAULT_PER_PAGE', 25), 'max_per_page' => (int) env('ARNA_PEOPLE_MAX_PER_PAGE', 100)],
    'contacts' => ['max_per_person' => (int) env('ARNA_PEOPLE_MAX_CONTACTS', 20), 'verification_required_for_trust' => true],
    'relationships' => ['max_per_person' => (int) env('ARNA_PEOPLE_MAX_RELATIONSHIPS', 100), 'relationship_grants_authority' => false],
    'participations' => ['max_per_person' => (int) env('ARNA_PEOPLE_MAX_PARTICIPATIONS', 200), 'participation_grants_authority' => false],
];
