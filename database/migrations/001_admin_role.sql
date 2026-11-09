-- Migration 001: Admin.Role for database-backed staff authorization
-- Safe for MariaDB 10.4.28. Idempotent: skips ADD COLUMN if Role already exists.
-- Does not drop or recreate Admin. Existing PasswordHash values are preserved.

USE clinic_db;

SET @role_col_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'Admin'
      AND COLUMN_NAME = 'Role'
);

SET @add_role_sql := IF(
    @role_col_exists = 0,
    'ALTER TABLE Admin ADD COLUMN Role VARCHAR(20) NOT NULL DEFAULT ''admin'' AFTER PasswordHash',
    'SELECT ''Admin.Role already present'' AS migration_001_info'
);

PREPARE migration_001_stmt FROM @add_role_sql;
EXECUTE migration_001_stmt;
DEALLOCATE PREPARE migration_001_stmt;

-- Bootstrap: if no super_admin exists yet, promote the earliest Admin row.
-- PHP still validates Role as only 'admin' or 'super_admin'.
UPDATE Admin
SET Role = 'super_admin'
WHERE Admin_ID = (
    SELECT mid FROM (SELECT MIN(Admin_ID) AS mid FROM Admin) AS earliest
)
AND NOT EXISTS (
    SELECT 1
    FROM (SELECT Admin_ID FROM Admin WHERE Role = 'super_admin') AS supers
);
