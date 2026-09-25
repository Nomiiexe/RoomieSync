-- One-time migration for databases created before the revised chore ERD.
-- This removes the legacy choresTb.assigned_to column and its foreign key.

USE roomiesync;

SET @assigned_to_fk = (
  SELECT kcu.CONSTRAINT_NAME
  FROM information_schema.KEY_COLUMN_USAGE AS kcu
  WHERE kcu.TABLE_SCHEMA = DATABASE()
    AND kcu.TABLE_NAME = 'choresTb'
    AND kcu.COLUMN_NAME = 'assigned_to'
    AND kcu.REFERENCED_TABLE_NAME = 'userTb'
  LIMIT 1
);

SET @drop_assigned_to_fk = IF(
  @assigned_to_fk IS NULL,
  'SELECT 1',
  CONCAT('ALTER TABLE choresTb DROP FOREIGN KEY `', @assigned_to_fk, '`')
);
PREPARE drop_assigned_to_fk_statement FROM @drop_assigned_to_fk;
EXECUTE drop_assigned_to_fk_statement;
DEALLOCATE PREPARE drop_assigned_to_fk_statement;

SET @assigned_to_column_exists = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'choresTb'
    AND COLUMN_NAME = 'assigned_to'
);

SET @drop_assigned_to_column = IF(
  @assigned_to_column_exists = 0,
  'SELECT 1',
  'ALTER TABLE choresTb DROP COLUMN assigned_to'
);
PREPARE drop_assigned_to_column_statement FROM @drop_assigned_to_column;
EXECUTE drop_assigned_to_column_statement;
DEALLOCATE PREPARE drop_assigned_to_column_statement;
