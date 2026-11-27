# ARNA AI A1 — Amharic-first Language Architecture

**Document ID:** ARNA-AI-A1-005  
**Version:** 1.0.0  
**Status:** OWNER DIRECTION CONFIRMED; DETAILED CONTRACT PROPOSED

## 1. Language policy

- Amharic is the default language for ARNA AI interaction and user-facing assistance.
- English is a supported alternative.
- Ge'ez Unicode is a foundational script requirement.
- Maale and additional Ethiopian languages are future expansion targets only after data readiness, linguistic review and measured evaluation. No current model capability is presumed.
- Language selection must not alter authorization, record meaning, legal effect or accounting rules.

## 2. Unicode and script handling

- Use valid Unicode text end-to-end; do not transliterate Amharic as a storage workaround.
- Preserve source text exactly where legally or operationally important.
- Support Ethiopic code points, combining marks, punctuation, mixed Ethiopic/Latin text and numerals.
- Normalize for search only in a derived representation; preserve original source bytes/text.
- Avoid lossy normalization, silent transliteration or destructive punctuation changes.
- Ensure fonts and rendering support the required Ethiopic glyph coverage in target browsers and devices.

## 3. Localization behavior

- The ARNA App's existing Amharic/English locale contract remains authoritative; AI UI must integrate with it rather than create a separate locale store.
- Amharic is the default language for AI prompts, responses and generated drafts when the user has not selected another supported language.
- Respect the user's explicit language selection and the source document's language.
- Keep quoted source text in its original language unless translation is requested.
- Label machine translations as translations; preserve links to original text.
- Do not treat a translation as an authoritative replacement for a signed or adopted source.

## 4. Ethiopian and Gregorian calendar

- Ethiopian Calendar is the default user-facing calendar priority.
- Gregorian Calendar remains supported for interoperability.
- Reuse ARNA Core's canonical Ethiopian calendar conversion; do not implement a second conversion engine in AI.
- Persist canonical, unambiguous dates/timestamps and explicit calendar identifiers where required by the owning API.
- Distinguish all-day civil dates from timed instants; preserve timezone context for timed events.
- Do not silently reinterpret a Gregorian source date as an Ethiopian date.
- Calendar, Finance and other domain-specific fiscal/period rules remain owned by their domains.

## 5. Numbers, currency and units

- Preserve source numerals and formatting where needed for faithful quotation.
- Support Amharic and Arabic/Western digit display as locale preferences only where the source and domain contract permit.
- Currency codes and values must be explicit; ETB is not assumed for every legal entity or foreign-currency transaction.
- Never use model-generated arithmetic for authoritative accounting, money movement or ledger posting.
- Use deterministic domain APIs for calculations and validation.
- Dates, quantities, percentages and monetary values must retain machine-readable canonical representations and clear display context.

## 6. Document intelligence and OCR

For each OCR/extraction result, retain:
- original file reference and version;
- detected language/script and page number;
- extracted text and confidence by field or region where supported;
- bounding boxes or location metadata where available;
- engine/model and version;
- correction history and reviewer attribution.

Low-confidence names, amounts, dates, legal references, identity numbers and financial fields must be flagged for human verification. OCR output is not proof of identity, legal validity, payment or approval.

## 7. Language evaluation

Maintain separate, versioned Amharic and English evaluation sets covering:
- user intent and task completion;
- factual grounding and citation correctness;
- domain terminology and corporate vocabulary;
- dates, calendar conversion and timezone interpretation;
- numbers, amounts, currencies and units;
- mixed-script text and named entities;
- translation fidelity and preservation of legal meaning;
- refusal, uncertainty and prompt-injection behavior;
- speech/transcription only if later approved as an explicit capability.

Evaluation data must be lawfully sourced, access-controlled and reviewed by competent language speakers. Synthetic examples may supplement but must not be presented as a substitute for representative real-world language evaluation.

## 8. Maale-language readiness gate

Before enabling Maale:
1. identify qualified language reviewers and governance;
2. establish orthography, vocabulary and variant policy;
3. confirm rights and consent for corpora/recordings;
4. create representative evaluation datasets;
5. measure retrieval, generation and translation quality;
6. define confidence/abstention thresholds;
7. approve the capability and its limitations explicitly.

## 9. Acceptance

- Amharic is the default end-to-end UI/AI language.
- English is usable for all A1-defined supported workflows.
- No Unicode corruption or lossy transformation occurs.
- Calendar and numeric values preserve canonical meaning.
- OCR and translation retain source provenance and uncertainty.
- Amharic evaluation reports are separate from English reports.
- Unsupported language requests are handled transparently rather than guessed.
