-- ARNA Wallet/Loan A2 production metadata preflight
-- READ-ONLY: INFORMATION_SCHEMA SELECTs and SHOW statements only.
-- No application-row queries, writes, DDL, locks, migrations, or transactions.
--
-- Operator controls:
-- 1. Open the intended production database in phpMyAdmin before running.
-- 2. Confirm DATABASE() is the expected ARNA production schema ("arna").
--    If not, STOP and resolve schema identity with the production operator.
-- 3. Prefer a dedicated read-only inspection account. To inspect the actual
--    application account's grants, log in as that account and run SHOW GRANTS.
-- 4. Never paste credentials, secrets, member data, or financial rows into chat,
--    GitHub, screenshots, or this report. Redact account/host identifiers.
-- 5. If access is denied, record NOT AVAILABLE; do not broaden privileges.
--
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

-- B. Current account grants. Keep output private; redact account/host identifiers.
SHOW GRANTS;

-- C. Wallet/Loan table inventory and storage engines.
-- TABLE_ROWS is an engine estimate, not an export of financial records.
SELECT
    TABLE_NAME, TABLE_TYPE, ENGINE, ROW_FORMAT, TABLE_COLLATION,
    CREATE_OPTIONS, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND (
      TABLE_NAME = 'wallets' OR LEFT(TABLE_NAME, 7) = 'wallet_'
      OR TABLE_NAME = 'loans' OR LEFT(TABLE_NAME, 5) = 'loan_'
  )
ORDER BY TABLE_NAME;

-- D. Wallet/Loan column definitions (schema metadata only).
SELECT
    TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, DATA_TYPE, COLUMN_TYPE,
    IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY, EXTRA
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND (
      TABLE_NAME = 'wallets' OR LEFT(TABLE_NAME, 7) = 'wallet_'
      OR TABLE_NAME = 'loans' OR LEFT(TABLE_NAME, 5) = 'loan_'
  )
ORDER BY TABLE_NAME, ORDINAL_POSITION;

-- E. Wallet/Loan indexes.
SELECT
    TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX,
    COLUMN_NAME, COLLATION, CARDINALITY, INDEX_TYPE
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND (
      TABLE_NAME = 'wallets' OR LEFT(TABLE_NAME, 7) = 'wallet_'
      OR TABLE_NAME = 'loans' OR LEFT(TABLE_NAME, 5) = 'loan_'
  )
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;

-- F. Wallet/Loan foreign-key relationships.
SELECT
    TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME,
    REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
  AND REFERENCED_TABLE_NAME IS NOT NULL
  AND (
      TABLE_NAME = 'wallets' OR LEFT(TABLE_NAME, 7) = 'wallet_'
      OR TABLE_NAME = 'loans' OR LEFT(TABLE_NAME, 5) = 'loan_'
  )
ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION;

-- G. Confirm whether Laravel's migration ledger exists.
SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'migrations';

-- OPTIONAL G2: run ONLY if section G confirms the table exists.
-- Returns migration identifiers/batch numbers only, not application records.
-- SELECT migration, batch FROM migrations ORDER BY batch, migration;

-- H. Relevant server limits/settings. SHOW syntax supports MySQL/MariaDB.
SHOW VARIABLES
WHERE Variable_name IN (
    'autocommit', 'character_set_database', 'collation_database',
    'innodb_file_per_table', 'innodb_flush_log_at_trx_commit',
    'innodb_lock_wait_timeout', 'max_connections', 'sql_mode',
    'time_zone', 'transaction_isolation', 'tx_isolation'
);

-- I. SQL metadata cannot prove backup freshness, retention, encryption,
--    or restore success. Obtain these from the hosting/backup control plane.
