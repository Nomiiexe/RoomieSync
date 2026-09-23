-- One-time migration for an existing RoomieSync database created from the
-- previous simplified database.sql. Existing users and remember-me tokens are
-- preserved. The domain tables currently contain no application data and are
-- rebuilt to match the attached diagrams.

USE roomiesync;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS chore_history;
DROP TABLE IF EXISTS inventoryTb;
DROP TABLE IF EXISTS paymentsTb;
DROP TABLE IF EXISTS bill_sharesTb;
DROP TABLE IF EXISTS billsTb;
DROP TABLE IF EXISTS bill_categoriesTb;
DROP TABLE IF EXISTS expenses_sharesTb;
DROP TABLE IF EXISTS expenseTb;
DROP TABLE IF EXISTS expensesTb;
DROP TABLE IF EXISTS expense_categoriesTb;
DROP TABLE IF EXISTS choresTb;
DROP TABLE IF EXISTS chore_rotationTb;
DROP TABLE IF EXISTS chore_assignmentsTb;
DROP TABLE IF EXISTS chore_categoriesTb;
DROP TABLE IF EXISTS announceTb;
DROP TABLE IF EXISTS maintenanceTb;
DROP TABLE IF EXISTS householdmembersTb;
DROP TABLE IF EXISTS householdTb;

SET FOREIGN_KEY_CHECKS = 1;

RENAME TABLE usersTb TO userTb;

ALTER TABLE userTb
  CHANGE COLUMN password password_hash VARCHAR(255) NOT NULL,
  ADD COLUMN email VARCHAR(100) NULL AFTER full_name,
  ADD UNIQUE KEY uq_user_email (email),
  ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'Active' AFTER created_at,
  MODIFY COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE authTokensTb
  MODIFY COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;
