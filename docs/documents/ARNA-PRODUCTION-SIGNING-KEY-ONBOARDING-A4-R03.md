# ARNA production document-signing key onboarding — Owner-controlled contract

Status: A4-R03 operational contract. No production key is provisioned, registered, generated, imported, enabled, or trusted by this document.

## Trust boundary

ARNA verifies detached Ed25519 signatures against tenant-scoped public-key records. Private keys remain outside ARNA under the signatory's custody. The trust registry is not self-service: there is no public registration endpoint. Test fixtures may generate ephemeral keys only and must never be promoted into production trust anchors.

A database row is not, by itself, proof of identity, authority, or custody. Production trust requires an independently evidenced onboarding decision and an Owner-authorized controlled change.

## Required onboarding stages

**K1 — Nomination and authority evidence**
- Identify the legal entity, tenant, governance body and position, intended signer, permitted document actions, and effective term.
- Verify the signer against authoritative identity and appointment/membership records.
- Verify that the proposed role is authorized for documents.sign and/or documents.issue under the applicable governance instrument.
- Record the source decision/instrument reference and accountable verifier.

**K2 — Independent identity and custody verification**
- Verify the signatory's identity out of band using approved authoritative evidence.
- Verify that the signer controls the private key and can produce a challenge signature from the intended signing device or managed key service.
- Confirm algorithm and public-key encoding; reject shared, copied, ambiguous, or unverifiable custody.
- Never request or accept a private key, seed phrase, recovery phrase, or secret export into ARNA, GitHub, chat, CI logs, or support tickets.

**K3 — Public-key challenge and review**
- Generate a unique non-production onboarding challenge containing a random nonce, tenant identifier, proposed signer identity, key identifier, and expiry.
- Verify the Ed25519 signature against the candidate public key outside the production trust registry.
- Independently review the challenge transcript, identity evidence reference, appointment authority, key fingerprint, and custody attestation.
- Communicate the key fingerprint to the signer through a second trusted channel.

**K4 — Owner authorization**
- Submit a complete onboarding dossier for explicit Owner approval. Approval must identify the exact tenant, signer user ID, key ID, SHA-256 fingerprint of the public key, allowed purpose, effective-from instant, optional expiry, and evidence references.
- No inferred, blanket, delegated, or retrospective approval. Any change to signer, key, tenant, purpose, validity, or trust reference requires a new approval.
- The implementer must not self-approve a key they nominate or verify.

**K5 — Controlled registry insertion (separate A5 authorization)**
- Only after K1–K4 are VERIFIED and the Owner separately authorizes execution may a designated operator insert the public key using a reviewed, auditable, one-time controlled procedure.
- Bind the row to the exact tenant and signer; use a unique tenant-scoped key ID, algorithm Ed25519, verified public key, trust reference, trust approver, trusted timestamp, validity start and optional expiry.
- Never place private material in the row or in logs.
- Verify the inserted row and fingerprint independently. Do not bundle insertion with application deployment or an unreviewed migration.

**K6 — Revocation and incident response**
- Revoke immediately on signer departure, appointment expiry, suspected compromise, lost custody, key replacement, or Owner instruction.
- Use the existing append-only revocation service and preserve reason, actor, timestamp and authority reference.
- A revoked key remains revoked; replacement requires a new K1–K5 dossier. A historical signature must not override current revocation policy.
- Retain incident evidence and assess documents signed during the suspected compromise window.

## Minimum dossier

The controlled dossier must include: tenant and legal entity; signer canonical user ID; governance body/position; permitted action(s); authoritative identity verification reference; appointment/resolution reference; key ID; algorithm; public-key fingerprint; challenge transcript reference; custody attestation; verifier identity; independent reviewer identity; Owner approval reference and timestamp; validity interval; registry insertion change reference; post-insertion verification; and revocation contact/runbook.

Sensitive identity evidence belongs in the approved restricted records system, not source control or public issue comments. Only non-sensitive evidence references and the public-key fingerprint belong in ordinary engineering records.

## Separation of duties

The nominator, identity verifier, independent reviewer, Owner approver, and registry operator must be distinguishable. If complete separation is impracticable, document compensating independent review and require explicit Owner acceptance of residual risk. The signer cannot approve their own trust onboarding.

## Current disposition and prohibition

- Ed25519 verification, tenant/signer binding, validity checks, revocation checks and append-only trust/revocation records: IMPLEMENTED.
- Production identity verification, custody attestation, Owner-approved dossier, controlled insertion procedure and production trust anchors: NOT IMPLEMENTED / NOT VERIFIED.
- Production key provisioning or registration: NOT AUTHORIZED.
- No production private or public key material was created or added by this remediation.
