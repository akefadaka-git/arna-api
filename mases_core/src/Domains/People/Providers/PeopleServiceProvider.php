<?php
declare(strict_types=1);
namespace MaaleArna\MASES\Core\Domains\People\Providers;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use MaaleArna\MASES\Core\Domains\People\Contracts\PersonProfileRepositoryContract;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Domains\People\Policies\PersonPolicy;
use MaaleArna\MASES\Core\Domains\People\Repositories\EloquentPersonProfileRepository;
final class PeopleServiceProvider extends ServiceProvider {
 public function register(): void {
  $this->mergeConfigFrom(__DIR__.'/../Config/people.php','people');
  $this->app->bind(PersonProfileRepositoryContract::class,EloquentPersonProfileRepository::class);
 }
 public function boot(): void {
  $this->loadTranslationsFrom(__DIR__.'/../Resources/lang','people');
  Gate::policy(Person::class,PersonPolicy::class);
 }
}
