<?php
declare(strict_types=1);
namespace Tests\Feature;
use MaaleArna\MASES\Core\Domains\People\Contracts\PersonProfileRepositoryContract;
use MaaleArna\MASES\Core\Domains\People\Enums\PersonContactType;
use MaaleArna\MASES\Core\Domains\People\Enums\PersonParticipationType;
use MaaleArna\MASES\Core\Domains\People\Enums\PersonRelationshipType;
use Tests\TestCase;
final class PeopleDomainStructureTest extends TestCase {
 public function test_people_vocabulary_values_are_stable_and_unique(): void {
  $this->assertSame(['email','phone','other'],PersonContactType::values());
  $this->assertContains('guardian',PersonRelationshipType::values());
  $this->assertContains('ministry',PersonParticipationType::values());
  $this->assertCount(count(PersonRelationshipType::values()),array_unique(PersonRelationshipType::values()));
  $this->assertCount(count(PersonParticipationType::values()),array_unique(PersonParticipationType::values()));
 }
 public function test_people_repository_contract_is_registered(): void {
  $this->assertInstanceOf(PersonProfileRepositoryContract::class,app(PersonProfileRepositoryContract::class));
 }
 public function test_people_domain_limits_are_bounded(): void {
  $this->assertGreaterThan(0,config('people.directory.default_per_page'));
  $this->assertLessThanOrEqual(100,config('people.directory.max_per_page'));
 }
}
