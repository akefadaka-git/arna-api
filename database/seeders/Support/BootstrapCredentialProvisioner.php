<?php

declare(strict_types=1);

namespace Database\Seeders\Support;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use MaaleArna\MASES\Core\Auth\Models\User;

/**
 * Assigns a one-time, per-account random credential to newly seeded users.
 *
 * The plaintext is never returned, persisted, or logged. Persisted User models
 * are left unchanged; a higher-level orchestrator may intentionally delete
 * and recreate seeded rows before this provisioner is called. Users establish
 * access through the normal password-reset flow.
 */
final class BootstrapCredentialProvisioner
{
    public static function assignForNewUser(User $user): void
    {
        if ($user->exists) {
            return;
        }

        $user->password = Hash::make(Str::random(64));
    }
}
