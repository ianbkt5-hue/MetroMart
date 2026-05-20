-- Migration: 001_create_password_reset_attempts.sql
-- Creates the password_reset_attempts table used by the forgot-password lockout logic

CREATE TABLE IF NOT EXISTS password_reset_attempts (
  user_id INT UNSIGNED PRIMARY KEY,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_attempt_at DATETIME,
  locked_until DATETIME,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Rollback:
-- DROP TABLE IF EXISTS password_reset_attempts;
