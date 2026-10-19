<?php

namespace Canyon\Core;

/**
 * Canyon Framework - Entity System
 *
 * Flexible entity system for prototyping content types and interactions.
 * Elgg's entity architecture.
 */
class Entity
{
    /** @var \PDO Database connection */
    protected \PDO $db;

    /** @var string Entity table name */
    protected string $table;

    /** @var array Primary key columns */
    protected array $primaryKey = ['id'];

    /** @var array Fillable attributes */
    protected array $fillable = [];

    /** @var array Casts */
    protected array $casts = [];

    /** @var array Additional properties */
    protected array $attributes = [];

    /** @var array Loaded relations */
    protected array $relations = [];

    /** @var array Access control rules */
    protected array $access = [];

    /**
     * Create a new Entity instance.
     *
     * @param array $attributes Initial attributes
     */
    public function __construct(array $attributes = [])
    {
        $this->db = (new Database())->getConnection();
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }
    }

    // -----------------------------------------------------------------------
    // Attributes
    // -----------------------------------------------------------------------

    /**
     * Set an attribute.
     */
    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * Get an attribute.
     */
    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Get all attributes.
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Check if attribute exists.
     */
    public function hasAttribute(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    // -----------------------------------------------------------------------
    // Casting
    // -----------------------------------------------------------------------

    /**
     * Cast an attribute to a specific type.
     */
    protected function cast(string $key, mixed $value): mixed
    {
        if (!isset($this->casts[$key])) {
            return $value;
        }

        $type = $this->casts[$key];
        return match ($type) {
            'int', 'integer' => (int) $value,
            'float', 'decimal' => (float) $value,
            'boolean', 'bool' => (bool) $value,
            'array', 'json' => is_string($value) ? json_decode($value, true) : $value,
            'collection' => new Collection((array) json_decode($value, true)),
            'encrypted' => $this->decrypt($value),
            default => $value,
        };
    }

    /**
     * Encrypt a value.
     */
    protected function encrypt(mixed $value): string
    {
        $key = $_ENV['APP_KEY'] ?? 'base64:';
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }
        $key = strlen($key) >= 32 ? substr($key, 0, 32) : str_pad($key, 32, "\0");
        $iv = random_bytes(16);
        return base64_encode($iv . openssl_encrypt($value, 'AES-256-CBC', $key, 0, $iv));
    }

    /**
     * Decrypt a value.
     */
    protected function decrypt(string $value): mixed
    {
        $key = $_ENV['APP_KEY'] ?? 'base64:';
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }
        $key = strlen($key) >= 32 ? substr($key, 0, 32) : str_pad($key, 32, "\0");
        $data = base64_decode($value);
        $iv = substr($data, 0, 16);
        $cipher = substr($data, 16);
        return openssl_decrypt($cipher, 'AES-256-CBC', $key, 0, $iv);
    }

    // -----------------------------------------------------------------------
    // Persistence
    // -----------------------------------------------------------------------

    /**
     * Save the entity to database.
     */
    public function save(): bool
    {
        if (isset($this->attributes['id']) && $this->attributes['id']) {
            return $this->update();
        }
        return $this->insert();
    }

    /**
     * Insert as a new record.
     */
    protected function insert(): bool
    {
        $data = $this->prepareData();
        $columns = implode(', ', array_map(fn($k) => "`$k`", array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $stmt = $this->db->prepare("INSERT INTO `{$this->table}` ($columns) VALUES ($placeholders)");
        $result = $stmt->execute(array_values($data));

        if ($result) {
            $this->attributes['id'] = (int) $this->db->lastInsertId();
        }

        return $result;
    }

    /**
     * Update an existing record.
     */
    protected function update(): bool
    {
        $data = $this->prepareData();
        $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));

        $where = [];
        $params = array_values($data);
        foreach ($this->primaryKey as $key) {
            $where[] = "`$key` = ?";
            $params[] = $this->attributes[$key];
        }

        $stmt = $this->db->prepare("UPDATE `{$this->table}` SET $set WHERE " . implode(' AND ', $where));
        return $stmt->execute($params);
    }

    /**
     * Delete this entity.
     */
    public function delete(): bool
    {
        $where = [];
        $params = [];
        foreach ($this->primaryKey as $key) {
            $where[] = "`$key` = ?";
            $params[] = $this->attributes[$key];
        }

        $stmt = $this->db->prepare("DELETE FROM `{$this->table}` WHERE " . implode(' AND ', $where));
        return $stmt->execute($params);
    }

    /**
     * Find an entity by primary key.
     */
    public static function find(string $className, int $id): ?static
    {
        $instance = new static();
        $pk = $instance->primaryKey[0];
        $stmt = $instance->db->prepare("SELECT * FROM `{$instance->table}` WHERE `$pk` = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $instance->fill($row);
        return $instance;
    }

    /**
     * Find or create by attributes.
     */
    public static function firstOrCreate(string $className, array $attributes, array $values = []): static
    {
        $instance = static::first($className, $attributes);
        if ($instance) {
            return $instance;
        }
        $instance = new static($attributes);
        $instance->setExtraAttributes($values);
        $instance->save();
        return $instance;
    }

    /**
     * Find first matching record.
     */
    public static function first(string $className, array $attributes): ?static
    {
        $instance = new static();
        $where = [];
        $params = [];
        foreach ($attributes as $key => $value) {
            $where[] = "`$key` = ?";
            $params[] = $value;
        }

        $sql = "SELECT * FROM `{$instance->table}`" . (empty($where) ? '' : " WHERE " . implode(' AND ', $where));
        $stmt = $instance->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $instance->fill($row);
        return $instance;
    }

    /**
     * Get all entities.
     */
    public static function all(string $className, int $limit = 100): array
    {
        $instance = new static();
        $stmt = $instance->db->prepare("SELECT * FROM `{$instance->table}` LIMIT ?");
        $stmt->execute([$limit]);
        return array_map(fn($row) => (new static($row)), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Prepare data for insert/update.
     */
    protected function prepareData(): array
    {
        $data = [];
        foreach ($this->attributes as $key => $value) {
            if (in_array($key, $this->primaryKey) && $value === null) {
                continue; // Skip null primary keys on update
            }
            $data[$key] = $this->casts[$key] ?? $value;
        }
        return $data;
    }

    /**
     * Fill attributes from array.
     */
    public function fill(array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            if (in_array($key, $this->fillable) || empty($this->fillable)) {
                $this->setAttribute($key, $value);
            }
        }
    }

    /**
     * Set extra attributes.
     */
    public function setExtraAttributes(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->attributes[$key] = $value;
        }
    }

    /**
     * Check access permissions.
     */
    public function hasAccess(string $permission, mixed $user = null): bool
    {
        $user = $user ?? Session::user();
        if ($user === null) {
            return false;
        }
        return $this->access[$permission] ?? false;
    }

    /**
     * Set access permissions.
     */
    public function setAccess(array $permissions): void
    {
        $this->access = $permissions;
    }

    /**
     * Get entity type.
     */
    public function getEntityType(): string
    {
        return strtolower((new \ReflectionClass($this))->getShortName());
    }

    /**
     * Get entity GUID (globally unique identifier).
     */
    public function getGuid(): string
    {
        return $this->getEntityType() . '_' . ($this->attributes['id'] ?? uniqid());
    }
}