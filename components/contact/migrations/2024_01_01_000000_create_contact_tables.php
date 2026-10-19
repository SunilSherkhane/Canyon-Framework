<?php

/**
 * Migration: Create contact form tables
 */
return [
    'up' => [
        // Contact forms table
        "CREATE TABLE IF NOT EXISTS `contact_forms` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(64) NOT NULL UNIQUE,
            `label` VARCHAR(255) NOT NULL,
            `recipients` TEXT NOT NULL,
            `reply_to` VARCHAR(255) DEFAULT '',
            `status` TINYINT(1) DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",

        // Contact submissions table
        "CREATE TABLE IF NOT EXISTS `contact_submissions` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `form_name` VARCHAR(64) NOT NULL,
            `name` VARCHAR(255) NOT NULL,
            `email` VARCHAR(255) NOT NULL,
            `subject` VARCHAR(255) NOT NULL,
            `message` TEXT NOT NULL,
            `ip_address` VARCHAR(45) DEFAULT 'unknown',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_form` (`form_name`),
            INDEX `idx_email` (`email`),
            INDEX `idx_created` (`created_at`)
        )",
    ],

    'down' => [
        "DROP TABLE IF EXISTS `contact_submissions`",
        "DROP TABLE IF EXISTS `contact_forms`",
    ],
];