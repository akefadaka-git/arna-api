<?php
declare(strict_types=1);
namespace Tests\Feature;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
final class PeopleMigrationDiscoveryTest extends TestCase {
 #[Test]
 public function domain_migration_path_is_registered_with_laravel(): void {
  $expected=realpath(base_path('mases_core/src/Domains/People/Database/Migrations'));
  $paths=array_map(static fn(string $path): string=>realpath($path)?:$path,app('migrator')->paths());
  $this->assertNotFalse($expected);
  $this->assertContains($expected,$paths);
 }
 #[Test]
 public function people_schema_migrations_exist_in_the_domain_folder(): void {
  $directory=base_path('mases_core/src/Domains/People/Database/Migrations');
  foreach([
   '2026_10_27_000001_create_people_table.php',
   '2026_10_27_000002_add_person_id_to_people_domains.php',
   '2026_10_27_000003_create_person_tenant_accesses_table.php',
   '2026_10_28_000001_create_person_document_links_table.php',
   '2026_10_28_000001_enforce_active_person_domain_uniqueness.php',
   '2026_10_28_000002_create_person_contact_points_table.php',
   '2026_10_28_000003_create_person_relationships_table.php',
   '2026_10_28_000004_create_person_participations_table.php',
  ] as $migration) $this->assertFileExists($directory.DIRECTORY_SEPARATOR.$migration);
 }
}
