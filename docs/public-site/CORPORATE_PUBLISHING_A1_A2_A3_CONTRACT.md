# ARNA Corporate Publishing Platform — A1/A2 Reconciliation and A3 Contract

Status: A1 reconciled; A2 contract baseline. Implementation branch: feat/public-corporate-platform.

## Boundary and reuse decisions

The existing PublicContent domain remains the canonical editorial submission, review, approval, publication, scheduling and audit subsystem. It is platform-wide, not tenant-owned. Preserve its published-only public reads, immutable contributor identity, version-checked decisions, self-review prevention, audit append-only behavior and existing permission public-content.review.

This platform extends corporate publishing; it does not replace ARNA Organization, People, Documents, Finance or other domain records. Organization units and legal entities remain authoritative in their domains. Corporate-site records may reference those entities, but must not duplicate their authoritative identity or ownership data.

## Publishing capabilities

1. Corporate pages and homepage composition: editable page metadata, ordered reusable sections, draft/review/approved/scheduled/published lifecycle, preview, version conflict protection and revision history.
2. Corporate profile: About, mission, vision, values, governance/organizational structure, business units, subsidiaries, services and public contact points. Dynamic content must be published only after approval.
3. Editorial: news, announcements, articles, press releases and publications; reuse PublicContent moderation.
4. Media: media-library metadata, image/video/document assets, alt text, captions, credits, focal point, locale metadata and usage references. Uploads require type/size validation, private quarantine, malware scanning before public delivery, and immutable storage identifiers. Do not expose private storage paths.
5. Careers: vacancies/opportunities, department/location, employment type, opening/closing dates, application instructions/URL, status and audit. Applications must be a separate protected workflow and must not be exposed in public listing responses.
6. Contact: public inquiry intake with validation, rate limiting, honeypot/abuse protection, privacy notice acknowledgement, reference ID and protected staff queue. Never expose inquiry payloads publicly.
7. Advertising and marketplace: separate advertiser identity and verification, listing/campaign submission, moderation, placement/slot, scheduling, expiry, consent/targeting constraints, billing/reporting integration points and audit. No public self-publication; do not reuse editorial approval as ad approval.
8. Localization: Amharic (am) and English (en) content stored independently by locale; explicit fallback policy, locale-aware slugs and metadata. Missing translation must be visible to editors and must not silently publish fabricated translations.
9. SEO: per-locale title, description, canonical URL, robots directives, social preview metadata, sitemap eligibility and structured-data type. Validate canonical URLs and disallow draft/noindex leakage.
10. Accessibility/responsive: semantic landmarks, keyboard operation, visible focus, skip links, alt text, caption/transcript support, contrast and reduced-motion support; WCAG 2.2 AA acceptance review across mobile/tablet/desktop.

## Security and lifecycle

- Public reads return only published records whose publication window is active.
- All admin endpoints require Sanctum and explicit global permission grants; frontend route guards are not authorization.
- Mutations require expected_version and append audit records; stale writes return 409.
- Editorial review stays within PublicContent. CMS page/section, careers, inquiry, media and advertising have explicit lifecycle and permission boundaries.
- Uploads are private until validated and approved. Do not render arbitrary HTML; use a reviewed allowlist sanitizer or structured rich-text schema.
- Validate all inputs server-side; rate-limit anonymous writes; protect against CSRF, XSS, SSRF, IDOR, mass assignment and file upload attacks.
- No production migration, seed, scheduler, storage exposure or deployment is authorized by this implementation task.

## A2 API contract baseline

Public read surface:
- GET /api/public/site/home
- GET /api/public/site/pages/{locale}/{slug}
- GET /api/public/site/navigation/{locale}
- GET /api/public/site/media/{publicId} (published asset metadata only)
- GET /api/public/site/careers/{locale}
- POST /api/public/site/inquiries

Admin surface (auth:sanctum + scoped global permission):
- CMS page/section CRUD, preview, submit-for-review, approve, reject, schedule, publish, unpublish.
- Media upload-intent/finalize, metadata edit, approve and retire.
- Career opportunity CRUD and lifecycle.
- Inquiry queue, assignment, status transitions and internal notes.
- Advertiser verification, campaign/listing submission, review, placement, schedule, expiry and reporting.

All public resources are locale-aware and omit internal IDs, draft state, review notes, contributor PII, internal storage paths and unpublished assets. Admin resources include version and audit context only for authorized reviewers.

## A3/A4 delivery gates

A3: implement migrations/models/services/policies/controllers/routes/seeders/tests in API and typed API clients/pages/locale-aware public routes/admin authoring surfaces in App. Preserve unrelated changes and existing main.
A4: run API unit/feature tests, migration rollback/forward tests on supported MySQL, route authorization matrix, frontend type-check and public build smoke, locale/SEO/accessibility/responsive checks, and security review. Record exact SHA and run links. If CI is blocked, mark the relevant checks NOT RUN and do not claim closure.
A5: prepare deployment plan, migration/seed/scheduler/storage/CORS/backup/rollback checklist and release evidence only. Production deployment remains a separate explicit approval.
