<?php

namespace Canyon\Core;

/**
 * Canyon Framework - Search
 *
 * Entity search with filtering, sorting, and pagination.
 * Elgg's search system.
 */
class Search
{
    /** @var \PDO Database connection */
    protected \PDO $db;

    /** @var string Entity table */
    protected string $table = 'entities';

    /** @var array Searchable fields */
    protected array $searchableFields = [];

    /** @var int Default limit */
    protected int $defaultLimit = 20;

    /**
     * Create a new Search instance.
     */
    public function __construct(?\PDO $pdo = null)
    {
        $this->db = $pdo ?? (new Database())->getConnection();
    }

    /**
     * Search entities.
     *
     * @param string $query Search query
     * @param array $filters Additional filters (type, subtype, owner, etc.)
     * @param array $options Search options (limit, offset, sort, order)
     * @return array Search results with pagination
     */
    public function query(string $query, array $filters = [], array $options = []): array
    {
        $limit = $options['limit'] ?? $this->defaultLimit;
        $offset = $options['offset'] ?? 0;
        $sort = $options['sort'] ?? 'created_at';
        $order = $options['order'] ?? 'DESC';

        // Build WHERE clause
        $where = [];
        $params = [];

        // Full-text search on searchable fields
        if (!empty($query)) {
            $searchConditions = [];
            foreach ($this->searchableFields as $field) {
                $searchConditions[] = "`$field` LIKE ?";
                $params[] = '%' . $query . '%';
            }
            if (!empty($searchConditions)) {
                $where[] = '(' . implode(' OR ', $searchConditions) . ')';
            }
        }

        // Apply filters
        foreach ($filters as $key => $value) {
            if (is_array($value)) {
                // Range filter
                $where[] = "`$key` BETWEEN ? AND ?";
                $params[] = $value[0];
                $params[] = $value[1];
            } else {
                $where[] = "`$key` = ?";
                $params[] = $value;
            }
        }

        $whereClause = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);

        // Get total count
        $countSql = "SELECT COUNT(*) FROM `{$this->table}`{$whereClause}";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Get results
        $sql = "SELECT * FROM `{$this->table}`{$whereClause} ORDER BY `$sort` $order LIMIT ? OFFSET ?";
        $stmt = $this->db->prepare($sql);
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return [
            'results' => $results,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'pages' => (int) ceil($total / $limit),
            'current_page' => (int) floor($offset / $limit) + 1,
        ];
    }

    /**
     * Search by type and subtype.
     */
    public function byType(string $type, string $subtype, array $options = []): array
    {
        return $this->query('', [
            'type' => $type,
            'subtype' => $subtype,
        ], $options);
    }

    /**
     * Search by owner.
     */
    public function byOwner(string $ownerId, array $options = []): array
    {
        return $this->query('', [
            'owner_id' => $ownerId,
        ], $options);
    }

    /**
     * Search by metadata.
     */
    public function byMetadata(string $key, string $value, array $options = []): array
    {
        $limit = $options['limit'] ?? $this->defaultLimit;
        $offset = $options['offset'] ?? 0;

        $sql = "SELECT e.* FROM `{$this->table}` e
                INNER JOIN `entity_metadata` em ON e.id = em.entity_id
                WHERE em.`key` = ? AND em.`value` = ?
                ORDER BY e.created_at DESC LIMIT ? OFFSET ?";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$key, $value, $limit, $offset]);

        return [
            'results' => $stmt->fetchAll(\PDO::FETCH_ASSOC),
            'total' => $this->countByMetadata($key, $value),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Count by metadata.
     */
    protected function countByMetadata(string $key, string $value): int
    {
        $sql = "SELECT COUNT(*) FROM `entity_metadata` WHERE `key` = ? AND `value` = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$key, $value]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Set searchable fields.
     */
    public function setSearchableFields(array $fields): void
    {
        $this->searchableFields = $fields;
    }

    /**
     * Add a searchable field.
     */
    public function addSearchableField(string $field): void
    {
        if (!in_array($field, $this->searchableFields)) {
            $this->searchableFields[] = $field;
        }
    }

    /**
     * Get search suggestions partial query.
     */
    public function suggestions(string $partial, int $limit = 10): array
    {
        if (empty($this->searchableFields)) {
            return [];
        }

        $conditions = [];
        $params = [];
        foreach ($this->searchableFields as $field) {
            $conditions[] = "`$field` LIKE ?";
            $params[] = $partial . '%';
        }

        $sql = "SELECT DISTINCT `{$this->searchableFields[0]}` FROM `{$this->table}`
                WHERE " . implode(' OR ', $conditions) . " LIMIT ?";
        $params[] = $limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Advanced search with multiple criteria.
     */
    public function advanced(array $criteria, array $options = []): array
    {
        $where = [];
        $params = [];

        foreach ($criteria as $field => $condition) {
            if (is_array($condition)) {
                [$operator, $value] = $condition;
                $where[] = "`$field` $operator ?";
                $params[] = $value;
            } else {
                $where[] = "`$field` = ?";
                $params[] = $condition;
            }
        }

        $limit = $options['limit'] ?? $this->defaultLimit;
        $offset = $options['offset'] ?? 0;

        $whereClause = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);

        $sql = "SELECT * FROM `{$this->table}`{$whereClause} ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return [
            'results' => $stmt->fetchAll(\PDO::FETCH_ASSOC),
            'total' => $this->countAdvanced($criteria),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Count advanced search results.
     */
    protected function countAdvanced(array $criteria): int
    {
        $where = [];
        $params = [];

        foreach ($criteria as $field => $condition) {
            if (is_array($condition)) {
                [$operator, $value] = $condition;
                $where[] = "`$field` $operator ?";
                $params[] = $value;
            } else {
                $where[] = "`$field` = ?";
                $params[] = $condition;
            }
        }

        $whereClause = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);

        $sql = "SELECT COUNT(*) FROM `{$this->table}`{$whereClause}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }
}