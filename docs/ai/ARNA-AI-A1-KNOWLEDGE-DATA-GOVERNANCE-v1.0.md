# ARNA AI A1 — Knowledge, Retrieval & Data Governance

**Document ID:** ARNA-AI-A1-004  
**Version:** 1.0.0  
**Status:** PROPOSED — OWNER APPROVAL PENDING

## 1. Objective

Provide source-grounded enterprise knowledge retrieval without creating a competing system of record or weakening ARNA's tenant, domain, legal-entity and confidentiality controls.

## 2. Knowledge classes

| Class | Description | Retrieval posture |
|---|---|---|
| K1 — Authoritative business record | Current record in its owning ARNA domain | Read only through authorized domain API; source record/version must be cited |
| K2 — Approved corporate knowledge | Approved policies, bylaws, adopted resolutions, procedures, manuals and published internal standards | Retrieve according to tenant, classification, effective date and audience |
| K3 — Working / draft material | Drafts, proposals, unapproved documents and working notes | Label as draft; never present as adopted authority |
| K4 — External / public material | External law, standards, research and public sources | Identify source, publication/effective date and jurisdiction; distinguish from ARNA-approved policy |
| K5 — AI-generated material | Summaries, translations, extracted fields, embeddings, analyses and proposals | Derived content only; link to source and generation lineage; never elevate to K1/K2 automatically |

## 3. Source registration and ingestion

Each source must have:
- immutable source identifier and owning domain;
- tenant and legal-entity scope where applicable;
- source type, title, language and classification;
- canonical record/document ID and version;
- created, effective, superseded and retrieval timestamps as applicable;
- owner, retention class and lifecycle state;
- permitted audience and retrieval policy;
- integrity metadata and ingestion outcome.

Ingestion must preserve the original. Parsing, OCR, chunking, translation and embedding create derived artifacts. Corrections must be versioned and attributable; do not overwrite the source.

## 4. Permission-aware RAG

Retrieval must apply controls at every boundary:

1. Authenticate user and resolve server-side tenant context.
2. Resolve role, permission, legal-entity and record-level confidentiality scope.
3. Filter candidate sources before retrieval ranking wherever supported.
4. Re-check each selected source through its authoritative API before content disclosure.
5. Enforce the same scope on cached chunks, summaries, citations and follow-up retrieval.
6. Reject cross-tenant, cross-entity or unauthorized references even if the model requests them.
7. Record retrieval policy version, source IDs/versions and freshness metadata.

A vector-store namespace or metadata filter alone is not an authorization boundary. The domain API remains authoritative.

## 5. Answer grounding contract

For material factual responses, provide:
- answer;
- source references with record/document identifier and version;
- relevant source date/effective date;
- retrieval timestamp or freshness label;
- distinction between source fact and AI interpretation;
- confidence/uncertainty and material limitations;
- missing/conflicting evidence warning where applicable.

Do not fabricate a citation, source title, page number, record ID or approval status. If no authorized evidence supports a material claim, abstain or explicitly state that it could not be verified.

## 6. Chunking and indexing

A2/A3 must determine the indexing strategy after inspecting source formats and workload. The architecture requires:
- stable mapping from every chunk to its exact source and version;
- tenant/domain/classification metadata;
- language and script metadata;
- page/section/paragraph offsets where available;
- deterministic re-index and deletion/supersession behavior;
- stale-index detection and source revalidation;
- separation of confidential and public retrieval contexts;
- no use of embeddings as canonical storage.

Chunk size, overlap, embedding model, vector database and ranking algorithm remain open pending measured evaluation and provider/data review.

## 7. Data lifecycle

\`REGISTERED → INGESTED → INDEXED → AVAILABLE → SUPERSEDED / WITHDRAWN → PURGED\`

- Effective-date and supersession rules are owned by the source domain.
- Revoked, deleted or access-restricted sources must cease to be retrievable within a defined, tested propagation objective.
- Cache invalidation must follow source version and authorization changes.
- Retention and purge must follow approved ARNA record-retention policy and legal holds.
- Backup copies, derived chunks and embeddings must be included in the retention/deletion design.
- No retention period is invented in A1.

## 8. Sensitive data and model context

- Minimize personal, financial, governance and confidential content sent to a model.
- Redact credentials, secrets, tokens and unrelated personal data.
- Never include one tenant's data in another tenant's prompt or retrieval context.
- Provider egress remains disabled until provider, region, contractual terms, retention, training use, security and data residency are approved.
- Do not use ARNA confidential data to train or fine-tune a model without a separate explicit approval and legal/privacy review.
- Treat retrieved documents and user-provided content as untrusted data, not as instructions to override system policy.

## 9. Prompt injection and malicious content

Retrieved content may contain malicious or misleading instructions. The system must:
- treat source text as data;
- prevent source text from changing agent role, system policy, tool allowlist or authorization;
- strip or neutralize active content where applicable;
- isolate tool execution from document parsing;
- require explicit schema validation for tool arguments;
- record and test prompt-injection attempts;
- abstain when the model cannot safely distinguish evidence from instructions.

## 10. Data quality and source conflicts

If two sources conflict:
1. apply the owning domain's authoritative-source and version rules;
2. prefer current effective approved source over draft or superseded content;
3. do not resolve legal or governance ambiguity by model confidence;
4. surface the conflict and request authoritative resolution;
5. preserve both references in the audit trail.

## 11. Evaluation requirements

Evaluate retrieval precision/recall, citation correctness, freshness, permission leakage, source conflict handling, prompt injection, OCR extraction accuracy, Amharic/English parity and abstention. Security leakage is a hard blocker; a high average relevance score cannot offset it.

## 12. Open design decisions

Vector store, embedding model, chunking policy, ranking, retention, deletion SLA, cache TTL, ingestion schedule, OCR provider and model context limits remain unresolved until A2/A3 evidence and Owner approval.
