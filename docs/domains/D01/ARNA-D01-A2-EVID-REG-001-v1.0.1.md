# ARNA D01 — A2 Evidence Register Successor Addendum

- **Record ID:** ARNA-D01-A2-EVID-REG-001
- **Version:** 1.0.1
- **Record type:** Controlled successor addendum to v1.0.0
- **Stage:** A2 — read-only evidence reconciliation
- **Disposition:** **PARTIAL — NOT VERIFIED-CLOSED**
- **Prepared:** 2026-11-24
- **Repository:** `akefadaka-git/arna-api`
- **Branch:** `docs/arna-d01-a2-evidence-register`
- **Parent record:** `ARNA-D01-A2-EVID-REG-001 v1.0.0`
- **Parent commit:** `2b687b8c97b4ea13e2904572adfa82e8b1fb8140`
- **Parent blob:** `fe0dddff1268e5b1d7e8c19c4b3012cd1c7c4920`

## 1. Purpose and change control

This addendum records the Owner's clarification received on 2026-11-24. It supplements, and does not overwrite, v1.0.0. The v1.0.0 baseline remains preserved as the original A2 reconciliation snapshot.

This is a documentation-only A2 correction. It does not change application code, database schema, migrations, seeds, permissions, production data or configuration. It does not authorize implementation, merge, A4 verification or A5 deployment.

## 2. Owner-confirmed company identity

The Owner identifies the company as **Maale Arna Share Company** and confirms these corporate identifiers:

| Identifier | Owner-confirmed value |
|---|---|
| Commercial registration number | `SE/TMDB/3/0056558/2017` |
| Licence number | `SE/TMDB/06/586/2017` |

These identifiers are recorded as Owner-confirmed statements. This addendum does not represent an independent registry lookup or certify the current status of the registration or licence.

## 3. Memorandum and Articles — source-status correction

The Owner expressly confirms that the company's **Memorandum of Association (MoA)** and **Articles of Association (AoA)** were already provided in earlier interactions. The Owner has directed that no duplicate upload or request for those documents be made in this chat.

For the evidence register, the correct treatment is:

- Record the MoA and AoA as **Owner-confirmed previously supplied source documents**.
- Do not mark them as absent merely because the binary originals are not currently attached to this conversation or surfaced by the latest Library search.
- Do not require the Owner to upload them again here.
- Do not infer that the 17-page bylaws/internal-regulations scan is the MoA or AoA.
- Do not infer that the 22-page DOCX is the registered MoA/AoA or controlling instrument solely from its title or text.
- Preserve the 17-page scan and 22-page DOCX as separately identified records pending reconciliation of their relationship to the MoA/AoA and the company's registered record.

The earlier v1.0.0 wording that treated the separate registered MoA/AoA as unavailable for direct inspection is superseded only to the extent that it implied the Owner had not supplied them. The current evidentiary limitation is narrower: this addendum does not reproduce or independently re-authenticate the original MoA/AoA bytes, registration endorsements, or complete source metadata. The Owner's confirmation is not to be misrepresented as independent certification.

## 4. Additional GA/Board evidence and server upload route

The Owner states that the remaining GA/Board records will be uploaded to the server, not to this chat. The evidence retrieval plan must therefore use the server-hosted records when made available through an authorized, accessible route.

No further upload to this conversation is requested. No GA/Board resolution, current Board appointment, executive appointment, strategy adoption or delegation is deemed verified merely because the Owner has stated that records will be uploaded.

When the records become accessible, reconcile at minimum:
1. GA and Board resolution/minute identifiers, dates and adopted text;
2. meeting notice, quorum, attendance, voting and signature evidence where applicable;
3. strategy approval authority and the resolution adopting the operative strategy baseline;
4. current Board composition, appointment/election dates, terms and any removal/replacement decisions;
5. current executive appointment, effective date, delegated authority and reporting line;
6. amendments, supersession and consistency with the registered MoA/AoA and controlling bylaws.

## 5. Revised disposition of relevant evidence questions

| Evidence question | Revised status | Basis / limitation |
|---|---|---|
| Company identity and registration number | **OWNER-CONFIRMED** | Registration number supplied directly by Owner; independent registry validation not performed in this addendum |
| Licence number | **OWNER-CONFIRMED** | Licence number supplied directly by Owner; current validity not independently checked |
| MoA and AoA previously supplied | **OWNER-CONFIRMED — SOURCE IDENTIFIED BY OWNER** | Do not request duplicate chat upload; preserve exact source identity and provenance limits |
| 17-page bylaws scan identity | **SEPARATE SOURCE — NOT TO BE RELABELLED** | Remains the separately recorded bylaws/internal-regulations scan |
| 22-page DOCX identity and authority | **UNRESOLVED** | Do not substitute it for registered MoA/AoA or merge its schedule with the scan |
| GA/Board strategy-approval records | **AWAITING SERVER ACCESS** | Owner will upload to server; no approval or appointment inferred before review |
| Current Board and executive appointments | **AWAITING AUTHORITATIVE RECORDS** | Must be checked against adopted resolutions and effective dates |

## 6. Gate decision

**D01 A2 remains PARTIAL — NOT VERIFIED-CLOSED.**

The Owner's clarification resolves the process issue that the MoA/AoA should not be requested again in this chat and supplies the registration and licence identifiers for the record. It does not by itself establish the exact source-byte identity, authenticate registry endorsements, prove current licence validity, identify the operative strategy-approval resolution, or verify current appointments.

The strategy approval workflow remains **FAIL-CLOSED** until the relevant authority and adopted records are reconciled. A3 implementation remains unauthorized by this addendum. No merge, deployment or production change is authorized.

## 7. Immediate next action

Await the Owner's server upload of GA/Board records and obtain authorized read access to the relevant server location. Then perform a read-only evidence reconciliation and issue the next controlled register version with exact file identifiers, hashes, dates, resolution references and findings. Do not ask the Owner to re-upload the MoA/AoA into this chat.

**End of v1.0.1 successor addendum.**
