<?php
declare(strict_types=1);
namespace MaaleArna\MASES\Core\Domains\People\Database\Seeders;
use Illuminate\Database\Seeder;
/** People never seeds synthetic personal/contact/relationship data. */
final class PeopleDomainSeeder extends Seeder {
 public function run(): void {
  // Vocabulary is provided by backed enums and localized resources; no
  // personal reference rows are required or safe to fabricate here.
 }
}
