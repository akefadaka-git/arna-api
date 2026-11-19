# Bootstrap Credential Exposure — Remediation and Operations

## Security disposition

The legacy demo/bootstrap seeders previously assigned one reusable password to
administrator and shareholder accounts and printed that credential in seeder
output. Treat the credential as compromised. It must not be reused, checked
into source, emitted to logs, or placed in CI variables.

The demo/bootstrap seeders are restricted by `DemoSeederGuard` to local and
testing environments with explicit opt-in. They must never be enabled against
production data.

## Code controls in this remediation

- Newly created demo accounts receive independently generated random secrets.
  The secret is hashed immediately and is never displayed or returned.
- Ordinary reseeds preserve existing account passwords.
- Seeder summaries print account identifiers only and direct account holders
  to the verified-email password-reset flow.
- `auth:rotate-demo-seeder-credentials` explicitly rotates the two seeded
  administrator accounts and users linked through the `shareholders` table.
- The rotation command changes each password independently, rotates remember
  tokens, revokes all Sanctum personal-access tokens, and removes outstanding
  password-reset tokens for the affected accounts.
- The command prints only account and token counts. It does not reveal any
  credential or reset token.

## Operational runbook

Do not run the rotation command until all of the following are confirmed:

1. The target environment and database are positively identified.
2. A current, restorable database backup exists.
3. Password-reset email delivery is configured and tested for every affected
   account holder.
4. The affected account set has been reconciled against the authoritative
   shareholder register and administrator appointments.
5. A controlled notification plan is ready so affected account holders know
   to request a new reset link.
6. The operator has explicit production-change approval.

After approval, run:

```sh
php artisan auth:rotate-demo-seeder-credentials
```

The command prompts for confirmation. For a pre-approved non-interactive
operation, use `--force` only after the same preconditions have been met:

```sh
php artisan auth:rotate-demo-seeder-credentials --force
```

Then verify the affected accounts can complete password reset, the old
credentials no longer authenticate, and the affected Sanctum tokens are
invalid. Preserve the command output as evidence; it contains counts only.

## Scope boundary

This code change does not execute against any production database, alter
production accounts, send email, merge to `main`, or deploy. Those are separate
controlled operations. Public Git history and previously published Actions
logs may retain the historical exposure; removing the value from current source
does not erase those copies. The exposed credential must therefore remain
treated as compromised.
