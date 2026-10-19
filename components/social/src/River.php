<?php

namespace Canyon\Core;

/**
 * Canyon Framework - River (Activity Stream)
 *
 * Activity stream for tracking user actions and events.
 * Elgg's River system.
 */
class River
{
    /** @var \PDO Database connection */
    protected \PDO $db;

    /** @var string River table */
    protected string $table = 'river';

    /**
     * Create a new River instance.
     */
    public function __construct(?\PDO $pdo = null)
    {
        $this->db = $pdo ?? (new Database())->getConnection();
        $this->ensureTable();
    }

    /**
     * Ensure river table exists.
     */
    protected function ensureTable(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS `{$this->table}` (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                type VARCHAR(50) NOT NULL,
                subtype VARCHAR(50) NOT NULL DEFAULT '',
                action_type VARCHAR(50) NOT NULL,
                entity_id INT UNSIGNED,
                entity_type VARCHAR(50),
                entity_subtype VARCHAR(50),
                owner_id VARCHAR(255),
                access_level VARCHAR(50) DEFAULT 'public',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_type (type, subtype),
                INDEX idx_entity (entity_id, entity_type),
                INDEX idx_owner (owner_id),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /**
     * Add an item to the river.
     *
     * @param array $item River item data
     * @return int River item ID
     */
    public function add(array $item): int
    {
        $required = ['type', 'action_type', 'entity_id'];
        foreach ($required as $field) {
            if (!isset($item[$field])) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        $stmt = $this->db->prepare(
            "INSERT INTO `{$this->table}`
            (type, subtype, action_type, entity_id, entity_type, entity_subtype, owner_id, access_level)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->execute([
            $item['type'],
            $item['subtype'] ?? '',
            $item['action_type'],
            $item['entity_id'],
            $item['entity_type'] ?? null,
            $item['entity_subtype'] ?? null,
            $item['owner_id'] ?? null,
            $item['access_level'] ?? 'public',
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Get river items.
     *
     * @param array $filters Filter options
     * @param int $limit Max items
     * @param int $offset Offset
     * @return array
     */
    public function get(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $where = [];
        $params = [];

        // Apply filters
        if (isset($filters['type'])) {
            $where[] = "type = ?";
            $params[] = $filters['type'];
        }
        if (isset($filters['subtype'])) {
            $where[] = "subtype = ?";
            $params[] = $filters['subtype'];
        }
        if (isset($filters['action_type'])) {
            $where[] = "action_type = ?";
            $params[] = $filters['action_type'];
        }
        if (isset($filters['entity_id'])) {
            $where[] = "entity_id = ?";
            $params[] = $filters['entity_id'];
        }
        if (isset($filters['entity_type'])) {
            $where[] = "entity_type = ?";
            $params[] = $filters['entity_type'];
        }
        if (isset($filters['owner_id'])) {
            $where[] = "owner_id = ?";
            $params[] = $filters['owner_id'];
        }

        // Access control
        if (!empty($filters['user_id'])) {
            $where[] = "(access_level = 'public' OR owner_id = ? OR access_level = 'logged_in')";
            $params[] = $filters['user_id'];
        }

        $whereClause = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);

        $sql = "SELECT * FROM `{$this->table}`{$whereClause}
                ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get items for a specific entity.
     */
    public function forEntity(int $entityId, string $entityType = '', int $limit = 20): array
    {
        $where = ["entity_id = ?"];
        $params = [$entityId];

        if ($entityType) {
            $where[] = "entity_type = ?";
            $params[] = $entityType;
        }

        $whereClause = ' WHERE ' . implode(' AND ', $where);

        $sql = "SELECT * FROM `{$this->table}`{$whereClause}
                ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get items for a specific owner.
     */
    public function forOwner(string $ownerId, int $limit = 20): array
    {
        return $this->get(['owner_id' => $ownerId], $limit);
    }

    /**
     * Delete river items for an entity.
     */
    public function deleteEntityItems(int $entityId, string $entityType = ''): int
    {
        $where = ["entity_id = ?"];
        $params = [$entityId];

        if ($entityType) {
            $where[] = "entity_type = ?";
            $params[] = $entityType;
        }

        $stmt = $this->db->prepare("DELETE FROM `{$this->table}` WHERE " . implode(' AND ', $where));
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Get count of river items.
     */
    public function count(array $filters = []): int
    {
        $where = [];
        $params = [];

        foreach (['type', 'subtype', 'action_type', 'entity_id', 'entity_type', 'owner_id'] as $field) {
            if (isset($filters[$field])) {
                $where[] = "`$field` = ?";
                $params[] = $filters[$field];
            }
        }

        $whereClause = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);

        $sql = "SELECT COUNT(*) FROM `{$this->table}`{$whereClause}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }
}