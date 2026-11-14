# Community Responsibility — Public CMS Governance

**Applies to:** Maale Arna Share Company public corporate website and its bilingual homepage CMS.

## Permanent public section

The Community Responsibility section is a permanent corporate section. It must remain visible in Amharic and English, including when there are no approved project updates.

When no approved projects are available, the public page presents only these corporate priorities:

- Education and skills development
- Community health and well-being
- Environmental care
- Local economic opportunity, enterprise and sustainable livelihoods

These priorities are corporate focus areas. They must not be represented as funded programs, active partnerships, completed projects, or measured outcomes without separate approval and documentation.

## Project publication contract

Community Responsibility project records belong in the localized `projects` array of the `community_responsibility`, `community-responsibility`, or `responsibility` section.

A project is eligible for public output only when all of the following are true:

1. `approval_status` is exactly `approved`.
2. `evidence_reference` is a non-empty reference to retained supporting documentation.
3. The complete homepage version is approved by an authorized reviewer other than its editor/author.
4. The project description and each optional partner, budget, or outcome claim are supported by the retained evidence and fall within the approval.

The API removes `approval_status` and `evidence_reference` from public project objects and adds `public_approved: true` only after the server-side gate passes. Internal evidence references are not exposed publicly.

Project-like cards must not be inserted into the Community Responsibility `items` array. That array is reserved for entries with `kind: priority`; all other entries are excluded from the public response. Use `projects` for project claims.

## Publication controls

- Page edits return the page to draft and clear its previous approval/publication state.
- The page author cannot approve their own page.
- Both Amharic and English titles and slugs are required before approval.
- The Community Responsibility section and bilingual titles are revalidated on homepage creation, update, approval, publication, and scheduling.
- Public APIs expose published records only.
- Draft, rejected, unapproved, or undocumented projects must never be presented as actual initiatives.
- Do not publish unapproved budgets, partner names or logos, beneficiary personal information, impact figures, or outcome claims.
- Do not include religious giving, church contributions, tithing, or personal religious obligations in the company's public Community Responsibility content.

## CMS authoring checklist

Before approving a homepage version, the independent reviewer confirms:

- The permanent section exists in both locales.
- Priority cards describe focus areas, not claimed achievements.
- Every project has a retained evidence reference and explicit approval.
- Partner, budget, delivery, and outcome details are individually supported.
- No private beneficiary data or unapproved branding is exposed.
- If there are no qualifying projects, the priorities remain visible and the projects list is empty.

This policy governs public content. It does not authorize or record a project, allocate a budget, establish a partnership, or make a claim about completed impact.
