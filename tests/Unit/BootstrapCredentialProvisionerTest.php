<?php

declare(strict_types=1);

namespace Tests\Unit;

use Database\Seeders\Support\BootstrapCredentialProvisioner;
use Illuminate\Support\Facades\Hash;
use MaaleArna\MASES\Core\Auth\Models\User;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BootstrapCredentialProvisionerTest extends TestCase
{
    #[Test]
    public function it_assigns_independent_random_credentials_to_new_users(): void
    {
        $generated = [];

        Hash::shouldReceive('make')
            ->twice()
            ->with(Mockery::on(function (string $plain) use (&$generated): bool {
                $generated[] = $plain;

                return strlen($plain) === 64;
            }))
            ->andReturnUsing(static fn (string $plain): string => hash('sha256', $plain));

        $first = new User();
        $second = new User();

        BootstrapCredentialProvisioner::assignForNewUser($first);
        BootstrapCredentialProvisioner::assignForNewUser($second);

        self::assertCount(2, $generated);
        self::assertNotSame($generated[0], $generated[1]);
        self::assertNotSame($first->password, $second->password);
        self::assertSame(hash('sha256', $generated[0]), $first->password);
        self::assertSame(hash('sha256', $generated[1]), $second->password);
    }

    #[Test]
    public function it_preserves_existing_credentials_on_reseed(): void
    {
        Hash::shouldReceive('make')->never();

        $user = new User();
        $user->exists = true;
        $user->password = 'existing-password-hash';

        BootstrapCredentialProvisioner::assignForNewUser($user);

        self::assertSame('existing-password-hash', $user->password);
    }

    #[Test]
    public function seeders_do_not_reintroduce_a_shared_default_or_print_credentials(): void
    {
        $root = dirname(__DIR__, 2);
        $adminSeeder = file_get_contents($root . '/database/seeders/AdminUserSeeder.php');
        $shareholderSeeder = file_get_contents($root . '/database/seeders/ShareholderSeeder.php');
        $summarySeeder = file_get_contents($root . '/database/seeders/MasesCoreSeeder.php');

        self::assertIsString($adminSeeder);
        self::assertIsString($shareholderSeeder);
        self::assertIsString($summarySeeder);

        self::assertStringNotContainsString('DEFAULT_PASSWORD', $adminSeeder);
        self::assertStringNotContainsString('DEFAULT_PASSWORD', $shareholderSeeder);
        self::assertStringNotContainsString('DEFAULT_PASSWORD', $summarySeeder);
        self::assertDoesNotMatchRegularExpression(
            '/\$this->command->(?:line|warn|info)\([^;]*(?:password|credential)/i',
            $summarySeeder,
        );
        self::assertStringContainsString('BootstrapCredentialProvisioner::assignForNewUser($user)', $adminSeeder);
        self::assertStringContainsString('BootstrapCredentialProvisioner::assignForNewUser($user)', $shareholderSeeder);
    }
}
