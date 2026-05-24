-- Migration: Add customer_reply columns to customer_reports
-- This migration adds columns for customers to reply to rider reports

ALTER TABLE `customer_reports` ADD COLUMN `customer_reply` TEXT AFTER `details` IF NOT EXISTS;
ALTER TABLE `customer_reports` ADD COLUMN `customer_replied_at` TIMESTAMP NULL AFTER `customer_reply` IF NOT EXISTS;
