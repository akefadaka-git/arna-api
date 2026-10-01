<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — UserFactory repair (H-09b)
 * =============================================================================
 *
 * File:            database/factories/UserFactory.php  (AMEND)
 * Unit:            Test-infrastructure repair — align factory with the
 *                  actual `users` schema
 * Delivery type:   Amendment (full-file replacement)
 *
 * Before:
 *   The factory emitted a single `name` column plus `email`,
 *   `email_verified_at`, `password`, and `remember_token`.
 *
 *   The MASES `users` table has no `name` column. It was deliberately
 *   replaced by six bilingual name columns in
 *   `2026_09_07_000002_create_users_table.php`:
 *
 *       first_name_am / first_name_en
 *       father_name_am / father_name_en
 *       grandfather_name_am / grandfather_name_en
 *
 *   Every test that called `User::factory()->create()` (or `->make()`)
 *   failed at the insert with:
 *
 *       SQLSTATE[HY000]: General error: 1 table users has no column
 *       named name
 *
 *   The defect was masked historically. The duplicate-migration
 *   defect (H-09) caused `migrate:fresh` to abort before tests could
 *   run, so the factory's mismatch with the schema never surfaced.
 *   Once the duplicate migrations were removed (D-20), the schema
 *   built correctly and this defect became visible.
 *
 * After:
 *   The factory emits the three English name components
 *   (`first_name_en`, `father_name_en`, `grandfather_name_en`). The
 *   three Amharic components are left null; the User model's
 *   `getFullNameAttribute()` accessor falls back to the English
 *   values when Amharic is absent, so `$user->name` continues to
 *   render.
 *
 *   The factory is also explicitly bound to the MASES User model:
 *
 *       protected $model = \MaaleArna\MASES\Core\Auth\Models\User::class;
 *
 *   Before this binding, the factory's model was inferred by
 *   convention to `App\Models\User` — the post-incident skeleton,
 *   not the platform's actual User model. The skeleton has a
 *   different `$fillable`, and instantiating it in tests produced a
 *   class unrelated to the Core model that `AUTH_MODEL` resolves.
 *
 *   Two additional columns are now set explicitly, matching the DB
 *   defaults but stated in the factory for clarity:
 *
 *       'locale'    => 'am'
 *       'is_active' => true
 *
 *   `username`, `phone`, `address`, `default_tenant_id`, and the
 *   login-audit columns (`last_login_at`, `last_login_ip`,
 *   `password_changed_at`) remain unset. They are nullable, and
 *   tests that need them set them explicitly.
 *
 * =============================================================================
 * What does NOT change
 * =============================================================================
 *   · The public factory API: `User::factory()`, `->create([...])`,
 *     `->make([...])`, `->unverified()`, and the static password
 *     cache all preserve their contract.
 *
 *   · The `unverified()` state method.
 *
 *   · The password: still `Hash::make('password')`, still cached per
 *     process via `static::$password`. Tests that log in continue to
 *     use `'password'` as the literal.
 *
 *   · `fake()->unique()->safeEmail()` for email generation.
 *
 * =============================================================================
 * Scope of impact
 * =============================================================================
 *   · Repairs the ~118 tests that currently fail on the missing
 *     `name` column.
 *   · Does NOT change any production code.
 *   · Does NOT change any migration, model, controller, or service.
 *   · Does NOT change the C-09 CR's scope.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   H-09   — duplicate migrations (remediated under D-20)
 *   H-09b  — this defect (the factory mismatch H-09 masked)
 *   C-09   — tenant-context correction (unaffected)
 *   Migration — 2026_09_07_000002_create_users_table.php
 *   Model     — MaaleArna\MASES\Core\Auth\Models\User
 * =============================================================================
 */

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use MaaleArna\MASES\Core\Auth\Models\User;

/**
 * UserFactory
 *
 * Produces `MaaleArna\MASES\Core\Auth\Models\User` instances whose
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
     * `MaaleArna\MASES\Core\Auth\Models\User::factory()` resolve to
     * the same instance type — the MASES Core User.
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