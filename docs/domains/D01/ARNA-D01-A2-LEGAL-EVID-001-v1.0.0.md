# ARNA D01 — A2 Legal and Authority Evidence Inventory

- **Record ID:** ARNA-D01-A2-LEGAL-EVID-001
- **Version:** 1.0.0
- **Prepared:** 2026-11-25
- **Record type:** A2 read-only evidence inventory and exception disposition
- **Repository:** `akefadaka-git/arna-api`
- **Working branch:** `docs/arna-d01-a2-evidence-register`
- **Scope:** LE-01 through LE-06 in `ARNA-D01-CONTRACT-001 v1.0.0`
- **Disposition:** **EVIDENCE INVENTORY RECORDED — ALL SIX EXCEPTIONS REMAIN OPEN**
- **No legal opinion or legal authority determination is made by this record.**

## 1. Purpose and evidence standard

This inventory records what the currently accessible destination-repository evidence does and does not establish for the six D01 legal/authority exceptions. It distinguishes:
1. Owner-confirmed document purpose or possession;
2. historical descriptions and working notes;
3. files actually preserved and inspectable in the destination repository;
4. authenticated corporate instruments, resolutions, appointments or legal determinations.

Only category 4 can resolve the corresponding legal/authority exception. A repository note, code model, proposed contract, draft charter, historical conversation summary or architecture approval is not a substitute.

This is a documentation-only A2 action. It does not alter any source legal instrument, infer legal hierarchy, validate a resolution, appoint an officer, or change any requirement status.

## 2. Source inventory inspected

| Source | Exact repository evidence | What it establishes | What it does not establish |
|---|---|---|---|
| Historical Shareholder and Articles Reconciliation Notes | `docs/legal/articles-of-association/HISTORICAL-RECONCILIATION-NOTES.md`, main blob `856cd6afbcfd16f26b26c98d91850ebd82fcd29a` | Historical source-status statements, Owner-reported possession of the original Articles, historical document descriptions and explicit evidence limitations | Original binary, verified transcription, legal validity, amendment chain, or competent authority |
| Articles source-status README | `docs/legal/articles-of-association/README.md`, main blob `c90f607d818304eaa44921d2bc05188a7e4da878` | Original Articles reported by Owner; original PDF not preserved/verified in repository; separate 17-page bylaws must not be substituted | Contents or legal effect of original Articles or bylaws |
| Shareholder Phase 2 Charter | `docs/PHASE_2_SHAREHOLDER_CHARTER.md`, main blob `ca16db92b7fc1eb3da31f398120b22204e8716c7` | A scaffold explicitly marked DRAFT / NOT APPROVED; its own proposed source hierarchy and pending decisions | Adopted company policy, corporate resolution, or legal authority |
| D01 architecture contract | `docs/domains/D01/ARNA-D01-CONTRACT-001-v1.0.0.md`, branch blob `a20752c112430d8d09eaae4090fccb1ab2ad01a1` | Owner-approved architecture routes and explicit LE-01–LE-06 exception register | Statutory competence, operative instrument clauses, current appointments or adopted strategy |
| D01 producer source-owner review | `docs/domains/D01/ARNA-D01-A2-SOR-001-v1.0.0.md`, blob `756d0343d4de6a4eec928ba691585195fd8979ce` | Technical review says named current custodians are NOT ESTABLISHED for the 16 producer domains; D03 governance models exist; proposed interfaces remain unapproved | Actual current appointment records, a specific adopted strategy resolution, or legal validity of any instrument |

The repository's `docs/legal/articles-of-association/` directory on main contains only the historical reconciliation notes and README. No original Articles PDF or official register scan is present in that directory. A repository code search for the literal phrase `22-page` returned no result; this is a search result, not proof that no copy exists elsewhere or in another format.

## 3. Distinct corporate-source documents — identity and purpose

The following distinctions are mandatory:

### 3.1 Original Articles of Association
- The Owner has stated that the original Articles are in their possession.
- Historical repository notes record a prior description of an Amharic image-scanned PDF dated 16/12/2016 E.C., reportedly executed, notarized and sealed.
- The original binary, filename, checksum, page count and page-by-page contents are not currently preserved or verified in the destination repository.
- This original Articles document is distinct from both bylaws documents below.

**Disposition:** Owner-reported possession; repository source unavailable for current verification. Do not infer exact clauses from historical summaries.

### 3.2 17-page bylaws document
- Owner's current instruction identifies the 17-page bylaws document as the version intended for government and public use.
- Historical repository notes identify a separate 17-page signed/stamped bylaws PDF and explicitly warn that it is not the original Articles.
- Historical notes say the 17-page document was only partially reviewed; exact clauses and legal effect were not fully transcribed or verified.
- Its complete binary and verified page-by-page transcription are not present in the inspected legal-source directory.

**Disposition:** Purpose recorded; operative/legal status and relationship to the other instruments remain unverified.

### 3.3 22-page internal-use bylaws document
- Owner's current instruction identifies the 22-page bylaws document as intended for internal use.
- The inspected destination repository does not contain this document in the legal-source directory; the repository search for the literal phrase `22-page` returned no matches.
- No adoption record, amendment chain, registration evidence, certified copy or legal determination connecting it to the 17-page document or the original Articles was found in the inspected sources.

**Disposition:** Purpose recorded; binary, identity, adoption, legal effect and relationship to the other instruments remain unverified.

**No hierarchy is inferred.** The public/government-use purpose of the 17-page document and internal-use purpose of the 22-page document are recorded as distinct Owner instructions. Purpose does not establish which instrument is registered, adopted, operative, amended, subordinate, controlling or legally effective. Neither bylaws document may be substituted for the original Articles.

## 4. LE-01 through LE-06 evidence disposition

| Exception | Repository evidence found | Missing evidence / determination | Disposition |
|---|---|---|---|
| **LE-01 — Competent authority** | Owner-approved D01 architecture contract states GM → Board → CEO → D01 coordination, subject to law and operative instruments. Historical draft charter states a proposed source hierarchy but is expressly NOT APPROVED. | Certified operative Memorandum/Articles/bylaws, current applicable law analysis and competent Ethiopian corporate-law determination of the organs' powers | **OPEN — NOT LEGALLY VERIFIED** |
| **LE-02 — Public/internal instrument identity and hierarchy** | Owner-confirmed purposes: 17-page for government/public; 22-page for internal use. Historical notes identify the 17-page signed/stamped bylaws as distinct from the original Articles. | Both complete source binaries, certification/registration/adoption evidence, amendment history, original Articles source inspection, and legal determination of identity, applicability and hierarchy | **OPEN — PURPOSE CLARIFIED; LEGAL IDENTITY/HIERARCHY UNRESOLVED** |
| **LE-03 — Annual budget reservation** | Architecture baseline selects Board approval of annual strategic plan and associated budget only within lawful authority; contract expressly preserves any GM reservation | Applicable statutory and operative clauses, any reserved-matter schedule, valid delegation and competent legal determination | **OPEN — NOT VERIFIED** |
| **LE-04 — Major-change reservation and delegation** | Architecture baseline classifies changes by strategic impact, reserved authority, risk and materiality; numeric thresholds deferred to a separate controlled policy | Operative reserved-matter provisions, adopted policy, current delegations and competent-organ resolutions | **OPEN — NOT VERIFIED** |
| **LE-05 — Current appointments and delegations** | Governance source code includes models/tables for governance bodies, appointments and authority grants; source-owner review explicitly says current named custodians/appointments are NOT ESTABLISHED | Current effective-dated appointment/election/acceptance records, term status, authority grants, delegation instruments, scope and revocation/supersession evidence | **OPEN — CODE STRUCTURES EXIST; CURRENT PEOPLE/AUTHORITY NOT ESTABLISHED** |
| **LE-06 — Adopted strategy baseline** | Owner-approved D01 architecture contract establishes intended lifecycle and approval architecture. Historical notes say signed Board/shareholder resolutions had not been located or verified at that time | Authenticated competent-organ resolution, adopted strategy document/version, decision/effective dates, scope, amendments and supersession history | **OPEN — NO AUTHENTICATED ADOPTED STRATEGY EVIDENCE** |

## 5. Evidence interpretation controls

1. **Code is not corporate authority.** The existence of `GovernanceBody`, `GovernanceAppointment` and `GovernanceAuthorityGrant` models/tables proves that software structures exist, not that any named person currently holds office or that a specific grant is legally effective.
2. **A document repository is not a legal determination.** D20 custody, upload, metadata, checksum or workflow status cannot by itself prove execution, registration, adoption, legal applicability or precedence.
3. **A draft is not an adopted policy.** The Phase 2 charter remains DRAFT / NOT APPROVED and cannot resolve LE-01, LE-03 or LE-04.
4. **Architecture approval is not a corporate resolution.** The D01 Owner approval governs the ARNA architecture baseline only; it does not establish that the GM or Board has adopted a strategy or approved a budget.
5. **Historical summaries remain provisional.** Reported Articles clauses, founder/share figures, register pages, capital/payment figures and historical draft governance artifacts must not be enforced as legal facts without source inspection and authentication.
6. **No legal-source substitution.** The 17-page public/government-use bylaws, 22-page internal-use bylaws and original Articles are separate evidence items until their identity and legal relationship are authoritatively determined.

## 6. Required resolution evidence by exception

- **LE-01:** certified operative corporate instruments plus a competent legal analysis identifying the organ with authority for each decision class.
- **LE-02:** preserved copies of the original Articles, 17-page public/government-use bylaws and 22-page internal-use bylaws; registration/adoption/amendment records; written determination of identity, applicability and hierarchy.
- **LE-03:** legal analysis of annual budget approval/reservation, applicable operative clauses and any valid GM/Board delegation.
- **LE-04:** reserved-matter schedule, adopted change-control policy, competent resolutions and effective delegation limits.
- **LE-05:** effective-dated election/appointment and acceptance records, terms, acting appointments where applicable, authority grants, delegations and revocation/supersession history.
- **LE-06:** authenticated strategy resolution and the exact approved strategy version with effective date, scope, conditions and supersession chain.

These are evidence requirements, not a request for the Owner to re-upload material in this response. The historical source-status record notes the current upload limitation; do not repeatedly request the same upload while it persists. Continue using accessible repository evidence and preserve the source gaps.

## 7. Stage and status decision

- LE-01: OPEN.
- LE-02: OPEN; document purposes distinguished, legal identity/hierarchy unresolved.
- LE-03: OPEN.
- LE-04: OPEN.
- LE-05: OPEN.
- LE-06: OPEN.
- D01 A2 domain disposition: **UNVERIFIED / OPEN**.
- Requirement checklist: **16 PARTIAL / 7 MISSING / 1 UNRESOLVED / 0 VERIFIED-CLOSED**; unchanged by this inventory.
- D01 subordinate A1 remains DRAFT / NOT FROZEN.
- No source legal document was edited, created, certified or authenticated.
- No tests, builds, workflows, legal review or runtime checks were run: **NOT RUN**.
- A3 implementation: **NOT AUTHORIZED**.
- A4: **NOT STARTED**.
- A5: **NOT AUTHORIZED**.
- Merge: **NOT AUTHORIZED**.
- Deployment: **NOT AUTHORIZED**.

## 8. Next A2 action

Continue a bounded read-only inspection of the existing D03 governance implementation and its fixtures/tests to determine exactly what software evidence exists and whether any records are merely synthetic/test data. Do not treat fixtures, seeders or test factories as current appointments, resolutions or authority grants. Record the result as technical evidence only; legal exceptions remain open until authenticated corporate evidence and competent legal determination are available.
