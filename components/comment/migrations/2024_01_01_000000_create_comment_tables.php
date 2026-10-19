<?php

/**
 * Migration: Create comment tables
 */
return [
    'up' => [
        "CREATE TABLE IF NOT EXISTS `comments` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `entity_type` VARCHAR(64) NOT NULL,
            `entity_id` INT UNSIGNED NOT NULL,
            `subject` VARCHAR(255) DEFAULT '',
            `body` TEXT NOT NULL,
            `author_id` INT UNSIGNED DEFAULT 0,
            `author_name` VARCHAR(255) DEFAULT 'Anonymous',
            `author_email` VARCHAR(255) DEFAULT '',
            `parent_id` INT UNSIGNED DEFAULT 0,
            `status` TINYINT DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL,
            INDEX `idx_entity` (`entity_type`, `entity_id`),
            INDEX `idx_parent` (`parent_id`),
            INDEX `idx_status` (`status`),
            INDEX `idx_author` (`author_id`),
            INDEX `idx_created` (`created_at`)
        )",
    ],

    'down' => [
        "DROP TABLE IF EXISTS `comments`",
    ],
];