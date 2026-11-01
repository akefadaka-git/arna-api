# ARNA Communication Hub — Announcement Contract

Status: implementation candidate on `feat/communication-hub-announcements`.

## Scope

This slice delivers tenant-scoped official announcements and connects publication to the existing Platform Notification service. It does not replace that service and does not yet implement direct/group chat, email/SMS delivery, attachments, comments, or public-site publication.

## Authority and lifecycle

`draft → pending_approval → approved → published`

- `announcement.view`: frontend navigation capability for the announcement workspace; recipient API reads remain restricted to published records addressed to the caller.
- `announcement.manage`: create and submit drafts.
- `announcement.approve`: approve a submitted announcement.
- `announcement.publish`: publish an approved announcement internally.

All four capabilities are registered in `Database\\Seeders\\Data\\PermissionCatalogue` as tenant-scoped, non-system permissions. The canonical role-grant matrix assigns view to manager, secretary and president; manage to secretary; approve and publish to president. Existing `platform_admin` and `tenant_admin` roles receive tenant-scoped capabilities through their existing `scope:tenant` grant, while `super_admin` retains its existing wildcard. No individual user or production role assignment is performed by this branch.
- Submitter and approver must be different users.
- A user cannot publish unless the record is approved.
- Public publication is not implemented.
- Draft creation requires an explicit list of recipient user IDs. No implicit tenant-wide broadcast exists.

These permission slugs must be registered and assigned through the established ARNA authorization/role administration process before users can perform the corresponding actions. This change does not grant permissions to any role or user.

## Tenant and recipient isolation

Every route is inside `auth:sanctum` and `tenant.membership`. The controller additionally resolves a required tenant context and constrains all announcement reads and mutations by that tenant. Recipients must have active membership when selected and again at publication. The notification service independently verifies recipient membership before delivery.

The recipient list is explicit and capped at 1,000 IDs per announcement. A user without `announcement.manage` sees only published announcements for which they are a recipient.

## API

All endpoints are under `/api/announcements`:

| Method | Path | Permission / behavior |
| --- | --- | --- |
| GET | `/` | Tenant-scoped list; managers see tenant records, recipients see their published records |
| POST | `/` | `announcement.manage`; creates draft and explicit recipients |
| POST | `/{id}/submit` | `announcement.manage`; draft → pending approval |
| POST | `/{id}/approve` | `announcement.approve`; different user from submitter |
| POST | `/{id}/publish` | `announcement.publish`; approved → published; creates central notifications |
| POST | `/{id}/acknowledge` | Recipient-only acknowledgement when required |

Responses use the existing `{ success, data }` API envelope.

## Persistence

- `communication_announcements`: tenant, author, bilingual title JSON, body, priority, lifecycle, approval/publication metadata, acknowledgement requirement and expiry.
- `communication_announcement_recipients`: explicit recipient, tenant, delivery/read/acknowledgement timestamps.

The migration is source code only until it is applied through the separately authorized database migration process. No database has been modified by this branch.

## Frontend

The workspace route is `/announcements`, guarded by `announcement.view`. The composer creates drafts and requires comma-separated user IDs; it does not publish. Separate controls submit, approve, and publish according to server-side permissions. Amharic-first labels and English title support are included.

## Verification

- Canonical permission catalogue and role-grant matrix include the four announcement permissions.
- Backend feature tests cover tenant isolation, active-recipient validation at creation and publication, lifecycle transitions, self-approval rejection, explicit recipient delivery, acknowledgement, and notification atomicity.
- Add frontend type-check/build verification and API contract tests.
- Verify MySQL migration up/down on a clean database and against the supported existing schema.
- Verify acknowledgement reporting and notification deep-link behavior.
- Do not merge, deploy, or publish publicly until A4 evidence and separate A5 authorization are recorded.
