<?php

declare(strict_types=1);

namespace Tests\Feature;

use MaaleArna\MASES\Core\Auth\Models\User as CoreUser;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AuthProviderModelContractTest extends TestCase
{
    #[Test]
    public function eloquent_auth_and_password_reset_use_only_the_platform_user_model(): void
    {
        $this->assertSame(
            CoreUser::class,
            config('auth.providers.users.model'),
            'Authentication and password resets must resolve the canonical platform Core User model.'
        );

        $this->assertFalse(
            class_exists(\App\Models\User::class),
            'The legacy App\\Models\\User model must not be present or autoloadable.'
        );
    }
}
