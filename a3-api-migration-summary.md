# API A3 identity migration

- Frozen base: `9c0ce83afcc084101fb14e6e55d54e1d953c2ffe`
- Core files moved: 642 tracked files from the legacy Core root to `arna_core/src/`
- Text files changed by deterministic replacements: 741
- Historical legacy-name mentions preserved in migration prose: 11
- Active legacy namespace/path/config/helper/env/provider/seeder/command scan: PASS (zero residual lines)
- Historical docs preserved: 10 named records
- Dormant provider remains unregistered; no database schema/data changes.
- 27 CI workflow path references are deferred to connector-authored updates; the Actions token cannot modify workflow files.
- Tests/builds not run here; A4 verification remains separate.
