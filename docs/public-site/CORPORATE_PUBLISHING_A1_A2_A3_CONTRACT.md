# ARNA Corporate Publishing Platform — A1/A2 Reconciliation and A3 Delivery

**Status:** A1 reconciliation and A2 contract established. A3 foundation implemented on `feat/public-corporate-platform`. A4 verification is separate.

## A1 findings and reuse

- The existing `PublicContent` domain remains intact for contributor submissions, email verification and moderation. The corporate CMS does not replace it.
- Organization units and legal entities remain authoritative in their domains. The public site must not query tenant-private Organization rows directly or claim legal ownership without a reviewed public disclosure.
- The App public build is static Vite output; public content is fetched from the API. Public reads therefore remain API-driven and published-only.

## A2 architecture decisions

- `CorporateSitePage`: first-party corporate pages and homepage sections, Amharic/English slugs and translations, localized SEO, versioned draft/review/approved/scheduled/published/unpublished lifecycle.
- `CorporateSiteEntry`: moderated public records for news, announcements, publications, media, careers, opportunities, businesses, services, advertisements and marketplace listings.
- `CorporateSiteInquiry`: private contact/investor/partnership/media/career/advertising/marketplace inquiry records with consent timestamp, status, assignment, internal notes and version.
- Reuse the existing `public-content.review` permission for the platform-wide corporate publishing workspace; enforce it server-side as well as in the App route.
- No production migration, seed, scheduler activation, storage exposure or deployment is included.

## A3 implementation delivered

### Persistence and API
- Two migrations create `corporate_site_pages`, `corporate_site_entries` and `corporate_site_inquiries`.
- Eloquent models, bilingual published-only page/entry reads, homepage/page navigation, detail routes and dynamic sitemap generation.
- Page create/update/review/publish/schedule/unpublish endpoints with optimistic version checks, author/reviewer separation and audit events.
- Entry create/update/moderation endpoints with optimistic version checks and audit events.
- Contact inquiry intake with validation, rate limiting, honeypot, required consent and stored consent timestamp; protected queue/status updates with version checks and audit events.
- Media upload is protected by the admin permission, private local quarantine, MIME/size validation, upload audit and orphan-file cleanup. Scanner integration is fail-closed and requires a configured trusted HTTPS scanner before publication.
- Scheduled page publication command is registered every minute and audited.
- Public routes return only published content; inquiry payloads and private asset paths are not part of public resources.

### API surface
Public:
- `GET /api/public/site/home?locale=am|en`
- `GET /api/public/site/pages?locale=am|en`
- `GET /api/public/site/pages/{locale}/{slug}`
- `GET /api/public/site/content/{type}?locale=am|en`
- `GET /api/public/site/content/{type}/{slug}?locale=am|en`
- `GET /api/public/site/media/{publicId}/asset`
- `GET /api/public/site/sitemap.xml`
- `POST /api/public/site/inquiries`
- `POST /api/public/site/submissions/{type}`

Protected administration (Sanctum + `public-content.review`):
- `/api/admin/corporate-site/pages` — list/create/update/review/publish/schedule/unpublish.
- `/api/admin/corporate-site/entries` — list/create/update/review/publish/reject/unpublish.
- `POST /api/admin/corporate-site/media` — quarantined media upload.
- `POST /api/admin/corporate-site/entries/{id}/scan` — trusted scanner verdict.
- `/api/admin/corporate-site/inquiries` — private inquiry queue and versioned triage.

### Runtime configuration
- `CORPORATE_SITE_URL`: canonical public website origin used in sitemap URLs.
- `CORPORATE_MEDIA_SCANNER_URL`: trusted HTTPS malware-scanner endpoint accepting multipart `file` and `reference`, returning `status=clean|infected`.
- `CORPORATE_MEDIA_SCANNER_TOKEN`: scanner bearer credential.
- Missing or unavailable scanner keeps media quarantined.

## Explicit A3 limitations (not to be reported as delivered)

- No direct public projection of OrganizationUnit/legal-entity records; public structure/business profiles must be curated in the CMS until a separately approved disclosure projection exists.
- No external email notification delivery, advertiser billing/payment, campaign analytics, marketplace checkout, application document upload or full applicant tracking workflow.
- Media scanner contract is implemented, but a production scanner endpoint and credentials must be supplied by the operator; until then no media can pass scanning.
- SEO metadata and sitemap are API-driven. Static hosting must be configured to expose/proxy the sitemap and use the public domain as canonical origin.
- No SSR/prerendering or third-party search-index integration is included.

## A4 acceptance

- Clean-install MySQL migration gate verifies all three corporate tables and migration count.
- Corporate publishing feature tests verify bilingual published-only reads, draft isolation and private inquiry intake/consent.
- App type-check, four application builds, public build smoke and route/locale checks.
- Scanner clean/infected/unavailable tests; authorization and private-data exposure review.
- Manual keyboard/screen-reader/WCAG 2.2 AA, contrast, reduced-motion and responsive mobile/tablet/desktop acceptance.
- Exact final SHA and run links must be recorded. Any unavailable gate is marked NOT RUN/UNRESOLVED, not PASS.
