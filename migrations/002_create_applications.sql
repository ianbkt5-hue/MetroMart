-- Migration: 002_create_applications.sql
-- Creates applications tables for riders and merchants

CREATE TABLE IF NOT EXISTS rider_applications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fname VARCHAR(120) NOT NULL,
  lname VARCHAR(120) NOT NULL,
  email VARCHAR(255),
  phone VARCHAR(32),
  vehicle_type VARCHAR(32) DEFAULT 'motorcycle',
  license_path VARCHAR(512),
  photo_path VARCHAR(512),
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  admin_note VARCHAR(512),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS merchant_applications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  merchant_name VARCHAR(255) NOT NULL,
  address TEXT,
  latitude DECIMAL(10,7),
  longitude DECIMAL(10,7),
  employee_fname VARCHAR(120) NOT NULL,
  employee_lname VARCHAR(120) NOT NULL,
  employee_phone VARCHAR(32),
  employee_email VARCHAR(255),
  employee_face_path VARCHAR(512),
  employee_id_path VARCHAR(512),
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  admin_note VARCHAR(512),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Rollback:
-- DROP TABLE IF EXISTS merchant_applications;
-- DROP TABLE IF EXISTS rider_applications;
