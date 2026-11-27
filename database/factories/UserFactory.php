<?php

declare(strict_types=1);

/* Factory fields must match the bilingual users table; no legacy name column. */

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use MaaleArna\Arna\Core\Auth\Models\User;

/**
 * UserFactory
 *
 * Produces `MaaleArna\Arna\Core\Auth\Models\User` instances whose
 * shape matches the actual `users` table schema (six bilingual name
 * columns; no single `name` column).
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The model this factory produces.
     *
     * Bound explicitly so that both `App\Models\User::factory()` and
     * `MaaleArna\Arna\Core\Auth\Models\User::factory()` resolve to
     * the same instance type — the ARNA Core User.
     *
     * @var class-string<User>
     */
    protected $model = User::class;

    /**
     * The current password being used by the factory.
     *
     * Cached across the process so that repeated factory calls do not
     * recompute the bcrypt hash. The literal is `password`; tests
     * that authenticate against factory-created users use that value.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * Emits the three English name components; the Amharic components
     * are left null, and the User model's `getFullNameAttribute()`
     * accessor falls back to the English values when Amharic is
     * absent. `$user->name` therefore renders as
     * `<first> <father> <grandfather>` for every factory instance.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Bilingual Ethiopian name components.
            // Amharic is left null; the accessor prefers Amharic but
            // falls back to English when Amharic is absent.
            'first_name_en'       => fake()->firstName(),
            'first_name_am'       => null,
            'father_name_en'      => fake()->lastName(),
            'father_name_am'      => null,
            'grandfather_name_en' => fake()->lastName(),
            'grandfather_name_am' => null,

            // Authentication and contact.
            'email'               => fake()->unique()->safeEmail(),
            'email_verified_at'   => now(),
            'password'            => static::$password ??= Hash::make('password'),
            'remember_token'      => Str::random(10),

            // Preferences and lifecycle (matching the DB defaults).
            'locale'              => 'am',
            'is_active'           => true,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}