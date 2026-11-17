# ARNA Enterprise Calendar — A2 API Read-only Baseline Report

- Assessment: A2 — read-only repository inventory
- Repository: ake fadaka-git/arna-api
- Branch assessed: main
- Exact API baseline SHA: 717288a68c29a6c4e24c5875cba1190522a5bdd8
- A1 approval: Owner approved A1 and authorized A2.
- Change boundary: No runtime source, migration, configuration, or production data changed by this assessment.
- Overall A2 disposition: PARTIAL — reusable foundations are identified; calendar-specific implementation is not present in the inspected domain tree.

## 1. Executive result

The API has reusable platform foundations for Ethiopian calendar conversion, tenant context, notifications, governance body/position/term/appointment/membership, authority/delegation, Finance accounting primitives, and domain audit/event patterns.

The inspected mases_core/src/Domains tree contains Finance, Governance, Loan, Organization, People, PublicContent, Shareholder, and Wallet. No Calendar domain directory, calendar event model, calendar event migration, calendar-specific service, or calendar routes were found in the inspected tree.

The Governance domain is not yet a meetings/voting engine. It currently has body, position, term, appointment, membership, seat, acting assignment, and authority-grant foundations. Governance configuration declares future meeting/minute/resolution/action-item status vocabularies, but the inspected model/service/migration inventory does not contain meeting, agenda, attendance, vote, resolution, or action-item entities. Electronic voting is explicitly disabled. Existing governance policy documents state that quorum, voting, publication, correction, and retention rules are unresolved/deferred pending governing instruments and Owner decisions.

## 2. Source inventory and disposition

| Capability | Evidence at baseline SHA | A2 disposition |
|---|---|---|
| Ethiopian calendar conversion | mases_core/src/Localization/Calendar/EthiopianCalendar.php and EthiopianDate.php; CoreServiceProvider binding | REUSE; verify one canonical engine and avoid parallel conversion |
| Legacy localization conversion | mases_core/src/Localization/EthiopianCalendar.php, EthiopianDateTime.php, EthiopianFormatter.php, LocalizationManager.php | AMEND/RECONCILE; two calendar class namespaces exist; establish canonical class and compatibility posture |
| Tenant context | Core tenant context and middleware conventions; NotificationService uses TenantContextService | REUSE; all calendar reads/writes must fail closed and tenant-scope every relation |
| Notifications | mases_core/src/Notifications/Models/PlatformNotification.php and Services/NotificationService.php; platform notification migration/controller/routes | REUSE; calendar event producers must publish after commit through reliable event/outbox integration |
| Governance body/position/term | Governance models/services/repositories/policies/migrations | REUSE |
| Governance appointment/membership | Models/services/repositories/policies/migrations and feature tests | REUSE |
| Governance seats/acting assignments | GovernanceSeat, GovernanceSeatOccupancy, GovernanceActingAssignment and services/migration | REUSE; preserve appointment and authority semantics |
| Governance authority | GovernanceAuthorityGrant, service, policy and feature tests | REUSE; not a substitute for meeting quorum or voting authority |
| Governance meetings/agenda/attendance | No corresponding model, migration, service or route in inspected Governance inventory | CREATE after policy contract approval |
| Quorum/voting/resolutions | No runtime entities/routes in inspected inventory; electronic_voting_enabled is false | DEFER; rules unresolved; do not implement binding outcomes from calendar proposal alone |
| Governance action items | Status vocabulary exists in governance.php; no action-item model/service/migration found in inspected inventory | CREATE or integrate with separately approved task owner |
| Finance | Finance domain has legal entities, accounts, periods, journals, lines, FX, consolidation mappings, and reporting services; routes include entity/account/period/journal/trial-balance endpoints | REUSE as read-only source links; budget planning/commitment workflow not found in inspected model inventory |
| Projects/Portfolio | No Project/Portfolio domain directory in inspected Domains tree | MISSING in inspected API tree |
| Tasks / general work management | No standalone Tasks domain directory in inspected tree; Governance has future action-item vocabulary only | MISSING; decide authoritative task ownership |
| Reporting | Finance FinancialReportService and TrialBalanceService; shared scheduled reporting not established in this pass | PARTIAL |
| Communication | No Communication domain directory in inspected Domains tree | MISSING |
| Documents | App File Management feature and separate file/document contracts; API-wide lifecycle not fully mapped in this pass | PARTIAL / further inventory required |
| Resource/room booking | No Calendar resource/reservation model found | MISSING |
| Recurrence/time-zone scheduling | No Calendar recurrence/event engine found | MISSING |

## 3. Governance constraints blocking assumptions

1. docs/governance/P1.3-governance-contract-and-correction-plan.md records meetings, attendance, quorum, voting, decisions/resolutions, publication and correction history as deferred pending explicit rules.
2. docs/governance/ARNA-GOV-POL-PSC-001.md is a draft proposed policy, not evidence of formal adoption. It says quorum and voting must be sourced from approved charters/governing documents; without valid rules, a meeting may discuss but cannot record a binding decision as approved.
3. mases_core/src/Domains/Governance/Config/governance.php declares future lifecycle vocabularies but keeps electronic_voting_enabled false.
4. Calendar scheduling of Board, committee, management, or Shareholders' GA meetings can be designed, but attendance, quorum, ballot, decision certification, proxy rules, notice periods and resolution correction must not be implemented from invented defaults.
5. Owner must approve the applicable governing documents and exact rule source per body before governance implementation. A1 approval does not approve quorum percentages, voting thresholds, notice periods, ballot types, or legal authority.

## 4. Calendar/time architecture findings

- Canonical candidate: MaaleArna\MASES\Core\Localization\Calendar\EthiopianCalendar, bound by CoreServiceProvider according to LocalizationServiceProvider documentation.
- A second legacy class exists at MaaleArna\MASES\Core\Localization\EthiopianCalendar. This is an ambiguity to resolve before adding API endpoints.
- EthiopianDate is an immutable value-object candidate; inspect parsing/formatting contracts and tests before adoption.
- UTC instants with explicit IANA timezone and date-only all-day values are proposed A1 contracts, not existing calendar behavior.
- Africa/Addis_Ababa is a proposed default requiring reconciliation with current platform timezone policy and tenant/user settings.
- E.C.-first display does not justify storing localized date strings or changing finance/governance effective-date semantics.

## 5. Routes and security

- Governance routes expose body, position, term, appointment and membership operations. Meeting/agenda/vote/resolution routes are absent.
- Existing Governance controllers/policies and authenticated tenant middleware are the patterns to reuse.
- Calendar source links must be checked under both calendar and linked-domain policy. Calendar visibility must never grant access to private documents, budgets, shareholder records, or governance resources.
- Personal notifications are recipient-scoped and tenant-scoped. Calendar reminders must preserve this contract; tenant broadcasts require explicit domain event and recipient selection.
- No calendar-specific permission slugs, model names, API routes, or schema are approved by this A2 report.

## 6. Required follow-up before A3

| Item | Status | Required evidence/decision |
|---|---|---|
| Reconcile canonical and legacy Ethiopian calendar classes | OPEN | call graph, service bindings, tests, compatibility plan |
| Timezone and all-day semantics | OPEN | platform timezone policy and E.C./G.C. test vectors |
| Calendar event ownership and visibility | OPEN | tenant/role/organizer/attendee policy matrix |
| Recurrence and exception semantics | OPEN | recurrence contract and timezone behavior |
| Task/action ownership | OPEN | platform task service vs domain-owned action records |
| Budget integration | OPEN | Finance budget model/API or explicit future scope |
| Portfolio/project ownership | OPEN | authoritative domain and API contract |
| Communication integration | OPEN | authoritative communication system and event contract |
| Reporting schedule ownership | OPEN | report registry/scheduler contract |
| Governance meeting rules | BLOCKED | approved Articles/bylaws/charters and body-specific quorum/vote/notice/correction rules |
| Electronic voting | BLOCKED | separate Owner decision and legal/governance validation; feature flag remains false |
| Document retention | OPEN | authoritative Documents lifecycle and retention rules |
| Notification event catalogue | OPEN | event keys, recipient rules, dedupe, retry/escalation |
| API schema and migration plan | NOT STARTED | A3 design, FK/index/idempotency/concurrency/retention review |

## 7. A2 conclusion

API A2: PARTIAL / INVENTORY RECORDED at exact main SHA above. Reusable foundations and hard blockers are identified. This assessment did not alter runtime code or database schema and did not run tests; it was a read-only baseline.

A3 must not begin until architecture decisions are reconciled, especially the two Ethiopian calendar class paths, task/portfolio ownership, Finance budget capability, and governance rules. No calendar runtime implementation is claimed.
