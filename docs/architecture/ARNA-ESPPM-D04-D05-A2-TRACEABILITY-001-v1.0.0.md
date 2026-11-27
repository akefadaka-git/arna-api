# ARNA ESPPM D04-D05 A2 Reconciliation and Traceability Baseline

- **Document ID:** ARNA-ESPPM-D04-D05-A2-TRACEABILITY-001
- **Version:** 1.0.0
- **Stage:** A2 — read-only reconciliation
- **Work package:** R08
- **Prepared:** 2026-11-24
- **Repository of record:** `akefadaka-git/arna-api`
- **Inspected API main:** `6e274c25ecc55b84e026e879d735b77f0e51592f`
- **Inspected App main:** `ffe0495c0eb7c8b5d2013419123aac5b68893d51`
- **Change class:** Documentation-only A2 evidence artifact
- **Domain dispositions:** D04 UNVERIFIED; D05 UNVERIFIED
- **Implementation / merge / deployment authority:** NOT GRANTED

---

## 1. Purpose and decision

This baseline records the bounded A2 evidence found for:

- D04 — Enterprise Risk Management
- D05 — Compliance & Internal Control Management

It defines requirement-level traceability and distinguishes proposed policy language from adopted corporate authority, implemented system capability, and verified operating effectiveness.

**Controlling A2 dispositions remain unchanged:**

| Domain | Owner-approved A2 disposition | R08 result | Disposition change |
|---|---|---|---|
| D04 Enterprise Risk Management | UNVERIFIED | UNVERIFIED — no accepted enterprise risk contract or verified operational risk-management capability established | NONE |
| D05 Compliance & Internal Control Management | UNVERIFIED | UNVERIFIED — proposed control material exists, but no adopted complete compliance/internal-control contract or verified operating lifecycle established | NONE |

This work package does not upgrade either domain to PARTIAL or SUPPORTED. It does not close any domain gap or accept residual risk.

## 2. Scope and method

Read-only inspection was limited to the exact API/App main snapshots stated above and the source documents indexed in Section 5.

The review:
1. identified relevant policy and control references;
2. tested whether each reference establishes an adopted authority, domain-owned contract, implementation, or verification result;
3. mapped requirements to exact source blobs;
4. recorded unsupported, missing, and unresolved elements; and
5. preserved the distinction between D04 risk ownership and D05 compliance/control assurance.

No application source, schema, migration, seeder, runtime configuration, production data, or repository main branch was changed. No tests or operational control assessments were run.

## 3. Evidence interpretation

- **PARTIAL (proposal only):** a source discusses the concept or proposed control, but does not establish adoption, complete requirements, implementation, or operating effectiveness.
- **MISSING:** no suitable source or capability was established in the inspected scope.
- **UNVERIFIED:** the evidence is insufficient to establish the domain contract or disposition.
- **NOT RUN:** no execution or operating-effectiveness test was performed.
- **NOT ADOPTED:** a document expressly remains a draft or provisional instrument.

A requirement-level PARTIAL label is not a domain-disposition upgrade.

## 4. D04 — Enterprise Risk Management

### 4.1 Requirement-to-source traceability

| Requirement | Minimum A2 requirement | Evidence found | Assessment |
|---|---|---|---|
| D04-RQ-01 | Enterprise risk taxonomy and category hierarchy | No accepted taxonomy located. PSC draft contains generic risk references only. | MISSING |
| D04-RQ-02 | Risk context linked to enterprise objectives, domains and operating environment | No accepted enterprise risk context or objective linkage contract located. | MISSING |
| D04-RQ-03 | Approved risk appetite, tolerance and escalation thresholds | No approved appetite/tolerance statement or threshold schedule located. | MISSING |
| D04-RQ-04 | Risk identification and accountable risk-register ownership | No accepted enterprise risk register or accountable risk-owner model located. | MISSING |
| D04-RQ-05 | Cause, event, consequence and affected-object recording | No risk-event data contract located. | MISSING |
| D04-RQ-06 | Inherent likelihood, impact and exposure assessment methodology | PSC draft refers to risk-based sampling and risk ratings, but no approved enterprise assessment methodology. | PARTIAL |
| D04-RQ-07 | Existing control mapping to each risk, with control owner and design assessment | Draft PSC policy and matrix describe proposed controls and segregation of duties; no enterprise risk-control mapping contract. | PARTIAL |
| D04-RQ-08 | Residual risk calculation after control assessment | No approved residual-risk method or evidence model located. | MISSING |
| D04-RQ-09 | Risk treatment options, plans, owners, due dates and progress | PSC draft describes corrective action for findings; it does not establish a general risk-treatment lifecycle. | PARTIAL |
| D04-RQ-10 | Risk acceptance authority, scope, rationale, expiry and review | No approved risk-acceptance authority or delegated thresholds located. | MISSING |
| D04-RQ-11 | Key risk indicators, thresholds, monitoring cadence and breach alerts | Draft management-risk reporting references exist; no approved KRI catalogue or monitoring contract. | PARTIAL |
| D04-RQ-12 | Risk escalation, incident linkage and emergency escalation paths | Draft PSC policy describes escalation for specified serious control matters; no enterprise risk escalation matrix. | PARTIAL |
| D04-RQ-13 | Emerging, concentration, dependency and cross-domain risk aggregation | No accepted enterprise aggregation or dependency-risk contract located. | MISSING |
| D04-RQ-14 | Periodic risk reporting, governance review and decision history | Draft PSC policy refers to management-risk reporting; no accepted enterprise risk reporting and review standard. | PARTIAL |
| D04-RQ-15 | Risk assurance, reassessment, change history and closure evidence | Draft corrective-action follow-up is narrow and provisional; no enterprise risk assurance/closure lifecycle. | MISSING |

**D04 requirement assessment:** 6 PARTIAL / 9 MISSING / 0 VERIFIED-CLOSED.

PARTIAL items indicate only that draft material discusses a related concept. They are not accepted D04 controls or operating evidence.

### 4.2 D04 evidence findings

1. The PSC policy draft refers to risk in proportionality/value decisions, risk-based review sampling, provisional severity, management-risk reporting, and risk assessment of exceptions.
2. The PSC matrix contains proposed control statements, including proposed procurement controls, evidence matching, independent review, conflict disclosure, and authority mapping.
3. Neither source is an adopted enterprise risk-management contract.
4. The material does not define an enterprise risk taxonomy, appetite, risk scoring methodology, inherent/residual exposure model, risk register, risk owner, treatment/acceptance authority, KRI framework, or portfolio-level risk reporting.
5. A control finding or corrective-action record is not equivalent to a complete enterprise risk record.
6. No D04-specific API domain root, application workflow, or D04 acceptance test was established by the evidence reviewed for this work package.

### 4.3 D04 A2 acceptance conditions

Before D04 can be treated as sufficiently specified, an accepted A1 contract must define:
- risk taxonomy, scope and context;
- appetite/tolerance and competent approval authority;
- risk register schema, ownership and lifecycle;
- inherent and residual assessment method;
- control linkage and control-effectiveness assessment;
- treatment, acceptance, escalation and review;
- KRIs, monitoring and reporting;
- cross-domain aggregation and dependencies; and
- audit history, evidence, reassessment and closure rules.

All conditions remain OPEN. No D04 A4 scenarios were executed.

## 5. D05 — Compliance & Internal Control Management

### 5.1 Requirement-to-source traceability

| Requirement | Minimum A2 requirement | Evidence found | Assessment |
|---|---|---|---|
| D05-RQ-01 | Authoritative legal, regulatory, contractual and internal obligation register | No complete obligation register or owner/citation lifecycle located. | MISSING |
| D05-RQ-02 | Compliance applicability assessment by entity, activity, jurisdiction and effective date | No accepted applicability model located. | MISSING |
| D05-RQ-03 | Control catalogue mapped to obligations and risks | PSC policy/matrix describe proposed operational controls; no complete enterprise catalogue or approved mapping. | PARTIAL |
| D05-RQ-04 | Control owner, performer, reviewer and accountable authority | Draft policy describes segregation and independent review principles; formal role appointments and mandates remain unverified. | PARTIAL |
| D05-RQ-05 | Control objective, design, frequency, population and execution procedure | Draft policy describes selected procedures; no complete controlled design specification. | PARTIAL |
| D05-RQ-06 | Control execution records and attributable supporting evidence | Draft policy describes evidence handling and review files; no verified enterprise execution lifecycle established. | PARTIAL |
| D05-RQ-07 | Control testing plans, samples, criteria and results | Draft PSC review procedure discusses scope, sample, criteria and evidence; no approved enterprise testing standard or implementation evidence. | PARTIAL |
| D05-RQ-08 | Deficiency, exception and non-compliance register | Draft PSC policy describes findings and exceptions; no adopted enterprise register and classification contract. | PARTIAL |
| D05-RQ-09 | Investigation intake, triage, case ownership and due process | Draft policy refers suspected non-compliance to authorized persons; investigation mandate and lifecycle are not established. | PARTIAL |
| D05-RQ-10 | Corrective/preventive action, owner, deadline and interim safeguards | Draft policy describes corrective-action ownership, deadlines and follow-up; adoption and implementation are not verified. | PARTIAL |
| D05-RQ-11 | Independent assurance and reviewer independence | Draft policy describes a Control Committee review function; formal charter, appointments and reporting line remain unverified. | PARTIAL |
| D05-RQ-12 | Escalation, whistleblowing/retaliation safeguards and external referral authority | Draft policy contains proposed escalation and confidentiality principles; approved channels and competent authority are not established. | PARTIAL |
| D05-RQ-13 | Compliance status reporting and overdue/breach monitoring | Draft policy proposes control and management-risk reporting; no accepted metric definitions, cadence or system implementation. | PARTIAL |
| D05-RQ-14 | Evidence integrity, audit trail, access and records retention | Draft policy proposes traceability and access controls; approved retention schedule and verified D05 system enforcement are absent. | PARTIAL |
| D05-RQ-15 | Regulatory/policy change monitoring and obligation reassessment | No accepted compliance-change monitoring lifecycle located. | MISSING |
| D05-RQ-16 | Formal control certification and periodic management attestation | No approved certification/attestation standard located. | MISSING |
| D05-RQ-17 | Risk-based control plan and linkage to D04 | Draft PSC material mentions risk-based sampling; no accepted D04-D05 risk/control integration contract. | PARTIAL |
| D05-RQ-18 | Domain reporting, assurance conclusions and retained decision history | Draft reporting principles exist; no accepted enterprise assurance opinion and sign-off lifecycle. | PARTIAL |

**D05 requirement assessment:** 13 PARTIAL / 5 MISSING / 0 VERIFIED-CLOSED.

PARTIAL items indicate draft design concepts only. They do not prove adoption, complete coverage, implementation or operating effectiveness.

### 5.2 D05 evidence findings

1. `ARNA-GOV-POL-PSC-001 v0.1.0` expressly identifies itself as a draft proposed for formal corporate approval. Its approval authority and approval record are pending.
2. The policy states that it becomes binding only after competent corporate approval, confirmation against governing documents and applicable law, and publication of an approved version.
3. The policy proposes a Control Committee review/assurance function, evidence collection, testing, findings, corrective action, reporting, follow-up, exceptions, non-compliance handling, records and digital-control principles.
4. The policy itself states that it does not establish that controls are already implemented in the application.
5. `ARNA-GOV-MAT-PSC-001-AM v0.2.0` is a provisional control baseline. Its own source-verification notes state that formal approval, role/mandate evidence and underlying authority remain outstanding.
6. The PSC matrix is a limited operational control proposal; it is not a complete obligation register, enterprise compliance framework, formal internal audit charter or evidence of control effectiveness.
7. No D05-specific API domain root, complete application workflow or D05 acceptance-test result was established by the evidence reviewed for this work package.

### 5.3 D05 A2 acceptance conditions

Before D05 can be treated as sufficiently specified, an accepted A1 contract must define:
- obligation sources, applicability, ownership and effective dates;
- obligation-to-risk-to-control traceability;
- control catalogue and design standards;
- control owner, performer, reviewer and independence requirements;
- execution evidence, testing, exceptions and deficiencies;
- investigation and non-compliance handling;
- corrective/preventive action and escalation;
- independent assurance, reporting and attestation;
- records, confidentiality, retention and legal hold; and
- regulatory/policy change monitoring and reassessment.

All conditions remain OPEN. No D05 A4 scenarios were executed.

## 6. Exact source index

All source blobs below were retrieved from the specified repository paths at the inspected default-branch snapshot.

| Source ID | Repository path | Blob SHA | Source status | Bounded relevance |
|---|---|---|---|---|
| SRC-01 | API `docs/governance/ARNA-GOV-POL-PSC-001.md` | `0486e6f1870f52fa596460b1c5cc2d8c5f10b485` | Draft v0.1.0; not formally adopted | Proposed committee/control-review, evidence, finding, corrective-action, risk-reporting and digital-control language |
| SRC-02 | API `docs/governance/ARNA-GOV-MAT-PSC-001-AM.md` | `07eb3f567bcc67fe2c7ac8e66c0a993c0d1e69c2` | Provisional Owner-approved control baseline; formal authority/source verification outstanding | Limited control matrix, proposed segregation, evidence, conflict and review controls |
| SRC-03 | API `README.md` | `b2f730fc147347cb3f2cc3385fa962e036544897` | Repository architecture statement | Backend authorization and tenant boundaries are API responsibilities; domain listing is not proof of implementation |
| SRC-04 | App `README.md` | `e0a341263168f177e455f313d0ece04ff55eb839` | Repository architecture statement | Frontend does not own backend business rules or authoritative authorization; feature folders are not proof of completeness |

### 6.1 Source limitations

- SRC-01 is a draft and explicitly not binding.
- SRC-02 is provisional and does not verify committee appointments, mandate, monetary thresholds, policy adoption or production controls.
- SRC-03 and SRC-04 describe repository boundaries; they are not D04/D05 implementation evidence.
- No bank records, account details, mandates, statements, signatory records or reconciliation documents were requested or used in this work package.
- No external statutory register, regulator, live system or production environment was accessed.

## 7. Domain boundaries and interfaces

| Boundary | D04 ownership | D05 ownership |
|---|---|---|
| Risk vs compliance | Enterprise risks, appetite, assessment, treatment, acceptance and aggregation | Obligations, compliance applicability, control design/testing, breaches and assurance |
| Risk vs control | D04 consumes control-effectiveness information to reassess residual risk | D05 owns control catalogue, testing and deficiency/assurance evidence |
| Governance | D04 defines risk information and escalation requirements; competent governance authority approves appetite/acceptance within lawful powers | D05 defines compliance/control evidence and assurance requirements; governance authority appoints and mandates assurance bodies |
| Legal | D04 records legal/regulatory exposure as risk | D05 tracks authoritative obligations and compliance status; D06 Legal remains authoritative for legal interpretation/advice |
| Finance and operations | D04 aggregates financial/operational risks from source domains | D05 maps obligations and tests controls in source domains; source domains retain transaction ownership |
| Documents and records | D04 requires risk decision/evidence history | D05 requires control/evidence/assurance record retention and access rules; D20 remains the records platform owner |

Neither D04 nor D05 may usurp the source domain's operational authority. A control test does not authorize a transaction; a risk record does not create a legal obligation or corporate delegation.

## 8. Cross-domain requirements and dependencies

1. D04 must consume relevant operational risk signals from D03, D06, D09–D24 and the remaining enterprise domains without assuming those domains are fully implemented.
2. D05 must map obligations and controls to the domains and legal entities to which they apply.
3. D06 Legal must provide authoritative legal interpretation and regulatory-source governance; D05 must not invent legal obligations.
4. D03 Governance must establish the competent authority, committee charter, appointments and decision record where applicable.
5. D20 Documents & Records must provide the approved records lifecycle and evidence-retention controls; D05 remains responsible for defining the compliance evidence requirements.
6. D23 Reporting & BI may consume approved risk and compliance metrics but must not redefine their authoritative meanings.
7. D24 IT & Digital Systems must enforce technical access, audit, security and platform controls; D05 defines control objectives and assurance criteria, not platform administration.

## 9. Open gaps and A2 exit conditions

| Gap ID | Domain | Outstanding item | Status |
|---|---|---|---|
| R08-G01 | D04 | Accepted enterprise risk A1 contract | MISSING |
| R08-G02 | D04 | Risk appetite/tolerance and acceptance authority | MISSING |
| R08-G03 | D04 | Risk register, assessment and residual-risk model | MISSING |
| R08-G04 | D04 | Risk treatment, KRI, escalation and reporting standard | MISSING |
| R08-G05 | D05 | Adopted compliance/internal-control policy and obligation register | MISSING |
| R08-G06 | D05 | Formal Control Committee charter, appointments and reporting line | UNVERIFIED |
| R08-G07 | D05 | Complete obligation-to-control catalogue and testing standard | PARTIAL |
| R08-G08 | D05 | Implemented and verified execution, investigation, remediation and assurance lifecycle | MISSING |
| R08-G09 | D04/D05 | Accepted D04-D05 integration and risk/control traceability | MISSING |
| R08-G10 | D04/D05 | Domain-specific API/App contract and acceptance evidence | MISSING |

R08 is complete as a bounded repository evidence reconciliation package, not as domain closure. The above gaps remain open.

## 10. Verification and authorization

| Item | Result |
|---|---|
| API/App main source inspection | COMPLETE for the bounded sources listed |
| D04 requirement traceability | COMPLETE for this A2 baseline |
| D05 requirement traceability | COMPLETE for this A2 baseline |
| D04 domain disposition | UNVERIFIED — PRESERVED |
| D05 domain disposition | UNVERIFIED — PRESERVED |
| D04/D05 requirements verified closed | 0 |
| Application tests / A4 scenarios | NOT RUN |
| Operating-effectiveness assessment | NOT RUN |
| Legal adoption / committee authority verification | UNRESOLVED |
| Application source/schema/migration/seeder/runtime changes | NONE |
| API main / App main changes | NONE |
| PR merge | NOT AUTHORIZED |
| A3 implementation | NOT AUTHORIZED |
| A4 verification | NOT STARTED |
| A5 deployment | NOT AUTHORIZED |

**R08 decision:** COMPLETE — A2 EVIDENCE RECONCILIATION ONLY. Keep D04 and D05 UNVERIFIED and keep overall A2 OPEN. No residual exception is accepted and no later stage is authorized.

**End of D04-D05 A2 Reconciliation and Traceability Baseline v1.0.0.**
