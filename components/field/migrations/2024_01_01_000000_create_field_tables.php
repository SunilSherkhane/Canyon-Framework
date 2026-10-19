<?php

/**
 * Migration: Create field tables
 */
return [
    'up' => [
        // Fields table
        "CREATE TABLE IF NOT EXISTS `fields` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(64) NOT NULL UNIQUE,
            `type` VARCHAR(64) NOT NULL,
            `label` VARCHAR(255) NOT NULL,
            `settings` TEXT,
            `widget` VARCHAR(64) DEFAULT 'text_textfield',
            `weight` INT DEFAULT 0,
            `required` TINYINT(1) DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",

        // Content field items (which fields belong to which content types)
        "CREATE TABLE IF NOT EXISTS `content_field_items` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `field_name` VARCHAR(64) NOT NULL,
            `content_type` VARCHAR(64) NOT NULL,
            `weight` INT DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `unique_field_type` (`field_name`, `content_type`)
        )",

        // Field values
        "CREATE TABLE IF NOT EXISTS `content_field_values` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `content_id` INT UNSIGNED NOT NULL,
            `field_name` VARCHAR(64) NOT NULL,
            `langcode` INT DEFAULT 10,
            `value` LONGTEXT,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_content_field` (`content_id`, `field_name`),
            INDEX `idx_field_lang` (`field_name`, `langcode`)
        )",
    ],

    'down' => [
        "DROP TABLE IF EXISTS `content_field_values`",
        "DROP TABLE IF EXISTS `content_field_items`",
        "DROP TABLE IF EXISTS `fields`",
    ],
];