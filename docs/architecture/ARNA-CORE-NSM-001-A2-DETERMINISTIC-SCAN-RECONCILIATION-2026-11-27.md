# ARNA Core MASES Identity — Deterministic A2 Scan and Reconciliation

- **Record:** ARNA-CORE-NSM-001-A2-SCAN-RECON
- **Assessment:** 2026-11-27 (EAT)
- **Scope:** frozen API and App repository commits only
- **Pattern:** case-insensitive `MASES` or `mase_app` in tracked paths and blob contents
- **Disposition:** **A2 VERIFIED-CLOSED for the defined API/App repository scope**, with the bounded external-consumer limitation recorded below

## 1. Frozen baselines and scan integrity

| Repository | Frozen commit | Expected blobs | Scanned blobs | UTF-8 text | Binary | Bytes scanned | Content occurrences | Content-hit files | Path-hit files |
|---|---|---:|---:|---:|---:|---:|---:|---:|---:|
| `akefadaka-git/arna-api` | `9c0ce83afcc084101fb14e6e55d54e1d953c2ffe` | 1,046 | 1,046 | 1,045 | 1 | 11,604,264 | 4,842 | 786 | 645 |
| `akefadaka-git/arna-app` | `71bed2158894e3ce9cf507e8bf70492ac1f9dfd4` | 493 | 493 | 490 | 3 | 8,563,220 | 4,407 | 336 | 0 |
| **Total** | — | **1,539** | **1,539** | **1,535** | **4** | **20,167,484** | **9,249** | **1,122 repo-file pairs** | **645 API files** |

**Integrity result: PASS.** Both actual checked-out HEAD values matched the exact frozen SHAs. Git tree blob counts matched the independently recorded recursive-tree counts. All 1,539 blobs were read from Git object storage. Four binary files were inventoried; none contained a matching identity token. No non-UTF-8 blobs were encountered.

The API has 645 unique tracked file paths containing the identity: 642 files under `mases_core/src/` and three separately named paths. The API tree contains 214 unique directory paths containing the identity. The App has no tracked file or directory paths containing `MASES` or `mase_app`.

The three additional API path renames are:
- `app/Console/Commands/MasesRectorVerifyCommand.php`
- `database/factories/MaaleArna/MASES/Core/Authorization/Models/PermissionFactory.php`
- `database/seeders/MasesCoreSeeder.php`

Across both repositories, 1,183 unique repository-file pairs have one or more path/content matches. The 9,896 occurrence rows comprise 9,249 content matches and 647 path-name matches.

## 2. Exhaustive occurrence-level reconciliation

Every row in the exported `reconciled-occurrences.csv` has exactly one primary reconciliation class. These counts are occurrence rows, not unique files; files may appear in multiple classes.

| Repository | Reconciliation class | Occurrences | Unique files |
|---|---|---:|---:|
| API | PHP namespace and class references to update | 2,312 | 545 |
| API | Tests and PHPUnit/test harness to update | 1,319 | 153 |
| API | Core source-tree paths to move | 644 | 642 |
| API | Helper, environment, provider, seeder identifiers to update | 363 | 98 |
| API | CI workflow references to update | 319 | 27 |
| API | Active source comments, labels and user text to review/update | 161 | 84 |
| API | Current technical/operational documentation to update | 144 | 18 |
| API | Historical documentation to preserve | 67 | 10 |
| API | Config namespace and container aliases to update | 51 | 24 |
| API | Developer tooling/package/autoload references to update | 44 | 10 |
| API | Additional Core path references to update | 37 | 21 |
| API | Dormant provider/localization cluster — reference review | 13 | 5 |
| API | Localization session/cookie/config references to update | 12 | 7 |
| API | Additional legacy-named paths to rename | 3 | 3 |
| App | CSS token substrate and consumer references to rename | 3,002 | 94 |
| App | Active source comments, component provenance and brand text to update | 853 | 297 |
| App | Stale `mase_app/` source-path/provenance comments to update | 390 | 303 |
| App | Test contracts/assertions to update | 95 | 3 |
| App | User-visible title/product identity references to update | 29 | 10 |
| App | Historical/superseded documentation to preserve with status | 16 | 6 |
| App | Browser-storage compatibility fallbacks to remove | 13 | 5 |
| App | Current README/brand/UX contracts to update | 9 | 3 |
| **Total** | **All classes** | **9,896** | — |

## 3. API reconciliation decisions

1. Move all **642** tracked files under `mases_core/src/` to the frozen canonical root `arna_core/src/`.
2. Replace active PHP declarations, imports, FQCNs and class strings using `MaaleArna\\MASES\\Core\\` with `MaaleArna\\Arna\\Core\\`.
3. Update Composer package/PSR-4/files-autoload metadata, registered provider references, Core config filename and `arna.*` namespace, publish tags, container aliases, helpers, environment names, seeder, command, factory, scripts, PHPUnit, Rector and all 27 affected workflows.
4. Update the active localization runtime's session/cookie key and config references. The separate unregistered `LocalizationServiceProvider` / `LocaleManager` / `mases_localization` cluster remains explicitly dormant; do not register it as part of this migration. Verify call sites before retiring dormant code.
5. Update the 18 current technical/operational documents; preserve the 10 historical records without rewriting history.
6. Update the 153 test/test-harness files so tests assert canonical ARNA identifiers and no active legacy compatibility behavior.

## 4. App reconciliation decisions

1. Replace active `--mases-*` CSS custom-property definitions and consumers with `--arna-*`. Remove old alias chains; do not keep MASES variables as compatibility aliases. The 3,002 occurrence rows include token definitions/consumers and related source comments; the line-level CSV is authoritative for exact paths and lines.
2. Remove legacy browser-storage read fallbacks for `mases.locale`, `mases.tenant_id`, `mases.auth_token`, and `mases.auth.remembered_identifier`. Keep only ARNA keys. The additional `mases_locale` references in API-client prose are explanatory cookie comments, not evidence of client-side cookie access.
3. Update the 390 stale `mase_app/` source-path comments across 303 files and current component provenance/brand comments across 297 files.
4. Update user-visible titles/product identity references in 10 files.
5. Rewrite the three affected App tests to assert no active MASES keys, tokens, aliases or fallbacks.
6. Update the README and current brand/UX contracts. Preserve dated audit/history. Mark the old GUI token-freeze and palette candidate as superseded by the Owner-approved A1 decision; do not treat their old token-preservation direction as current.

## 5. Historical and database boundaries

- Historical Phase 1/2 reports, dated A1–A4 audit records, dated review evidence and historical source-document names remain historically accurate.
- Historical MASES wording is permitted only when it accurately describes the original system, prior repository state or archival provenance. It is not permitted as an active product identity, runtime identifier, fallback key, current design token, current source path or current operational instruction.
- The scan found MASES wording in comments in some existing database migration files and old PHP namespace references in Core-owned migration classes. No MASES-prefixed table or column identifier was observed.
- No database schema, table, column, migration history, stored data or production state is changed by this refactor. Core-owned migration files move with the source tree; migration execution is outside scope.

## 6. External-consumer boundary

The known in-scope consumer is `akefadaka-git/arna-app`, which was included in the deterministic scan. Supplemental public-owner GitHub code searches for the old PHP namespace, `mases_core`, `mases_locale` and `mase_app` returned only the API and App repositories; this is corroborative indexed search, not proof that private/local repositories do not exist.

Private, local or unconnected consumers remain **UNKNOWN / NOT ESTABLISHED**. The Owner has stated that there is no useful deployed content to preserve and that a fresh Plesk GitHub deployment will be performed by the Owner. This bounds the deployment migration exposure; it is not independent proof of zero external consumers. The Owner's deployment responsibility remains unchanged.

## 7. Formal A2 closure

**A2 STATUS: VERIFIED-CLOSED for the defined frozen API/App repository scope.**

Closure basis:
- Exact frozen commit identities verified.
- Both recursive tree counts reconciled with every Git blob scanned.
- All tracked path and content occurrences inventoried.
- All 9,896 occurrence rows assigned a primary reconciliation class.
- Active implementation changes, historical exceptions, dormant code, CI/test/tooling, documentation and database boundaries explicitly reconciled.
- External-consumer uncertainty retained as an explicit, bounded Owner-accepted residual rather than falsely reported as zero.

**A3 is now eligible to begin on isolated API and App branches from the frozen SHAs.** No merge, default-branch change, database migration/schema/data change, production change or deployment is authorized by this closure. A4 and A5 remain separate gates.

## 8. Reproducible scan evidence

- Workflow run: [45975141734](https://github.com/akefadaka-git/arna-api/actions/runs/45975141734)
- Scan branch: `audit/arna-a2-frozen-content-scan-20261127`
- Scan workflow commit: `d0e8eccd944988a596b065d3f4f175ad14ce60dc`
- Artifact: `arna-a2-frozen-content-scan-20261127`, ID `15187345405`, 314,364 bytes
- Artifact SHA-256: `46c7d28c52d9fec755ddb178f8a580e75af14379e6b3cf3d077574f785a693c4`
- Artifact expires: 2026-12-04 09:36:48 UTC
- Artifact files: `scan-report.json`, `tracked-file-inventory.csv`, `identity-occurrences.csv`, `scan-summary.md`
- Derived occurrence-level reconciliation: `reconciled-occurrences.csv`; per-file rollup: `reconciliation-file-summary.csv`
