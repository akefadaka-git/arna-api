-- ARNA production read-only schema preflight for Plesk/phpMyAdmin
-- Target schema: `arna`. Verify this is the intended production schema before running.
-- Run only after logging in as the dedicated account and confirming CURRENT_USER().
-- Never paste passwords or secrets into chat.
-- STOP if SHOW GRANTS shows application-table SELECT privileges or any write/admin grants.
-- This script queries metadata only; it does not query application rows or modify data.

-- 1. Confirm session identity. selected_schema may be information_schema in phpMyAdmin.
SELECT
    DATABASE() AS selected_schema,
    CURRENT_USER() AS authenticated_account,
    @@hostname AS database_server,
    @@read_only AS server_read_only;

-- 2. Review grants for the account actually executing this script.
SHOW GRANTS;

-- 3. Find shareholder-related tables in the explicit target schema.
SELECT
    TABLE_NAME,
    TABLE_TYPE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'arna'
  AND (
      LOWER(TABLE_NAME) LIKE '%shareholder%'
      OR LOWER(TABLE_NAME) LIKE '%alias%'
      OR LOWER(TABLE_NAME) LIKE '%sequence%'
  )
ORDER BY TABLE_NAME;

-- 4. Inspect columns only for matching tables in the explicit target schema.
SELECT
    TABLE_NAME,
    COLUMN_NAME,
    ORDINAL_POSITION,
    DATA_TYPE,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_KEY,
    EXTRA
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'arna'
  AND (
      LOWER(TABLE_NAME) LIKE '%shareholder%'
      OR LOWER(TABLE_NAME) LIKE '%alias%'
      OR LOWER(TABLE_NAME) LIKE '%sequence%'
  )
ORDER BY TABLE_NAME, ORDINAL_POSITION;

-- 5. Inspect indexes only for matching tables in the explicit target schema.
SELECT
    TABLE_NAME,
    INDEX_NAME,
    NON_UNIQUE,
    SEQ_IN_INDEX,
    COLUMN_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = 'arna'
  AND (
      LOWER(TABLE_NAME) LIKE '%shareholder%'
      OR LOWER(TABLE_NAME) LIKE '%alias%'
      OR LOWER(TABLE_NAME) LIKE '%sequence%'
  )
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;
