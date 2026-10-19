<?php

/**
 * Migration: Create block tables
 */
return [
    'up' => [
        // Block regions table
        "CREATE TABLE IF NOT EXISTS `block_regions` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(64) NOT NULL,
            `label` VARCHAR(255) NOT NULL,
            `theme` VARCHAR(64) DEFAULT 'default',
            `weight` INT DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",

        // Blocks table
        "CREATE TABLE IF NOT EXISTS `blocks` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(64) NOT NULL UNIQUE,
            `label` VARCHAR(255) NOT NULL,
            `type` VARCHAR(64) DEFAULT 'custom',
            `plugin_id` VARCHAR(255) DEFAULT NULL,
            `theme` VARCHAR(64) DEFAULT 'default',
            `status` TINYINT(1) DEFAULT 1,
            `weight` INT DEFAULT 0,
            `settings` TEXT,
            `visibility` TEXT,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",

        // Block placement table
        "CREATE TABLE IF NOT EXISTS `block_placement` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `block_id` INT UNSIGNED NOT NULL,
            `region` VARCHAR(64) NOT NULL,
            `theme` VARCHAR(64) DEFAULT 'default',
            `weight` INT DEFAULT 0,
            `status` TINYINT(1) DEFAULT 1,
            `visibility` TEXT,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_region_theme` (`region`, `theme`),
            INDEX `idx_block` (`block_id`)
        )",
    ],

    'down' => [
        "DROP TABLE IF EXISTS `block_placement`",
        "DROP TABLE IF EXISTS `blocks`",
        "DROP TABLE IF EXISTS `block_regions`",
    ],
];