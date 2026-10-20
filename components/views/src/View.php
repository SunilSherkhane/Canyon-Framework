<?php

namespace Canyon\Core\Views;

use Canyon\Core\Database;
use Canyon\Core\Cache;

/**
 * Views Module - Dynamic Content Lists
 *
 * Similar to Drupal's Views module.
 * Provides ORM-like query building, filtering, sorting, and pagination.
 */
class View
{
    /**
     * Entity type to query.
     */
    protected string $baseTable;

    /**
     * Fields to select.
     */
    protected array $fields = ['*'];

    /**
     * Where conditions.
     */
    protected array $conditions = [];

    /**
     * Join clauses.
     */
    protected array $joins = [];

    /**
     * Order by clause.
     */
    protected array $orderBy = [];

    /**
     * Limit and offset.
     */
    protected int $limit = 0;
    protected int $offset = 0;

    /**
     * Group by clause.
     */
    protected array $groupBy = [];

    /**
     * Having conditions.
     */
    protected array $having = [];

    /**
     * Cache options.
     */
    protected ?int $cache = null;

    /**
     * Constructor.
     */
    public function __construct(string $baseTable)
    {
        $this->baseTable = $baseTable;
    }

    /**
     * Add a field to select.
     */
    public function field(string $field, ?string $alias = null): self
    {
        $this->fields[] = $alias ? "{$alias} as {$field}" : $field;
        return $this;
    }

    /**
     * Add a select all fields.
     */
    public function fields(): self
    {
        $this->fields = ['*'];
        return $this;
    }

    /**
     * Add a where condition.
     */
    public function where(string $field, string $operator, mixed $value): self
    {
        $this->conditions[] = [
            'field' => $field,
            'operator' => $operator,
            'value' => $value,
        ];
        return $this;
    }

    /**
     * Add an OR where condition.
     */
    public function orWhere(string $field, string $operator, mixed $value): self
    {
        $this->conditions[] = [
            'field' => $field,
            'operator' => $operator,
            'value' => $value,
            'or' => true,
        ];
        return $this;
    }

    /**
     * Add an IN condition.
     */
    public function whereIn(string $field, array $values): self
    {
        return $this->where($field, 'IN', $values);
    }

    /**
     * Add a NOT IN condition.
     */
    public function whereNotIn(string $field, array $values): self
    {
        return $this->where($field, 'NOT IN', $values);
    }

    /**
     * Add a join clause.
     */
    public function join(string $table, string $condition, string $type = 'LEFT JOIN'): self
    {
        $this->joins[] = [
            'table' => $table,
            'condition' => $condition,
            'type' => $type,
        ];
        return $this;
    }

    /**
     * Add an inner join.
     */
    public function innerJoin(string $table, string $condition): self
    {
        return $this->join($table, $condition, 'INNER JOIN');
    }

    /**
     * Add a left join.
     */
    public function leftJoin(string $table, string $condition): self
    {
        return $this->join($table, $condition, 'LEFT JOIN');
    }

    /**
     * Add order by.
     */
    public function orderBy(string $field, string $direction = 'ASC'): self
    {
        $this->orderBy[] = " {$field} {$direction}";
        return $this;
    }

    /**
     * Add descending order.
     */
    public function orderByDesc(string $field): self
    {
        return $this->orderBy($field, 'DESC');
    }

    /**
     * Set limit.
     */
    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * Set offset.
     */
    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    /**
     * Set group by.
     */
    public function groupBy(string $field): self
    {
        $this->groupBy[] = $field;
        return $this;
    }

    /**
     * Set cache TTL.
     */
    public function cache(int $ttl = 3600): self
    {
        $this->cache = $ttl;
        return $this;
    }

    /**
     * Execute the query and get results.
     */
    public function get(): array
    {
        $key = $this->getCacheKey();
        if ($key) {
            $cached = Cache::get($key);
            if ($cached !== null) {
                return $cached;
            }
        }

        $sql = $this->buildQuery();
        $results = Database::statement($sql, $this->getBindings());

        if ($key) {
            Cache::put($key, $results, $this->cache);
        }

        return $results;
    }

    /**
     * Get first result.
     */
    public function first(): ?array
    {
        $result = $this->limit(1)->get();
        return $result[0] ?? null;
    }

    /**
     * Count results.
     */
    public function count(): int
    {
        $countField = $this->fields[0] ?? '*';
        $oldFields = $this->fields;
        $this->fields = ["COUNT({$countField}) as count"];

        $result = $this->get();
        $this->fields = $oldFields;

        return (int)($result[0]['count'] ?? 0);
    }

    /**
     * Get SQL for debugging.
     */
    public function toSql(): string
    {
        return $this->buildQuery();
    }

    /**
     * Build the SQL query.
     */
    protected function buildQuery(): string
    {
        $sql = "SELECT " . implode(', ', $this->fields);
        $sql .= " FROM {$this->baseTable}";

        foreach ($this->joins as $join) {
            $sql .= " {$join['type']} {$join['table']} ON {$join['condition']}";
        }

        $sql .= $this->buildWhere();
        $sql .= $this->buildGroupBy();
        $sql .= $this->buildHaving();
        $sql .= $this->buildOrderBy();

        if ($this->limit > 0) {
            $sql .= " LIMIT {$this->limit}";
        }
        if ($this->offset > 0) {
            $sql .= " OFFSET {$this->offset}";
        }

        return $sql;
    }

    /**
     * Build WHERE clause.
     */
    protected function buildWhere(): string
    {
        if (empty($this->conditions)) return '';

        $parts = [];
        foreach ($this->conditions as $cond) {
            $isOr = !empty($cond['or']);
            $field = $cond['field'];
            $operator = $cond['operator'];
            $value = $cond['value'];

            if ($operator === 'IN' || $operator === 'NOT IN') {
                $placeholders = implode(',', array_fill(0, count($value), '?'));
                $parts[] = "({$field} {$operator} ({$placeholders}))";
            } else {
                $parts[] = "({$field} {$operator} ?)";
            }
        }

        return ' WHERE ' . implode(' OR ', $parts);
    }

    /**
     * Build GROUP BY clause.
     */
    protected function buildGroupBy(): string
    {
        if (empty($this->groupBy)) return '';
        return ' GROUP BY ' . implode(', ', $this->groupBy);
    }

    /**
     * Build HAVING clause.
     */
    protected function buildHaving(): string
    {
        if (empty($this->having)) return '';
        return ' HAVING ' . implode(' AND ', $this->having);
    }

    /**
     * Build ORDER BY clause.
     */
    protected function buildOrderBy(): string
    {
        if (empty($this->orderBy)) return '';
        return ' ORDER BY ' . ltrim(implode('', $this->orderBy));
    }

    /**
     * Get query parameters.
     */
    protected function getBindings(): array
    {
        $bindings = [];
        foreach ($this->conditions as $cond) {
            if ($cond['operator'] === 'IN' || $cond['operator'] === 'NOT IN') {
                $bindings = array_merge($bindings, $cond['value']);
            } else {
                $bindings[] = $cond['value'];
            }
        }
        return $bindings;
    }

    /**
     * Get cache key.
     */
    protected function getCacheKey(): ?string
    {
        if (!$this->cache) return null;

        return 'view:' . md5($this->baseTable . serialize($this->conditions) . serialize($this->orderBy) . $this->limit . $this->offset);
    }
}

/**
 * View Builder - Create views easily.
 */
class ViewBuilder
{
    /**
     * Create a new view.
     */
    public static function of(string $baseTable): View
    {
        return new View($baseTable);
    }

    /**
     * Content view (shortcut to content table).
     */
    public static function content(): View
    {
        return new View('content');
    }

    /**
     * User view.
     */
    public static function user(): View
    {
        return new View('users');
    }

    /**
     * Comment view.
     */
    public static function comment(): View
    {
        return new View('comments');
    }
}