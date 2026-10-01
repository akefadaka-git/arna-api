<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;

/**
 * TenantFactory
 *
 * Factory for generating Tenant model instances for testing.
 *
 * @extends Factory<Tenant>
 */
final class TenantFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Tenant>
     */
    protected $model = Tenant::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name_am'   => $this->faker->company(),
            'name_en'   => $this->faker->company(),
            'slug'      => $this->faker->unique()->slug(2),
            'domain'    => $this->faker->unique()->domainName(),
            'settings'  => [
                'timezone' => 'Africa/Addis_Ababa',
                'locale'   => 'am',
            ],
            'is_active' => true,
        ];
    }
}