<?php

declare(strict_types=1);

namespace Tests\Feature;

use MaaleArna\MASES\Core\Auth\Models\User as CoreUser;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AuthProviderModelContractTest extends TestCase
{
    #[Test]
    public function default_eloquent_auth_provider_uses_the_platform_user_model(): void
    {
        $this->assertSame(
            CoreUser::class,
            config('auth.providers.users.model'),
            'The auth/password-reset provider must resolve the same platform User model used by ARNA authentication.'
        );
    }
}
