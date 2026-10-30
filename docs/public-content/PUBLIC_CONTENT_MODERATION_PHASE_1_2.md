# ARNA Public Content Moderation — Phase 1.2

## Scope

This domain owns public submissions, editorial review, approval, publication, and the public read surface. It is platform-wide corporate content, not tenant-owned operational data. It does not introduce a second identity system, CMS, or database.

Supported content types: `news`, `article`, `announcement`, `press_release`.

## Lifecycle

```text
submission
  └─ pending_review
       ├─ approve ──> approved ── publish now ──> published
       │                        └─ schedule ────> scheduled ── due scheduler ──> published
       ├─ request-changes ──> changes_requested ──> re-review
       └─ reject ───────────> rejected

published ── unpublish ──> unpublished

Any material administrator edit increments version, clears prior review metadata,
removes scheduled/published timestamps, and returns the record to pending_review.
```

A public submission is never assigned an approved or published state from client input. The model also forces `pending_review` on creation.

## API contract

### Public routes

- `GET /api/public/content` — published records only; optional `type` and bounded `per_page`.
- `GET /api/public/content/{publicId}` — published record by opaque UUID only.
- `POST /api/public/content/submissions` — anonymous or authenticated submission; 5 requests per 10 minutes per source.

Submission fields: `content_type`, `title`, `body`, `author_name`, required `author_email`, and accepted `consent`. The server stores consent time. The reviewer identity check compares both the authenticated submitter ID and the contributor contact email with the reviewer account. A guest-provided email is a contact identifier, not cryptographic proof of identity; a later verified-contributor flow is required if the business needs stronger identity assurance for anonymous authors. A non-empty honeypot field is silently discarded without creating a record.

Public resources do not expose author email, submitter identity, reviewer identity, internal status, review notes, or internal numeric IDs.

### Admin routes

All routes require `auth:sanctum` and the global permission `public-content.review`. They do not require a tenant context because this is a platform-wide publication function.

- `GET /api/admin/public-content` — permission-gated review queue with optional status/type filters.
- `PUT /api/admin/public-content/{id}` — version-checked content edit; always requeues for review.
- `POST /api/admin/public-content/{id}/approve`
- `POST /api/admin/public-content/{id}/reject`
- `POST /api/admin/public-content/{id}/request-changes`
- `POST /api/admin/public-content/{id}/publish` — requires prior approval; optional future `scheduled_at`.
- `POST /api/admin/public-content/{id}/unpublish`

Every decision and edit requires `expected_version`. A stale version returns HTTP 409. Rejection and change requests require a reason. The backend blocks moderation when the submitter account or contributor contact email matches the reviewer account. Guest email is not independently verified in this phase, so it is a contact identifier rather than cryptographic identity proof.

## Authorization provisioning

The permission catalogue contains `public-content.review` with global scope. Existing role-grant rules provide global-scope permissions to `platform_admin`; `super_admin` receives it through its existing wildcard grant. Other roles do not receive publication authority by default.

The permission is provisioned by the existing PermissionSeeder / RolePermissionSeeder sequence. Deployment must run the normal approved seeding process after migration; do not grant the permission by frontend configuration.

## Audit

Submission, approval, rejection, request-changes, publication, unpublication, scheduled publication, and material edits append records to the existing `audit_logs` table. Audit metadata records the public UUID; review decisions record old/new status and version; material edits also record changed metadata and before/after body hashes without duplicating full body text. The domain does not mutate or delete audit rows.

## Scheduling

`public-content:publish-scheduled` publishes only records still in `scheduled` state whose schedule time has arrived. It locks each record, rechecks eligibility, and writes an audit row. Laravel schedules it every minute with overlap prevention. Production must run Laravel's scheduler using the existing cron entry:

```sh
* * * * * cd /path/to/arna-api && php artisan schedule:run >> /dev/null 2>&1
```

## Security and operational constraints

- Public reads are a strict published-only query; drafts and moderation metadata are never serialized publicly.
- Body content is returned as text, not trusted HTML. Consumers must render it as text or apply a separately reviewed sanitization/rendering pipeline.
- Submission rate limiting and a honeypot provide baseline abuse controls. Add edge/WAF controls and monitoring before broad public promotion.
- The public site must use the configured HTTPS API origin and the API CORS allow-list must include `https://www.maalearna.com.et`.
- No advertiser/campaign lifecycle is included here. Third-party ads require a separate advertiser identity/approval, campaign review, placement, billing/consent, and reporting contract; do not reuse editorial approval as an advertising approval.
- No database migration, seeding, or scheduler has been executed against production by this PR.
