<?php

/**
 * Migration: Create taxonomy tables
 */
return [
    'up' => [
        // Vocabularies table
        "CREATE TABLE IF NOT EXISTS `taxonomies` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `vid` VARCHAR(64) NOT NULL UNIQUE,
            `name` VARCHAR(255) NOT NULL,
            `description` TEXT,
            `weight` INT DEFAULT 0,
            `multiple` TINYINT(1) DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",

        // Terms table
        "CREATE TABLE IF NOT EXISTS `taxonomy_terms` (
            `tid` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `vid` VARCHAR(64) NOT NULL,
            `name` VARCHAR(255) NOT NULL,
            `slug` VARCHAR(255) NOT NULL,
            `description` TEXT,
            `parent` INT DEFAULT 0,
            `weight` INT DEFAULT 0,
            `format` VARCHAR(255) DEFAULT 'plain_text',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL,
            INDEX `idx_vid` (`vid`),
            INDEX `idx_slug` (`slug`),
            INDEX `idx_parent` (`parent`),
            INDEX `idx_weight` (`weight`)
        )",

        // Content-term relationships
        "CREATE TABLE IF NOT EXISTS `content_terms` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `content_id` INT UNSIGNED NOT NULL,
            `tid` INT UNSIGNED NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `unique_content_term` (`content_id`, `tid`),
            INDEX `idx_term` (`tid`)
        )",
    ],

    'down' => [
        "DROP TABLE IF EXISTS `content_terms`",
        "DROP TABLE IF EXISTS `taxonomy_terms`",
        "DROP TABLE IF EXISTS `taxonomies`",
    ],
];