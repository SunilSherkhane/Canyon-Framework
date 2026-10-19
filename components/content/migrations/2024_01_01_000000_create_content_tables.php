<?php

/**
 * Migration: Create content and content types tables
 */
return [
    'up' => [
        // Content types table
        "CREATE TABLE IF NOT EXISTS `content_types` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `type` VARCHAR(64) NOT NULL UNIQUE,
            `name` VARCHAR(255) NOT NULL,
            `description` TEXT,
            `settings` TEXT,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",

        // Content table
        "CREATE TABLE IF NOT EXISTS `content` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `type` VARCHAR(64) NOT NULL,
            `title` VARCHAR(255) NOT NULL,
            `slug` VARCHAR(255) NOT NULL UNIQUE,
            `body` LONGTEXT,
            `excerpt` TEXT,
            `author_id` INT UNSIGNED NOT NULL,
            `status` TINYINT DEFAULT 1,
            `published_at` TIMESTAMP NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_type_status` (`type`, `status`),
            INDEX `idx_slug` (`slug`),
            INDEX `idx_author` (`author_id`),
            INDEX `idx_created` (`created_at`)
        )",
    ],

    'down' => [
        "DROP TABLE IF EXISTS `content`",
        "DROP TABLE IF EXISTS `content_types`",
    ],
];