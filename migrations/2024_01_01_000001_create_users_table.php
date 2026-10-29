<?php

use Canyon\Core\Database;

/**
 * Create users table for authentication.
 */

$table = 'users';

$sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `roles` JSON DEFAULT '[\"user\"]',
  `created_at` INT UNSIGNED NOT NULL,
  `updated_at` INT UNSIGNED NOT NULL,
  INDEX `idx_email` (`email`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

try {
 (new Database())->getConnection()->exec($sql);
} catch (\PDOException $e) {
 // Table may already exist — log but continue
 error_log('User table migration: ' . $e->getMessage());
}
