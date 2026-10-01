<?php

declare(strict_types=1);

namespace Database\Factories\MaaleArna\MASES\Core\Authorization\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use MaaleArna\MASES\Core\Authorization\Models\Permission;

/**
 * PermissionFactory
 *
 * Factory for generating Permission model instances for testing.
 *
 * @extends Factory<Permission>
 */
final class PermissionFactory extends Factory
{
    protected $model = Permission::class;

    public function definition(): array
    {
        return [
            'name_am'        => $this->faker->word(),
            'name_en'        => $this->faker->word(),
            'slug'           => $this->faker->unique()->slug(3),
            'description_am' => $this->faker->sentence(),
            'description_en' => $this->faker->sentence(),
            'category'       => $this->faker->word(),
            'scope'          => 'global',
            'tenant_id'      => null,
            'application_id' => null,
            'is_active'      => true,
            'is_system'      => false,
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