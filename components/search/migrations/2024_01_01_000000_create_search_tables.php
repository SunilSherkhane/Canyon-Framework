<?php

/**
 * Migration: Create search index table
 */
return [
    'up' => [
        "CREATE TABLE IF NOT EXISTS `search_index` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `entity_type` VARCHAR(64) NOT NULL,
            `entity_id` INT UNSIGNED NOT NULL,
            `title` VARCHAR(255) NOT NULL,
            `body` LONGTEXT,
            `tags` TEXT,
            `status` TINYINT DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `unique_entity` (`entity_type`, `entity_id`),
            INDEX `idx_status` (`status`),
            INDEX `idx_type` (`entity_type`),
            INDEX `idx_updated` (`updated_at`)
        )",
    ],

    'down' => [
        "DROP TABLE IF EXISTS `search_index`",
    ],
];