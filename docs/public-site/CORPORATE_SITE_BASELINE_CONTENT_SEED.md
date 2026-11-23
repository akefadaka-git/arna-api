# Corporate website baseline content — controlled seed

**Purpose:** provide the first bilingual corporate page set for the public-site CMS.
**Repository:** `akefadaka-git/arna-api`
**Seeder:** `database/seeders/CorporateSiteBaselineSeeder.php`
**Execution:** manual, after the lifecycle migration has been applied:
`php artisan db:seed --class=CorporateSiteBaselineSeeder`

## Safety properties

- The seeder inserts only missing page keys. It never overwrites an existing page or administrator edit.
- Every inserted page is `draft`, version 1, and unpublished. No content is approved or made public by the seeder.
- A different authorized reviewer must approve each version before publication.
- The homepage includes the permanent bilingual Community Responsibility section.
- Amharic and English copy are stored independently. English is an editorial rendering and must receive language review before publication.
- The source map distinguishes bylaw-stated corporate purposes from current operations.

## Included page set

1. Home — corporate identity, headquarters, vision, mission, bylaw purposes, values, Community Responsibility, governance and public-disclosure principles.
2. About — company identity and bylaw-stated corporate purpose.
3. Vision, Mission and Values — Owner-provided corporate statements, explicitly not represented as bylaw quotations.
4. Business Areas — categories listed in the bylaws, with a clear notice that these do not prove current operations.
5. Governance and Leadership — general Board oversight description; no unverified director names or biographies.
6. Community Responsibility — permanent institutional priorities; no fabricated active projects, partners, budgets or outcomes.
7. Public Disclosures — evidence rules for registration, licences, permits, financial statements and government-body relationships; no endorsement claim.
8. Contact — verified head-office address and the existing website inquiry form; no invented phone number or email address.

## Deliberately withheld pending authoritative evidence

- Exact incorporation/registration number and date.
- Named current directors, biographies, board terms and committee appointments.
- Government-body names or relationships where a current official record has not been verified.
- Licences, permits, certificates, government endorsements and procurement/contract claims.
- Audited financial statements, revenue, profitability, growth and other financial metrics.
- Active subsidiaries, operational business lines, products, services, staff counts, projects, partners, beneficiaries and impact figures.
- Direct telephone numbers, email addresses and social accounts not verified from company-controlled records.

## A4 content checks

- Verify every seeded page exists once and remains draft after seeding.
- Confirm the homepage invariant is satisfied in both languages.
- Confirm public routes do not return any seeded page before approval and publication.
- Review Amharic/English meaning parity, typography, links, headings and SEO metadata.
- Approve and publish pages individually only after Owner content review and an independent reviewer action.

This file is an implementation record, not a claim that the seeder has been executed in any environment.
