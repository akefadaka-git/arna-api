# ARNA General Assembly (GA) System — A1 Requirements Baseline

- **Document ID:** ARNA-GOV-GA-A1-001
- **Version:** 1.0.0
- **Status:** A1 baseline frozen; legal activation parameters explicitly gated
- **Owner:** ARNA Owner / competent corporate authority
- **Domain:** Governance
- **Related domains:** Shareholder, Organization, Finance, Documents, Calendar, Notifications, Audit
- **Date:** 2026-11-04

## 1. Purpose and scope

This document freezes the system requirements for the ARNA Shareholder General Assembly (GA) lifecycle. It covers ordinary and extraordinary GAs, convening, notices, electorate, attendance, proxy representation, quorum, time management, agenda, motions, voting, resolutions, minutes, penalties, notifications, decision authority, execution, records and audit.

This is a requirements baseline, not a legal opinion and not a substitute for the operative Memorandum and Articles, applicable Ethiopian law, approved company regulations or valid corporate resolutions.

## 2. Authority hierarchy and conflict handling

The system shall apply the following precedence, subject to applicable mandatory law:

1. Applicable Ethiopian law and binding regulatory requirements.
2. The company's operative Memorandum and Articles, including valid amendments.
3. Valid resolutions of the General Assembly and Board, each within its lawful authority.
4. Approved company regulations and policies.
5. Owner-approved technical requirements and implementation contracts.
6. Application configuration and operational instructions.

A lower-level source shall not override a higher-level source. Where sources conflict, are incomplete, or cannot be authenticated, the affected operation shall be blocked and referred to the authorized governance/legal reviewer. The software shall not resolve legal ambiguity by inference.

Every governing source shall be registered with title, identifier, version, approval/effective date, approving authority, source file, supersession status and integrity metadata.

## 3. Meeting classes and convening

The system shall support:
- Ordinary General Assembly.
- Extraordinary General Assembly.
- Other shareholder meeting classes only where authorized by the governing instruments and applicable law.

Every meeting record shall include a unique identifier, meeting class, convening authority, authorization evidence, purpose, proposed date/time, time zone, venue or approved remote participation mode, notice/record dates, secretary, chair (when appointed), lifecycle state and audit history.

The system shall enforce a controlled lifecycle:
1. Proposed / draft.
2. Authority review and authorization.
3. Scheduled.
4. Notice approved and issued.
5. Registration open.
6. Quorum assessment.
7. In session.
8. Adjourned or suspended, where authorized.
9. Closed.
10. Minutes under preparation, review and approval.
11. Archived.
12. Post-meeting decisions under execution and monitoring.

The system shall not treat creation or scheduling as proof of valid convening. Notice period, notice method, recipients, record date, meeting authority and special-meeting conditions must be configured from the verified operative sources before the relevant meeting can be issued.

## 4. Electorate and voting entitlement

The electorate shall be derived from the authoritative shareholder register and the legally applicable record date.

The system shall:
- Verify shareholder identity and active legal status.
- Snapshot the eligible electorate at the authorized record date.
- Determine voting entitlement from the applicable shareholding, share class and governing voting rights.
- Preserve the underlying share and voting-right calculations.
- Distinguish ownership, attendance, representation, quorum presence and votes cast.
- Prevent duplicate exercise of the same voting entitlement.
- Preserve abstentions, invalid ballots, challenges and corrections as distinct states.
- Reconcile entitlement changes only through authorized, auditable correction workflows.

The system shall not assume one shareholder equals one vote. It shall not assume that every share has identical voting rights. These matters must be taken from authenticated governing instruments and applicable law.

Electronic voting is not enabled by this baseline. It requires a separate approved legal, corporate, security and operational authorization, including identity assurance, ballot secrecy where required, integrity, auditability, accessibility and dispute handling.

## 5. Attendance and representation

Attendance channels shall be configurable only to the extent authorized by the governing instruments and applicable law. The record shall distinguish physical attendance, approved remote attendance (if authorized), proxy representation, excused absence and unexcused absence.

Each attendance event shall retain:
- Meeting and shareholder identifiers.
- Verified identity and entitlement.
- Check-in/check-out timestamps.
- Registration officer and evidence.
- Attendance channel and status.
- Corrections, reason, approver and immutable audit trail.

The system shall not count the same voting entitlement twice through simultaneous personal and proxy attendance.

## 6. Proxy requirements

The system shall provide proxy submission, validation, review, approval, rejection, revocation, expiry and audit history.

Every proxy shall identify the principal shareholder, representative, meeting, scope of authority, submission time, supporting instrument, verification outcome, reviewer and decision timestamp.

The approved attendance policy records the following deadlines:
- **GA proxy:** submitted at least three (3) days before the meeting.
- **Board proxy:** submitted at least three (3) hours before the meeting; this is a Board rule and must not be applied to GA proxies.

The GA proxy deadline shall be enforced only after confirmation that it is consistent with the operative Memorandum, applicable law and approved GA procedure. If a higher authority provides a different rule, the higher authority prevails and the policy must be formally amended.

A proxy shall not be accepted solely because a form was uploaded. Authority, identity, scope, validity, duplicate representation and applicable formalities must be checked. The system shall prevent an unapproved or invalid proxy from contributing to quorum or voting entitlement.

Proxy limits, permitted representative classes, revocation rules, form requirements, notarization/witnessing requirements and whether one representative may represent multiple shareholders are legal parameters to be populated from authenticated governing sources. Do not invent defaults.

## 7. Quorum

Quorum shall be computed from the legally relevant denominator and presence rules prescribed by the operative Memorandum, applicable law and approved GA procedure.

The system shall make the calculation inspectable and preserve:
- Applicable rule and source/version.
- Eligible denominator and record-date snapshot.
- Presence and representation counts.
- Voting entitlement represented, where relevant.
- Treatment of valid proxies, joint holdings, abstentions and challenged attendance.
- Calculation timestamp, operator and approving authority.
- Quorum result and any subsequent change.

The system shall not hard-code a 50% quorum for all GAs. A separate governance-election policy's half_register rule shall not be reused as a GA meeting quorum rule without explicit legal authority. Exact thresholds, denominator (persons/shares/voting rights), treatment of proxies, adjourned-meeting rules and any special resolution quorum remain blocked until verified against the controlling sources.

The chair/secretary shall not override a failed quorum calculation by manual status change. Any correction must use a reasoned, authorized and auditable process.

## 8. Notice, calendar and time management

All dates and times shall be stored as unambiguous timestamps with the relevant time zone. Ethiopian Calendar (E.C.) is the default ARNA user-facing calendar; Gregorian Calendar (G.C.) may be selected as a user preference. Conversion shall preserve the underlying instant and must not create two conflicting meeting times.

The system shall support:
- Notice preparation, approval, issuance and delivery evidence.
- Notice deadlines and recipient register.
- Calendar invitations and updates.
- Proxy, agenda-submission and document-submission deadlines.
- Configurable reminders and escalation.
- Scheduled start, actual opening, pauses, resumptions, agenda-item start/end, voting windows, adjournment and closure.
- Clock/event history that is append-only; corrections are separate events with reason and approver.

The chair or authorized meeting officer may control the live session within the approved procedure. Administrators shall not silently rewrite historical timestamps.

## 9. Agenda and meeting papers

Agenda items shall have stable identifiers, sequence, title, description, submitting authority, legal/decision category, responsible presenter, supporting documents, time allocation, status and audit history.

The workflow shall support:
- Drafting and authorized approval.
- Shareholder submissions where permitted by governing rules.
- Reordering before publication through an authorized action.
- Publication with version and delivery evidence.
- Controlled amendment after publication, including reason, authority and notice to affected participants.
- Item presentation, discussion, motion, voting, deferral and completion.
- Links to source documents and related prior decisions.

The system shall not allow an agenda item requiring special notice or special resolution treatment to proceed unless the applicable notice and procedure checks pass.

## 10. Motions, voting and resolutions

For every motion/resolution, the system shall record:
- Unique identifier and linked agenda item.
- Exact text and version put to the meeting.
- Mover and seconder where required.
- Applicable decision rule and governing source.
- Eligible voting entitlement at the time of vote.
- Voting method and opening/closing timestamps.
- Votes for, against, abstentions, invalid/challenged votes and non-votes, as applicable.
- Result calculation, threshold, outcome and certification.
- Objections, challenges, recounts and corrections.
- Supporting evidence and audit events.

Decision thresholds shall not be assumed to be simple majority for every matter. Ordinary, special, reserved, election and other decision classes must each be mapped to the correct governing rule. Secret ballot, show of hands, poll, electronic ballot and proxy vote handling must follow the verified procedure.

A resolution shall not be represented as approved until the correct threshold and quorum conditions are satisfied and the authorized result is certified. Failed, withdrawn, deferred and void resolutions must remain in the permanent record.

## 11. Penalties and financial treatment

The approved attendance policy records:
- Late arrival after the scheduled start: **ETB 50 flat**.
- Unexcused absence: **ETB 300**.
- Penalty assessment is subject to authorized review.
- Affected shareholder must have a dispute/review route.
- Dividend deduction must never occur automatically.

These amounts and rules shall be applied to GA participants only after confirmation that the policy is valid for the GA under the operative Memorandum, applicable law and approved GA procedure. The system shall distinguish a calculated penalty candidate from an approved payable liability.

Required states: candidate, pending review, approved, waived, disputed, upheld, reversed, settled and written off (where authorized).

Governance owns the assessment and decision record. Finance owns accounting recognition, receipt, settlement and reconciliation. A Governance record alone shall not post a journal entry. No dividend, shareholding or shareholder entitlement shall be reduced without a separately authorized and legally valid process.

## 12. Minutes, decisions and execution

The system shall support draft minutes, attendance register, agenda proceedings, motions, voting results, resolutions, objections, declarations, adjournments, attachments, review, approval, signature/certification and archival.

Minutes must preserve version history. Corrections after approval shall be recorded as amendments or corrigenda with authority and reason; the original approved record shall remain retrievable.

Every actionable decision shall include:
- Decision/resolution reference.
- Exact approved action.
- Responsible owner and accountable authority.
- Due date and milestones.
- Dependencies and required resources.
- Status, evidence and completion date.
- Escalation and overdue history.
- Closure approval where required.

The system shall distinguish a binding GA resolution from a recommendation, discussion note, action item or secretary's summary. Only the competent authority may certify completion or authorize a change to a resolution.

## 13. Decision authority and segregation of duties

The system shall enforce role-based and scope-based permissions, with tenant isolation and least privilege. At minimum, distinguish:
- Convening authority.
- GA chair.
- Meeting secretary.
- Shareholder / eligible voter.
- Proxy representative.
- Registration/attendance officer.
- Agenda/document custodian.
- Vote counter / election officer, where applicable.
- Legal/compliance reviewer.
- Governance approver.
- Finance reviewer / accountant.
- Records administrator.
- System administrator (technical access only).

Technical administrators shall not acquire corporate decision authority by virtue of their system role. No user shall approve their own proxy, penalty, attendance correction, financial settlement or privileged action where segregation of duties is required. All privileged actions require actor, authority, timestamp, reason and evidence.

The exact appointment, removal, delegation, quorum, chair casting vote, secretary certification and reserved-matter powers must follow the operative governing sources. The application shall not infer these powers from job titles.

## 14. Notifications and communication

Notifications shall be event-driven, tenant-scoped, traceable and deduplicated. Events include meeting authorization, notice issuance, agenda publication/amendment, proxy receipt/decision/deadline, reminders, attendance, quorum result, session changes, voting/resolution outcomes, penalty review, minutes approval, assigned actions and overdue actions.

Channels may include in-app and approved email/SMS integrations. Delivery status, retries, failures, recipient, template version and timestamps shall be auditable. Notification delivery is not proof of legally sufficient service unless the governing procedure recognizes that channel and the required delivery evidence exists.

## 15. Records, privacy, security and audit

All GA records shall be tenant-isolated, access-controlled, encrypted in transit and at rest under platform security policy, and retained according to approved retention and legal-hold rules.

The audit trail shall be append-only for material governance events. It shall capture actor, action, object, before/after state or version reference, timestamp, reason, authority and correlation identifier. Sensitive shareholder, proxy, ballot and financial information shall be disclosed only to authorized roles.

The system shall support exportable, reproducible meeting packs and audit evidence without exposing confidential ballots or personal data beyond authorized access.

## 16. Reporting and acceptance requirements

The GA dashboard and reports shall include:
- Meetings by lifecycle status.
- Notice and delivery compliance.
- Eligible electorate and voting entitlement snapshot.
- Attendance by channel and status.
- Proxy submitted/approved/rejected/expired counts.
- Quorum calculation and evidence.
- Agenda completion and time variance.
- Resolution outcomes and certification.
- Penalty candidates, approvals, disputes and settlements.
- Minutes approval and archival status.
- Decisions by owner, due date, status and overdue age.
- Notification delivery/failure.
- Audit exceptions and access events.

A1 acceptance requires that each requirement has an identifier, source/authority, owner, testable acceptance criterion and unresolved-parameter status. A2 shall produce a traceability matrix from source clauses to API, data model, UI, permissions, tests and evidence.

## 17. Legal and policy parameters that remain unresolved

The following shall remain explicitly blocked until authenticated source review:
1. Exact GA quorum threshold and denominator.
2. Quorum rules for adjourned meetings and any second call.
3. Statutory/contractual notice periods, service methods and record date.
4. Share-class voting rights and any voting limitations.
5. Proxy formality, eligible representative classes, proxy caps and revocation rules.
6. Whether and how remote attendance is legally recognized.
7. Voting methods, thresholds for ordinary/special/reserved matters and ballot secrecy.
8. Chair, secretary, convening authority and committee powers.
9. Whether attendance penalties apply to GA, their legal basis, exceptions and appeal authority.
10. Rules for agenda additions, shareholder proposals and special-notice items.
11. Minutes approval, signatures, certification and filing obligations.
12. Retention, disclosure and statutory reporting requirements.

No production workflow shall activate a parameter marked unresolved where it affects validity, rights, quorum, voting, liability or statutory compliance.

## 18. A1 freeze and change control

This version freezes the functional scope, authority hierarchy, safeguards, lifecycle and required data/evidence model. It does not falsely certify unresolved legal values.

A1 changes after this baseline require a documented change request identifying the affected requirement, reason, source authority, legal impact, backward compatibility and Owner approval. A2 may clarify implementation contracts but may not silently alter this baseline. Any newly discovered legal conflict returns only the affected parameter for controlled resolution; it does not reopen unrelated frozen requirements.

## 19. Source register

The following source documents must be attached or referenced by immutable repository path and verified version during A2:
- Operative Memorandum and Articles of Association, including amendments.
- Approved General Assembly and Board Meeting Rules of Procedure.
- Valid GA resolutions approving or amending those rules.
- Approved ARNA attendance and penalty policy: ARNA-GOV-POL-MEETING-ATTENDANCE-001.
- Legal reconciliation memorandum: ARNA-GOV-LEGAL-MEMO-MEETING-ATTENDANCE-001.
- Applicable Ethiopian company law and regulations, as reviewed by competent counsel.

**Repository note:** This document is the A1 requirements baseline. It is not evidence that the separate meeting-foundation branch has been merged, that migrations are reconciled, or that the system is deployed or production-verified.
