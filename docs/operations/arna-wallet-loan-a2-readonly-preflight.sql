-- ARNA Wallet/Loan A2 production metadata preflight
-- READ-ONLY: SELECT from INFORMATION_SCHEMA and SHOW statements only.
-- No application-row queries, writes, DDL, locks, migrations, or transactions.
--
-- Operator controls:
-- 1. Open the intended production database in phpMyAdmin before running.
-- 2. Confirm DATABASE() is the expected ARNA production schema.
-- 3. Prefer a dedicated read-only inspection account. If inspecting the actual
--    application account's grants, log in as that account and run SHOW GRANTS.
-- 4. Do not paste credentials, secret values, member data, or financial rows
--    into chat, GitHub, screenshots, or this report.
-- 5. If any query is denied, record "NOT AVAILABLE"; do not broaden privileges.
--
-- Expected schema name in the current deployment record: arna.
-- If the selected schema is not "arna", STOP and resolve the schema identity
-- with the production operator before running the remaining queries.

-- A. Connection identity and server configuration.
SELECT
    DATABASE() AS selected_schema,
    CURRENT_USER() AS authenticated_account,
    USER() AS connection_identity,
    @@version AS database_version,
    @@version_comment AS database_distribution,
    @@character_set_server AS server_charset,
    @@collation_server AS server_collation,
    @@time_zone AS session_time_zone,
    @@system_time_zone AS system_time_zone,
    @@sql_mode AS sql_mode,
    @@read_only AS server_read_only;

-- B. Current account grants. Preserve output privately; redact account host
--    identifiers before sharing outside the production administration team.
SHOW GRANTS;

-- C. Wallet/Loan table inventory and storage engines.
--    TABLE_ROWS is an engine estimate, not a financial-record export.
SELECT
    TABLE_NAME,
    TABLE_TYPE,
    ENGINE,
    ROW_FORMAT,
    TABLE_COLLATION,
    CREATE_OPTIONS,
    TABLE_ROWS,
    DATA_LENGTH,
    INDEX_LENGTH
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND (
      TABLE_NAME = 'wallets'
      OR LEFT(TABLE_NAME, 7) = 'wallet_'
      OR TABLE_NAME = 'loans'
      OR LEFT(TABLE_NAME, 5) = 'loan_'
  )
ORDER BY TABLE_NAME;

-- D. Wallet/Loan column definitions (no row values).
SELECT
    TABLE_NAME,
    COLUMN_NAME,
    ORDINAL_POSITION,
    DATA_TYPE,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_KEY,
    EXTRA
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND (
      TABLE_NAME = 'wallets'
      OR LEFT(TABLE_NAME, 7) = 'wallet_'
      OR TABLE_NAME = 'loans'
      OR LEFT(TABLE_NAME, 5) = 'loan_'
  )
ORDER BY TABLE_NAME, ORDINAL_POSITION;

-- E. Wallet/Loan indexes.
SELECT
    TABLE_NAME,
    INDEX_NAME,
    NON_UNIQUE,
    SEQ_IN_INDEX,
    COLUMN_NAME,
    COLLATION,
    CARDINALITY,
    INDEX_TYPE
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND (
      TABLE_NAME = 'wallets'
      OR LEFT(TABLE_NAME, 7) = 'wallet_'
      OR TABLE_NAME = 'loans'
      OR LEFT(TABLE_NAME, 5) = 'loan_'
  )
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;

-- F. Wallet/Loan foreign-key relationships.
SELECT
    TABLE_NAME,
    CONSTRAINT_NAME,
    COLUMN_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
  AND REFERENCED_TABLE_NAME IS NOT NULL
  AND (
      TABLE_NAME = 'wallets'
      OR LEFT(TABLE_NAME, 7) = 'wallet_'
      OR TABLE_NAME = 'loans'
      OR LEFT(TABLE_NAME, 5) = 'loan_'
  )
ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION;

-- G. Migration ledger presence and definition. First inspect this result.
--    Only if a table named "migrations" is listed, run the optional query below.
SELECT
    TABLE_NAME,
    ENGINE,
    TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'migrations';

-- OPTIONAL G2 (run only when section G confirms the table exists):
-- This returns migration identifiers and batch numbers only, not application data.
-- SELECT migration, batch FROM migrations ORDER BY batch, migration;

-- H. Relevant server limits/settings, using SHOW for MySQL/MariaDB compatibility.
SHOW VARIABLES
WHERE Variable_name IN (
    'autocommit',
    'character_set_database',
    'collation_database',
    'innodb_file_per_table',
    'innodb_flush_log_at_trx_commit',
    'innodb_lock_wait_timeout',
    'max_connections',
    'sql_mode',
    'time_zone',
    'transaction_isolation',
    'tx_isolation'
);

-- I. Backup evidence is NOT available from SQL metadata alone.
--    Obtain schedule, latest successful completion timestamp, retention,
--    encryption and restore-test evidence from the hosting/backup control plane.
