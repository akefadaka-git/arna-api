# AGE A3.8 — role-grant reconciliation and Owner decision register

Status: **READ-ONLY REVIEW COMPLETE — NO ROLE GRANTS CHANGED** (2026-11-15).

This report reconciles the current Governance permission catalogue with the Governance role seeder. It does not infer production assignments from seeders and does not authorize any role change.

## 1. Permission catalogue

Source: `mases_core/src/Domains/Governance/Database/Seeders/GovernancePermissionsSeeder.php`.

The current seeder contains **43 permission slugs**, including these nine election permissions:

- `governance.election.view`
- `governance.election.manage`
- `governance.election.committee.manage`
- `governance.election.register.manage`
- `governance.election.nomination.manage`
- `governance.election.ballot.issue`
- `governance.election.count.manage`
- `governance.election.complaint.manage`
- `governance.election.certify`

The file's explanatory inventory still states 36 tenant-scoped permissions / 37 total. That count is stale against the current 43 actual `slug` entries (42 tenant-scoped plus the application-scoped launcher permission). The role seeder's corresponding 36/37 count descriptions are stale as well.

This is documentation/count drift; the listed permission records are present in the seeder source. It should be corrected in a separate bounded documentation update, without changing permission semantics.

## 2. Current seeded election role matrix

Source: `mases_core/src/Domains/Governance/Database/Seeders/GovernanceRolesSeeder.php`, `ROLE_PERMISSIONS`.

| Existing role | Current election grants in the matrix |
|---|---|
| `super_admin` | `governance.election.view`, `governance.election.manage` |
| `platform_admin` | `governance.election.view`, `governance.election.manage` |
| `tenant_admin` | `governance.election.view` |
| `board` | `governance.election.view` |
| `president` | `governance.election.view` |
| `secretary` | `governance.election.view` |
| `manager` | no election permission |
| `ceo` | no election permission |

**No role in the current matrix receives `governance.election.register.manage`.** The same applies to the committee, nomination, physical-ballot, count, complaint, and certification permissions: they are defined in the permission catalogue but not granted by this role matrix.

## 3. Operational consequence

PR #140 aligns the three register controller actions with the dedicated `manageRegister` policy and the route middleware permission `governance.election.register.manage`.

Therefore, users who have only the current seeded `governance.election.manage` grant — including `super_admin` and `platform_admin` in this matrix — will **not** pass the register-specific authorization check unless they separately receive the dedicated register permission.

This is fail-closed behavior. It is preferable to silently broadening existing role grants while the legal and organizational authorities remain unresolved.

## 4. Composite-sync warning

`GovernanceRolesSeeder` treats its `ROLE_PERMISSIONS` matrix as authoritative within the Governance permission namespace. Its composite-sync algorithm preserves non-Governance grants but replaces each managed role's Governance grants with the matrix.

Consequently, manually attaching `governance.election.register.manage` to a managed role without first updating the approved matrix is not durable: a subsequent GovernanceRolesSeeder run can detach that grant. Do not work around this by writing directly to `role_has_permissions`.

## 5. Owner/legal decisions required before any grant

The following remain unresolved:

1. Which legally authorized organizational role may prepare the record-date register?
2. Which independent role/body may reconcile it?
3. Which independent role/body may approve and freeze it?
4. May platform-level `super_admin` or `platform_admin` act on tenant election registers, or should they remain excluded from operational election authority?
5. Should the single `register.manage` permission remain, with service-enforced three-actor separation, or should preparation, reconciliation, and approval become separate permissions?
6. What is the Legal Advisor's authority and the Election Committee's delegated scope? This overlaps unresolved legal blocker LEG-06.

The current legal blockers LEG-01..LEG-06 remain controlling. No role should be inferred from a generic title such as president, secretary, board, or tenant administrator without approved company-specific authority.

## 6. Required evidence before a future grant PR

A later Owner-approved role-grant change should include:

- approved organizational role definitions and delegated authority;
- a role-to-action matrix for prepare, reconcile, and approve/freeze;
- conflict-of-interest and emergency access rules;
- exact permission catalogue and seeder inventory reconciliation;
- tests proving grants are added only to approved roles and non-Governance pivots are preserved;
- tests proving rerunning the seeder does not silently remove approved grants;
- audit evidence and independent review.

## 7. Scope and change-control result

- This report is read-only analysis of repository seeders.
- No permission rows, role rows, role pivots, user grants, or production data were changed.
- No seeder was executed.
- Production runtime role assignments are **UNKNOWN / NOT VERIFIED** from repository source alone.
- No merge, deployment, production migration, role grant, voting activation, or production data change is authorized.
