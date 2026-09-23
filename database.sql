-- RoomieSync schema based on the attached ER diagrams.
-- Import this file in phpMyAdmin for a new installation.
--
-- The diagrams include an email column in userTb. It is nullable to preserve
-- older accounts created before email registration was enabled. Email is
-- stored on new profiles but is not used for password recovery.

CREATE DATABASE IF NOT EXISTS roomiesync
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE roomiesync;

CREATE TABLE IF NOT EXISTS userTb (
  user_id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(100) NOT NULL,
  email VARCHAR(100) NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status VARCHAR(20) NOT NULL DEFAULT 'Active',
  recovery_code_hash VARCHAR(255) NULL
) ENGINE=InnoDB;

-- Technical table used by the PHP "Remember Me" feature.
CREATE TABLE IF NOT EXISTS authTokensTb (
  token_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  selector CHAR(24) NOT NULL UNIQUE,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_auth_tokens_user (user_id),
  INDEX idx_auth_tokens_expiry (expires_at),
  FOREIGN KEY (user_id) REFERENCES userTb(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS householdTb (
  household_id INT AUTO_INCREMENT PRIMARY KEY,
  household_name VARCHAR(100) NOT NULL,
  description TEXT NULL,
  address VARCHAR(255) NULL,
  household_image VARCHAR(255) NULL,
  join_code VARCHAR(32) NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS householdmembersTb (
  household_id INT NOT NULL,
  user_id INT NOT NULL,
  role VARCHAR(20) NOT NULL DEFAULT 'Member',
  joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (household_id, user_id),
  FOREIGN KEY (household_id) REFERENCES householdTb(household_id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES userTb(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS expense_categoriesTb (
  expense_category_id INT AUTO_INCREMENT PRIMARY KEY,
  household_id INT NOT NULL,
  category_name VARCHAR(50) NOT NULL,
  description TEXT NULL,
  FOREIGN KEY (household_id) REFERENCES householdTb(household_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS expenseTb (
  expense_id INT AUTO_INCREMENT PRIMARY KEY,
  household_id INT NOT NULL,
  category_id INT NULL,
  title VARCHAR(100) NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  paid_by INT NULL,
  expense_date DATE NOT NULL,
  description TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (household_id) REFERENCES householdTb(household_id) ON DELETE CASCADE,
  FOREIGN KEY (category_id) REFERENCES expense_categoriesTb(expense_category_id) ON DELETE SET NULL,
  FOREIGN KEY (paid_by) REFERENCES userTb(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS expenses_sharesTb (
  share_id INT AUTO_INCREMENT PRIMARY KEY,
  expense_id INT NOT NULL,
  user_id INT NOT NULL,
  share_amount DECIMAL(10,2) NOT NULL,
  is_paid TINYINT(1) NOT NULL DEFAULT 0,
  FOREIGN KEY (expense_id) REFERENCES expenseTb(expense_id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES userTb(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS chore_categoriesTb (
  chore_category_id INT AUTO_INCREMENT PRIMARY KEY,
  household_id INT NOT NULL,
  category_name VARCHAR(50) NOT NULL,
  description TEXT NULL,
  FOREIGN KEY (household_id) REFERENCES householdTb(household_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS choresTb (
  chore_id INT AUTO_INCREMENT PRIMARY KEY,
  household_id INT NOT NULL,
  category_id INT NULL,
  chore_name VARCHAR(100) NOT NULL,
  description TEXT NULL,
  frequency VARCHAR(50) NOT NULL,
  assignment_type VARCHAR(20) NOT NULL,
  created_by INT NOT NULL,
  assigned_to INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (household_id) REFERENCES householdTb(household_id) ON DELETE CASCADE,
  FOREIGN KEY (category_id) REFERENCES chore_categoriesTb(chore_category_id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES userTb(user_id) ON DELETE CASCADE,
  FOREIGN KEY (assigned_to) REFERENCES userTb(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS chore_rotationTb (
  rotation_id INT AUTO_INCREMENT PRIMARY KEY,
  chore_id INT NOT NULL,
  user_id INT NOT NULL,
  rotation_order INT NOT NULL,
  FOREIGN KEY (chore_id) REFERENCES choresTb(chore_id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES userTb(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS chore_assignmentsTb (
  assignment_id INT AUTO_INCREMENT PRIMARY KEY,
  chore_id INT NOT NULL,
  user_id INT NOT NULL,
  due_date DATE NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'Pending',
  completed_at DATETIME NULL,
  FOREIGN KEY (chore_id) REFERENCES choresTb(chore_id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES userTb(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS bill_categoriesTb (
  bill_category_id INT AUTO_INCREMENT PRIMARY KEY,
  household_id INT NOT NULL,
  category_name VARCHAR(50) NOT NULL,
  description TEXT NULL,
  FOREIGN KEY (household_id) REFERENCES householdTb(household_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS billsTb (
  bill_id INT AUTO_INCREMENT PRIMARY KEY,
  household_id INT NOT NULL,
  category_id INT NULL,
  bill_name VARCHAR(100) NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  due_date DATE NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'Unpaid',
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (household_id) REFERENCES householdTb(household_id) ON DELETE CASCADE,
  FOREIGN KEY (category_id) REFERENCES bill_categoriesTb(bill_category_id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES userTb(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS bill_sharesTb (
  bill_share_id INT AUTO_INCREMENT PRIMARY KEY,
  bill_id INT NOT NULL,
  user_id INT NOT NULL,
  share_amount DECIMAL(10,2) NOT NULL,
  payment_status VARCHAR(20) NOT NULL DEFAULT 'Unpaid',
  FOREIGN KEY (bill_id) REFERENCES billsTb(bill_id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES userTb(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS paymentsTb (
  payment_id INT AUTO_INCREMENT PRIMARY KEY,
  bill_share_id INT NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  payment_date DATE NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'Paid',
  notes TEXT NULL,
  FOREIGN KEY (bill_share_id) REFERENCES bill_sharesTb(bill_share_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS announceTb (
  announcement_id INT AUTO_INCREMENT PRIMARY KEY,
  household_id INT NOT NULL,
  created_by INT NULL,
  title VARCHAR(100) NOT NULL,
  content TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (household_id) REFERENCES householdTb(household_id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES userTb(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS maintenanceTb (
  problem_id INT AUTO_INCREMENT PRIMARY KEY,
  household_id INT NOT NULL,
  reported_by INT NULL,
  problem_type VARCHAR(50) NOT NULL,
  problem_desc TEXT NULL,
  reported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status VARCHAR(20) NOT NULL DEFAULT 'Open',
  FOREIGN KEY (household_id) REFERENCES householdTb(household_id) ON DELETE CASCADE,
  FOREIGN KEY (reported_by) REFERENCES userTb(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;
