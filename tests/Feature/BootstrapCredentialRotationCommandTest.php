<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use MaaleArna\MASES\Core\Auth\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BootstrapCredentialRotationCommandTest extends TestCase
{
    use RefreshDatabase;

    private const COMPROMISED_TEST_CREDENTIAL = 'p0-9-test-only-compromised-credential';

    protected function setUp(): void
    {
        parent::setUp();
        putenv('ARNA_COMPROMISED_BOOTSTRAP_PASSWORD=' . self::COMPROMISED_TEST_CREDENTIAL);
    }

    protected function tearDown(): void
    {
        putenv('ARNA_COMPROMISED_BOOTSTRAP_PASSWORD');
        parent::tearDown();
    }


    #[Test]
    public function it_fails_closed_when_the_incident_credential_is_not_supplied(): void
    {
        putenv('ARNA_COMPROMISED_BOOTSTRAP_PASSWORD');

        $exitCode = Artisan::call('arna:rotate-compromised-bootstrap-credentials');

        self::assertSame(1, $exitCode);
        self::assertStringContainsString(
            'ARNA_COMPROMISED_BOOTSTRAP_PASSWORD must be set',
            Artisan::output(),
        );
    }

    #[Test]
    public function dry_run_reports_matches_without_changing_passwords_or_tokens(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['password' => Hash::make(self::COMPROMISED_TEST_CREDENTIAL)])->save();
        $token = $user->createToken('p0-9-dry-run')->accessToken;

        $exitCode = Artisan::call('arna:rotate-compromised-bootstrap-credentials');

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('1 account(s) match', Artisan::output());
        self::assertTrue(Hash::check(self::COMPROMISED_TEST_CREDENTIAL, $user->fresh()->password));
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->id]);
    }

    #[Test]
    public function apply_fails_closed_for_non_database_sessions_without_mutating_accounts_or_tokens(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['password' => Hash::make(self::COMPROMISED_TEST_CREDENTIAL)])->save();
        $token = $user->createToken('p0-9-non-database-session')->accessToken;

        config(['session.driver' => 'array']);

        $exitCode = Artisan::call('arna:rotate-compromised-bootstrap-credentials', ['--apply' => true]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('session driver [array] is not database-backed', Artisan::output());
        self::assertTrue(Hash::check(self::COMPROMISED_TEST_CREDENTIAL, $user->fresh()->password));
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->id]);
    }

    #[Test]
    public function apply_rotates_matching_passwords_and_revokes_only_their_api_tokens(): void
    {
        $affected = User::factory()->create();
        $oldRememberToken = $affected->remember_token;
        $affected->forceFill(['password' => Hash::make(self::COMPROMISED_TEST_CREDENTIAL)])->save();
        $affectedToken = $affected->createToken('p0-9-affected')->accessToken;

        config([
            'session.driver' => 'database',
            'session.table' => 'p0_9_sessions',
            'session.connection' => config('database.default'),
        ]);
        Schema::create('p0_9_sessions', function ($table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
        DB::table('p0_9_sessions')->insert([
            [
                'id' => 'p0-9-affected-session',
                'user_id' => $affected->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'P0.9 test',
                'payload' => 'affected-session-payload',
                'last_activity' => time(),
            ],
        ]);

        $unaffected = User::factory()->create();
        DB::table('p0_9_sessions')->insert([
            [
                'id' => 'p0-9-unaffected-session',
                'user_id' => $unaffected->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'P0.9 test',
                'payload' => 'unaffected-session-payload',
                'last_activity' => time(),
            ],
        ]);
        $unaffectedPassword = 'unaffected-user-test-password';
        $unaffected->forceFill(['password' => Hash::make($unaffectedPassword)])->save();
        $unaffectedToken = $unaffected->createToken('p0-9-unaffected')->accessToken;

        $exitCode = Artisan::call('arna:rotate-compromised-bootstrap-credentials', ['--apply' => true]);

        self::assertSame(0, $exitCode);
        self::assertFalse(Hash::check(self::COMPROMISED_TEST_CREDENTIAL, $affected->fresh()->password));
        self::assertNotSame($oldRememberToken, $affected->fresh()->remember_token);
        self::assertTrue(Hash::check($unaffectedPassword, $unaffected->fresh()->password));
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $affectedToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $unaffectedToken->id]);
        $this->assertDatabaseMissing('p0_9_sessions', ['id' => 'p0-9-affected-session']);
        $this->assertDatabaseHas('p0_9_sessions', ['id' => 'p0-9-unaffected-session']);
    }
}
