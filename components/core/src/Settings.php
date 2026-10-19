<?php

namespace Canyon\Core;

/**
 * Canyon Framework - Settings
 *
 * Plugin and user settings management.
 * based on Elgg's settings system.
 */
class Settings
{
    /** @var \PDO Database connection */
    protected \PDO $db;

    /** @var string Settings table */
    protected string $table = 'settings';

    /** @var string User settings table */
    protected string $userTable = 'user_settings';

    /**
     * Create a new Settings instance.
     */
    public function __construct(?\PDO $pdo = null)
    {
        $this->db = $pdo ?? (new Database())->getConnection();
        $this->ensureTables();
    }

    /**
     * Ensure settings tables exist.
     */
    protected function ensureTables(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS `{$this->table}` (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                entity_id VARCHAR(255) NOT NULL,
                entity_type VARCHAR(50) NOT NULL DEFAULT 'plugin',
                name VARCHAR(255) NOT NULL,
                value TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_setting (entity_id, entity_type, name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS `{$this->userTable}` (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id VARCHAR(255) NOT NULL,
                name VARCHAR(255) NOT NULL,
                value TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_user_setting (user_id, name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /**
     * Get a setting value.
     */
    public function get(string $entityId, string $name, mixed $default = null, string $entityType = 'plugin'): mixed
    {
        $stmt = $this->db->prepare(
            "SELECT value FROM `{$this->table}` WHERE entity_id = ? AND entity_type = ? AND name = ?"
        );
        $stmt->execute([$entityId, $entityType, $name]);
        $value = $stmt->fetchColumn();

        if ($value === false) {
            return $default;
        }

        return $this->unserialize($value);
    }

    /**
     * Set a setting value.
     */
    public function set(string $entityId, string $name, mixed $value, string $entityType = 'plugin'): bool
    {
        $serialized = $this->serialize($value);

        $stmt = $this->db->prepare(
            "INSERT INTO `{$this->table}` (entity_id, entity_type, name, value)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE value = ?"
        );

        return $stmt->execute([$entityId, $entityType, $name, $serialized, $serialized]);
    }

    /**
     * Delete a setting.
     */
    public function delete(string $entityId, string $name, string $entityType = 'plugin'): bool
    {
        $stmt = $this->db->prepare(
            "DELETE FROM `{$this->table}` WHERE entity_id = ? AND entity_type = ? AND name = ?"
        );
        return $stmt->execute([$entityId, $entityType, $name]);
    }

    /**
     * Get all settings for an entity.
     */
    public function all(string $entityId, string $entityType = 'plugin'): array
    {
        $stmt = $this->db->prepare(
            "SELECT name, value FROM `{$this->table}` WHERE entity_id = ? AND entity_type = ?"
        );
        $stmt->execute([$entityId, $entityType]);

        $settings = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $settings[$row['name']] = $this->unserialize($row['value']);
        }

        return $settings;
    }

    /**
     * Get a user setting.
     */
    public function getUser(string $userId, string $name, mixed $default = null): mixed
    {
        $stmt = $this->db->prepare(
            "SELECT value FROM `{$this->userTable}` WHERE user_id = ? AND name = ?"
        );
        $stmt->execute([$userId, $name]);
        $value = $stmt->fetchColumn();

        if ($value === false) {
            return $default;
        }

        return $this->unserialize($value);
    }

    /**
     * Set a user setting.
     */
    public function setUser(string $userId, string $name, mixed $value): bool
    {
        $serialized = $this->serialize($value);

        $stmt = $this->db->prepare(
            "INSERT INTO `{$this->userTable}` (user_id, name, value)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE value = ?"
        );

        return $stmt->execute([$userId, $name, $serialized, $serialized]);
    }

    /**
     * Delete a user setting.
     */
    public function deleteUser(string $userId, string $name): bool
    {
        $stmt = $this->db->prepare(
            "DELETE FROM `{$this->userTable}` WHERE user_id = ? AND name = ?"
        );
        return $stmt->execute([$userId, $name]);
    }

    /**
     * Get all user settings.
     */
    public function allUser(string $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT name, value FROM `{$this->userTable}` WHERE user_id = ?"
        );
        $stmt->execute([$userId]);

        $settings = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $settings[$row['name']] = $this->unserialize($row['value']);
        }

        return $settings;
    }

    /**
     * Serialize a value.
     */
    protected function serialize(mixed $value): string
    {
        return serialize($value);
    }

    /**
     * Unserialize a value.
     */
    protected function unserialize(string $value): mixed
    {
        return unserialize($value);
    }
}