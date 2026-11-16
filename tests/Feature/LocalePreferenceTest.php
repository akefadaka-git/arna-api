<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use MaaleArna\MASES\Core\Auth\Models\User;
use Tests\TestCase;

final class LocalePreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_update_their_locale_without_tenant_context(): void
    {
        $user = User::factory()->create(['locale' => 'am']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/locale', ['locale' => 'en'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.locale', 'en');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'locale' => 'en',
        ]);
    }

    public function test_unsupported_locale_is_rejected_without_changing_the_preference(): void
    {
        $user = User::factory()->create(['locale' => 'am']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/locale', ['locale' => 'fr'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('locale');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'locale' => 'am',
        ]);
    }

    public function test_locale_update_requires_authentication(): void
    {
        $this->postJson('/api/locale', ['locale' => 'en'])
            ->assertUnauthorized();
    }
}
