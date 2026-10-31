# Finance Domain

Canonical home for Finance and Accounting implementation, domain-owned
persistence, route definitions, configuration, tests, and technical
documentation.

## Layout

- `Config/`: domain configuration, Finance application metadata and canonical chart-of-accounts register.
- `Contracts/`, `Enums/`, `Events/`, `Listeners/`: domain contracts and lifecycle.
- `Database/Migrations/`: Finance migrations; original timestamped filenames are
  retained so migration-history identifiers remain stable.
- `Database/Seeders/`: Finance-owned permission, role and role-grant catalogues,
  plus explicit Finance seeders; the chart-of-accounts seeder is not automatically invoked.
- Platform application, role, permission and role-grant registries compose Finance
  entries from these domain-owned sources; they do not duplicate Finance definitions.
- `Documentation/`: accounting contract, CoA specification, and consolidation/FX boundaries.
- `Http/`, `Models/`, `Policies/`, `Repositories/`, `Services/`,
  `Support/`: application layer and domain logic.
- `Routes/api.php`: Finance API routes mounted by `FinanceServiceProvider`.
- `Tests/Feature/`, `Tests/Unit/`: Finance tests discovered by PHPUnit's Domain suite.

## Guardrails

- Tenant and legal-entity boundaries are mandatory.
- Consolidation mappings and manual FX rates require explicit maker-checker
  approval before use.
- Consolidation remains read-only and unadjusted until governed elimination,
  translation, tax, and statutory rules are approved.
- Tax defaults and opening balances are not activated or seeded by this
  restructuring.
