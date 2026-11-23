# Production database bootstrap

## Scope

The default DatabaseSeeder runs ProductionCoreSeeder. This is the non-destructive bootstrap for the canonical ARNA core identity and authorization data.

It provisions, in dependency order:

1. The default tenant.
2. The seven registered platform applications.
3. The core permission catalogue and Governance election-committee permissions.
4. The twelve core roles and core role-to-permission grants.
5. Governance application, permissions, and role grants.
6. Shareholder, Wallet, and Loan permission catalogues and composite role grants.
7. Shareholder share classes.
8. The two bootstrap administrator accounts.
9. The 100-row shareholder register and its 100 user identities.
10. Tenant memberships and role assignments, including the eight board-member assignments.
11. Permanent shareholder numbers, reservations, legacy aliases, and the next-number sequence.
12. Post-write integrity checks for all 102 expected identities and 100 shareholder records.

The two entity shareholders are represented through representative_user_id; their direct user_id remains null. Individual shareholders use user_id.

## Fresh installation

After the required migrations have completed successfully, run:

    php artisan migrate:status
    php artisan db:seed --force

Expected core cohort:

- 2 administrator identities: super administrator and platform administrator.
- 100 shareholder identities.
- 100 shareholder-register rows.
- 100 shareholder role assignments at minimum.
- 8 of the 100 shareholders also hold the board role and their configured board offices.
- 100 permanent shareholder-number reservations and legacy aliases on a clean database.

The seeder generates unique random password hashes for newly created accounts. It never prints or supplies a shared password. Account holders must establish usable credentials through the approved verified-email recovery flow.

## Existing installation or rerun

ProductionCoreSeeder is designed to reconcile the canonical core cohort without clearing existing users, shareholders, memberships, role pivots, Sanctum tokens, number reservations, or legacy aliases.

Run:

    php artisan db:seed --force

The command is safe to rerun after a partial failure. It fails closed if the canonical cohort is incomplete, if shareholder identities are ambiguous, or if the permanent numbering reconciliation is incomplete.

## Destructive demo reset

MasesCoreSeeder remains a separate destructive reset flow. It clears the user graph and must only be used in local/testing with the explicit demo-seeding opt-in. Never enable demo seeding in production.

## Not included in the core bootstrap

ProductionDomainReferenceSeeder includes the deterministic Shareholder, Wallet, Loan, and Governance permission/role reference data plus the two Shareholder share classes. It does not create operational lending products or activate a loan policy. LoanProductsSeeder and LoanProductRulesPolicySeeder remain separate because they create active financial products and an effective approval policy. Finance chart-of-accounts remains separate because no legal-entity bootstrap seeder exists; it requires a configured active legal entity and Finance accounting-policy approval. The corporate-site baseline seeder also remains a separate optional operation and inserts draft pages only.

## Recovery

If the seeder stops with an exception, retain the database state and exception output. Do not run migrate:fresh, manually delete identity rows, or disable the demo guard to recover. Resolve the named prerequisite and rerun php artisan db:seed --force.
