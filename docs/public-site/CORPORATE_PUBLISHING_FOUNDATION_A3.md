# ARNA Corporate Publishing Foundation — A1/A2 Reconciliation and A3 Contract

**Status:** A3 implementation branch. Not merged or deployed.

## Reconciled architecture

- The existing `PublicContent` domain remains the contribution and moderation workflow for externally submitted editorial contributions. It is not replaced.
- `CorporateSitePage` owns first-party corporate pages and homepage composition. It supports Amharic and English slugs, localized translations, structured sections, localized SEO metadata, versioned drafts, independent review, approval, publication, unpublication and scheduled publication.
- `CorporateSiteEntry` owns moderated editorial and directory records: `news`, `announcement`, `publication`, `media`, `career`, `opportunity`, `business`, `service`, `advertisement`, and `marketplace`.
- `CorporateSiteInquiry` owns private contact, investor, partnership, media, career, advertising and marketplace inquiries. It is not public-readable.
- Organization units remain in the Organization domain and tenant-scoped. The public site does not expose internal organization rows directly. Public organizational structure must be authored as reviewed corporate pages or published business records until a separately authorized public-disclosure projection is specified.

## Publication invariants

1. Public read endpoints return published records only.
2. Every CMS mutation is version checked; stale writes return HTTP 409.
3. A page author cannot approve their own page. Bilingual page approval requires Amharic and English title/slug pairs.
4. Publishing requires approved status. Scheduled publishing runs every minute and writes audit events.
5. Public submissions enter draft/pending moderation and are never immediately published.
6. Media is stored on the private local disk under `corporate-quarantine`. It is not served publicly until it is published.
7. Media publication is blocked unless the scanner has returned a clean verdict. Scanner integration is fail-closed.
8. Administrative routes require Sanctum authentication and the existing `public-content.review` permission. Inquiry content is only exposed through those protected routes.
9. Contact and submission endpoints are rate limited; the contact form includes a honeypot field.

## API surface

### Public
- `GET /api/public/site/home?locale=am|en`
- `GET /api/public/site/pages?locale=am|en` — published page navigation.
- `GET /api/public/site/pages/{locale}/{slug}`
- `GET /api/public/site/content/{type}?locale=am|en`
- `GET /api/public/site/content/{type}/{slug}?locale=am|en`
- `GET /api/public/site/media/{publicId}/asset`
- `GET /api/public/site/sitemap.xml`
- `POST /api/public/site/inquiries`
- `POST /api/public/site/submissions/{type}`
- `POST /api/admin/corporate-site/media` — authenticated, permission-gated media upload into quarantine.

### Protected administration
- `/api/admin/corporate-site/pages` — list/create/update and approve/publish/schedule/unpublish.
- `/api/admin/corporate-site/entries` — list/create/update, scan media, approve/publish/reject/unpublish.
- `/api/admin/corporate-site/inquiries` — list and versioned status/assignment/internal-note updates.

## Required environment configuration

- `CORPORATE_SITE_URL`: canonical public website origin used by sitemap generation.
- `CORPORATE_MEDIA_SCANNER_URL`: trusted HTTPS scanner endpoint. It must accept multipart `file` and `reference`, and return JSON with `status` equal to `clean` or `infected`. A missing, non-HTTPS, unavailable or invalid scanner fails closed.
- `CORPORATE_MEDIA_SCANNER_TOKEN`: bearer token for the scanner endpoint.

No credentials or production values are stored in source control.

## Frontend

- Public homepage consumes published CMS homepage sections and localized news, business, service, career and marketplace records.
- Public corporate pages and editorial details are locale-aware and apply SEO title, description, canonical and robots metadata where supplied.
- Contact and opportunity/advertising/marketplace submission forms are connected to the API.
- Admin corporate publishing workspace provides page authoring/editing, review/publication controls, editorial authoring/moderation, media scan action and contact inquiry triage.

## A3 scope boundaries and dependencies

- The public structure/business content is publication-managed and is not a direct view of tenant-private Organization rows.
- Legal entity and subsidiary ownership claims must be verified against authoritative Organization/Finance records before being published.
- Media scanning requires the operator to configure a trusted scanner service. Until configured and returning a clean verdict, media stays quarantined.
- Delivery of email notifications, analytics/ad billing, advertiser payment processing, marketplace checkout, job-application document handling, and external search indexing are not part of this publishing foundation.
- Production storage/CDN, DNS, SMTP, scanner credentials and runtime scheduler must be configured in the deployment environment; no production deployment is included in A3.

## A4 evidence required

Run API clean-install migration and feature tests on MySQL, run frontend type-check/build/public-entry smoke tests, verify the scanner's clean/infected/unavailable paths, verify tenant/privacy boundaries, then conduct keyboard/screen-reader/mobile/tablet/desktop review. A3 code delivery alone is not A4 acceptance.
