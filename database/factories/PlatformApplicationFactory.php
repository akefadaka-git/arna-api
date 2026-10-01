<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use MaaleArna\MASES\Core\Authorization\Models\PlatformApplication;

/**
 * PlatformApplicationFactory
 *
 * Factory for generating PlatformApplication model instances for testing.
 *
 * @extends Factory<PlatformApplication>
 */
final class PlatformApplicationFactory extends Factory
{
    protected $model = PlatformApplication::class;

    public function definition(): array
    {
        return [
            'name_am'        => $this->faker->company(),
            'name_en'        => $this->faker->company(),
            'slug'           => $this->faker->unique()->slug(2),
            'domain'         => $this->faker->unique()->domainName(),
            'description_am' => $this->faker->sentence(),
            'description_en' => $this->faker->sentence(),
            'is_active'      => true,
        ];
    }
}