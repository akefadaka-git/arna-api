<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use MaaleArna\MASES\Core\Authorization\Models\Role;

/**
 * RoleFactory
 *
 * Factory for generating Role model instances for testing.
 *
 * @extends Factory<Role>
 */
final class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        return [
            'name_am'        => $this->faker->word(),
            'name_en'        => $this->faker->word(),
            'slug'           => $this->faker->unique()->slug(2),   // <-- REQUIRED
            'description_am' => $this->faker->sentence(),
            'description_en' => $this->faker->sentence(),
            'scope'          => 'platform',
            'tenant_id'      => null,
            'application_id' => null,
            'is_active'      => true,
        ];
    }

    public function tenantScope(): static
    {
        return $this->state(fn (array $attributes) => [
            'scope' => 'tenant',
        ]);
    }

    public function applicationScope(): static
    {
        return $this->state(fn (array $attributes) => [
            'scope' => 'application',
        ]);
    }
}