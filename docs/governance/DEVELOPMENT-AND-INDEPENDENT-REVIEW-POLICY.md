# ARNA Application Development, Production-Readiness, and Independent Review Policy

**Status:** Owner-directed project policy  
**Scope:** ARNA application and its supporting repositories, services, and release artifacts  
**Effective:** Upon commit to the repository  
**Authority:** Owner approval governs implementation scope, stage transitions, release, and deployment.

## 1. Policy decision

ARNA is to be fully developed, integrated, enhanced, and internally verified before the project is submitted for independent technical and security review.

Independent review is a **consolidated final-stage activity**. It is not a mandatory approval gate for every feature, remediation, pull request, branch, or incremental development step.

The development team must still perform continuous internal engineering verification. Removing repeated independent reviews does not remove testing, security engineering, evidence recording, or Owner-controlled stage gates.

## 2. Required delivery sequence

### Stage 1 — Complete implementation and integration

- Implement the approved application scope across its relevant modules and repositories.
- Complete required remediation, enhancements, integration, and cross-module workflows.
- Preserve established architecture decisions, data contracts, authorization boundaries, and unrelated repository work.
- Record material design decisions, migrations, dependencies, and known limitations.
- Do not treat an intermediate branch or partial module as the final release candidate.

### Stage 2 — Continuous internal verification

Throughout implementation, run appropriate checks as the code changes, including where applicable:

- Unit, feature, integration, and end-to-end tests.
- Regression tests for fixed defects and security controls.
- Authorization, authentication, tenant-isolation, and access-control checks.
- Data-integrity, transaction, concurrency, and rollback tests.
- Dependency, static-analysis, secret-scanning, and vulnerability checks.
- Build, packaging, compatibility, and migration checks.

Record the exact commit SHA, commands or workflow, results, failures, and unresolved risks. Failed or unavailable checks must be reported accurately; they must not be described as passed. A finding may be tracked and remediated during development without commissioning a separate independent review for that individual step.

### Stage 3 — Production-readiness completion

Before final independent review, complete and internally assess the production-readiness baseline, including:

- Functional completeness and cross-module integration.
- Security controls and least-privilege access.
- Tenant and organizational data isolation.
- Data integrity, backup and recovery, auditability, and lifecycle guarantees.
- Error handling, observability, operational configuration, and maintainability.
- Performance, reliability, accessibility, and supported-device compatibility.
- Database migration and deployment/rollback procedures.
- Privacy, retention, and applicable legal or regulatory requirements.
- Documentation, release notes, known limitations, and operational runbooks.

Production readiness must be supported by evidence. Any remaining exception must be explicitly documented with its impact, mitigation, owner, and disposition. A label such as “production ready” must not be used merely because implementation is complete.

### Stage 4 — Final test APK and release-candidate evidence

When the Android application is ready:

- Generate the final test APK from the designated release-candidate commit.
- Record the repository, branch, exact source SHA, build workflow or environment, build configuration, version, and artifact checksum.
- Install and test the APK on supported Android configurations.
- Execute the agreed smoke, functional, upgrade/data-persistence, offline, and regression tests as applicable.
- Preserve build logs, test results, and the APK artifact or its authoritative repository release location.

The APK is a test/review artifact unless and until a separate release decision authorizes distribution. Do not imply that an APK is production-approved solely because it builds successfully.

### Stage 5 — One consolidated independent review

After the completed application and final test APK are available, arrange an independent technical and security review of the consolidated release candidate.

The review package should include:

- Exact release-candidate source SHA(s) and repository references.
- Architecture and security documentation.
- Test plans, test results, and CI/workflow evidence.
- Dependency and vulnerability reports.
- Database, migration, backup, and recovery evidence.
- APK, checksum, build provenance, and device-test results.
- Known risks, accepted exceptions, and remediation history.

The reviewer should assess the application as a whole, including cross-module interactions and the security of the release candidate. Findings are to be recorded, prioritized, remediated where required, and re-tested against exact SHAs. A material change after review may require targeted re-review or a refreshed consolidated review, depending on its impact.

No separate independent review is required for each intermediate implementation step unless the Owner expressly requests one or a specific legal, contractual, regulatory, or safety obligation requires it.

### Stage 6 — Owner decision and deployment

Independent review does not itself authorize merge, production release, data migration, key provisioning, public distribution, or deployment.

Present the final evidence and review disposition to the Owner for an explicit release/deployment decision. Keep deployment as a separate stage with its own approval. No production deployment or irreversible operational action may be inferred from approval to implement, test, build an APK, or conduct the consolidated review.

## 3. Governance and evidence requirements

- Preserve Owner-governed scope and explicit stage approvals.
- Use exact commit SHAs as the basis for implementation and verification claims.
- Keep frozen baselines immutable unless the Owner explicitly authorizes otherwise.
- Do not merge or deploy merely because a pull request is complete or checks pass.
- Preserve unrelated work and avoid unapproved scope expansion.
- Use clear dispositions: **PASS**, **VERIFIED**, **PARTIAL**, **FAILED**, **MISSING**, **UNRESOLVED**, or **NOT RUN**.
- Distinguish internal verification from independent review.
- Report unavailable paid services or CI capacity honestly and prefer feasible no-cost verification paths where possible.
- Do not claim production readiness, final APK validation, independent review, or deployment without the corresponding evidence.

## 4. Supersession and interpretation

This policy supersedes any prior working assumption that independent review must be requested or completed at every incremental development step. It does not cancel previously identified defects, security requirements, test obligations, Owner approvals, or repository-specific stage gates.

Where this policy conflicts with a specific Owner-approved project decision, applicable law, or mandatory external requirement, pause the affected action and resolve the conflict explicitly. The Owner retains authority over project scope and release decisions.

## 5. Current application of this policy

For work already underway, continue the authorized implementation and remediation branches, perform continuous internal verification, and consolidate the remaining evidence for the final release candidate. Do not request an independent reviewer for an intermediate PR solely because it contains a remediation or implementation step. The independent review is to occur after the application and final test APK are ready, followed by a separate Owner release/deployment decision.
