# Election committee legacy-record reconciliation

## Purpose

The election lifecycle migration creates the canonical `governance_elections` parent aggregate and then attaches tenant-scoped foreign keys to the earlier committee-security tables. Existing committee rows must refer to real, authoritative elections. The migration deliberately does not invent placeholder elections.

MySQL commits DDL independently. If the migration stops because legacy committee rows have no matching canonical election, the parent aggregate and lifecycle-transition tables may already exist. The migration is designed to be rerun after the reconciliation below; do not drop those tables or roll back this forward-only migration.

## 1. Read-only inventory

Back up the database and record the backup identifier before any data change. Run the following queries first:

```sql
SELECT 'admins' AS source_table, tenant_id, election_id, COUNT(*) AS row_count
FROM governance_election_committee_admins
GROUP BY tenant_id, election_id
UNION ALL
SELECT 'conflicts', tenant_id, election_id, COUNT(*)
FROM governance_election_committee_conflicts
GROUP BY tenant_id, election_id
UNION ALL
SELECT 'security_events', tenant_id, election_id, COUNT(*)
FROM governance_election_committee_security_events
GROUP BY tenant_id, election_id
ORDER BY tenant_id, election_id, source_table;
```

After the canonical parent table exists, list unresolved pairs:

```sql
SELECT legacy.tenant_id, legacy.election_id, COUNT(*) AS legacy_rows
FROM (
    SELECT tenant_id, election_id FROM governance_election_committee_admins
    UNION ALL
    SELECT tenant_id, election_id FROM governance_election_committee_conflicts
    UNION ALL
    SELECT tenant_id, election_id FROM governance_election_committee_security_events
) AS legacy
LEFT JOIN governance_elections AS election
  ON election.tenant_id = legacy.tenant_id
 AND election.id = legacy.election_id
WHERE election.id IS NULL
GROUP BY legacy.tenant_id, legacy.election_id
ORDER BY legacy.tenant_id, legacy.election_id;
```

## 2. Authoritative mapping

For every unresolved `(tenant_id, election_id)` pair:

1. Locate the original election register, approved resolution, election instrument, or other authoritative source that proves the election existed.
2. Confirm the tenant identity and original election identifier. Do not infer a tenant from a person, user, or committee member.
3. Record the approved election code, title, scope, type, status, source reference, and reviewer in a reconciliation register.
4. Have the authorized governance data owner validate the mapping.
5. If no authoritative source proves the election, stop. Do not create a placeholder row, reuse another tenant's election, or delete the committee-security records to make the migration pass.

## 3. Insert canonical parent records

Only after the mapping is approved, insert one canonical parent row for each proven election, preserving the original `election_id` and `tenant_id`. The values for code, scope, type, title, and status must come from the approved source, not from guesses.

Example shape (replace every bracketed value from the approved reconciliation register; this is a template, not a ready-to-run statement):

```sql
INSERT INTO governance_elections
    (id, tenant_id, code, title_am, title_en, election_scope,
     election_type, status, created_at, updated_at)
VALUES
    ([original_election_id], [verified_tenant_id], '[approved_unique_code]',
     [verified_title_am_or_NULL], [verified_title_en_or_NULL],
     '[verified_scope]', '[verified_type]', '[verified_status]', NOW(), NOW());
```

Check uniqueness before insertion and preserve the original IDs referenced by legacy rows. Do not insert a parent row if its authoritative source is missing or contradictory.

## 4. Verify before retry

Repeat the unresolved-pairs query in section 1. It must return zero rows. Also confirm that every legacy row still exists and that every pair is tenant-matched to exactly one canonical parent.

Then rerun the migration through the repository's approved migration workflow. The migration will install the composite foreign keys idempotently. If any orphan remains, it will stop without adding the committee foreign keys.

## 5. Evidence to retain

Retain the database backup reference, read-only query output, authoritative source references and digests, approved mapping register, inserted IDs, migration run link, and post-migration foreign-key verification. This procedure is a pre-deployment operational control; it does not authorize production migration or deployment by itself.
