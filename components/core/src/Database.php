<?php

namespace Canyon\Core;

/**
 * Canyon Framework Database Layer
 *
 * Simple PDO-based database abstraction layer.
 * Provides query building, prepared statements, and result handling.
 */
class Database
{
  /** @var \PDO|null Database connection instance */
  protected ?\PDO $pdo = null;

  /** @var array Configuration for the active connection */
  protected array $config = [];

  /**
   * Create a new database connection using the default configuration.
   */
  public function __construct()
  {
    $this->config = $this->resolveConfig();
    $this->connect();
  }

  /**
   * Resolve database configuration from environment or defaults.
   */
  protected function resolveConfig(): array
  {
    $connection = env('DB_CONNECTION', 'mysql');

    return [
      'driver'  => $connection,
      'host'   => env('DB_HOST', '127.0.0.1'),
      'port'   => env('DB_PORT', $connection === 'sqlite' ? '' : 3306),
      'database' => env('DB_DATABASE', 'canyon'),
      'username' => env('DB_USERNAME', 'root'),
      'password' => env('DB_PASSWORD', ''),
      'charset' => env('DB_CHARSET', 'utf8mb4'),
    ];
  }

  /**
   * Establish the PDO connection.
   */
  protected function connect(): void
  {
    $config = $this->config;
    $driver = $config['driver'];

    try {
      if ($driver === 'sqlite') {
        $path = $config['database'];
        $this->pdo = new \PDO("sqlite:$path");
      } else {
        $dsn = "$driver:host={$config['host']};port={$config['port']};dbname={$config['database']};charset={$config['charset']}";
        $this->pdo = new \PDO(
          $dsn,
          $config['username'],
          $config['password'],
          [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
          ]
        );
      }
    } catch (\PDOException $e) {
      // Log the error but don't expose in production
      if (env('APP_DEBUG', false)) {
        throw new \RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
      }
      throw new \RuntimeException('Database connection failed.');
    }
  }

  /**
   * Get the PDO instance.
   */
  public function getConnection(): \PDO
  {
    if ($this->pdo === null) {
      $this->connect();
    }
    return $this->pdo;
  }

  // -----------------------------------------------------------------------
  // Query execution
  // -----------------------------------------------------------------------

  /**
   * Execute a raw SQL query with optional bindings.
   *
   * @param string $sql The SQL query
   * @param array $bindings Values to bind to the query
   * @return \PDOStatement The executed statement
   */
  public function statement(string $sql, array $bindings = []): \PDOStatement
  {
    $stmt = $this->getConnection()->prepare($sql);
    $stmt->execute($bindings);
    return $stmt;
  }

  /**
   * Execute a raw SQL query statically (convenience method).
   *
   * @param string $sql The SQL query
   * @param array $bindings Values to bind to the query
   * @return \PDOStatement The executed statement
   */
  public static function query(string $sql, array $bindings = []): \PDOStatement
  {
    return (new self())->statement($sql, $bindings);
  }

  /**
   * Select rows from a table.
   *
   * @param string $table Table name
   * @param array|string $columns Columns to select (default: *)
   * @param array $where Where conditions [column, operator, value] or [column => value]
   * @param int|null $limit Limit results
   * @param int|null $offset Offset results
   * @return array Selected rows
   */
  public function select(
    string $table,
    array|string $columns = ['*'],
    array $where = [],
    ?int $limit = null,
    ?int $offset = null
  ): array {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $columns = is_array($columns) ? implode(', ', array_map(fn($col) => "`" . str_replace("`", "``", $col) . "`", $columns)) : $columns;

    $sql = "SELECT $columns FROM `$table`";
    $bindings = [];
    $params = [];

    if (!empty($where)) {
      $conditions = [];
      foreach ($where as $key => $value) {
        if (is_array($value)) {
          // [operator, value] format — operator must be a safe comparison token
          $operator = preg_replace('/[^a-zA-Z0-9_<>=!]/', '', $value[0]);
          if (!in_array($operator, ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'IN', 'IS NULL', 'IS NOT NULL', 'BETWEEN'], true)) {
            $operator = '=';
          }
          $conditions[] = "`" . str_replace("`", "``", $key) . "` " . $operator . ' ?';
          $bindings[] = $value[1] ?? null;
        } else {
          // [column => value] format - exact match
          $conditions[] = "`$key` = ?";
          $bindings[] = $value;
        }
      }
      $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }

    if ($limit !== null) {
      $sql .= ' LIMIT :limit';
      $bindings[':limit'] = $limit;
    }

    if ($offset !== null) {
      $sql .= ' OFFSET :offset';
      $bindings[':offset'] = $offset;
    }

    return $this->statement($sql, $bindings)->fetchAll();
  }

  /**
   * Insert a row into a table.
   *
   * @param string $table Table name
   * @param array $data Column => value pairs
   * @return int The insert ID
   */
  public function insert(string $table, array $data): int
  {
    if (empty($data)) {
      throw new \InvalidArgumentException('Cannot insert empty data');
    }

    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $columns = implode(', ', array_map(fn($col) => "`" . str_replace("`", "``", $col) . "`", array_keys($data)));
    $placeholders = implode(', ', array_fill(0, count($data), '?'));

    $sql = "INSERT INTO `$table` ($columns) VALUES ($placeholders)";

    $this->statement($sql, array_values($data));

    return (int) $this->getConnection()->lastInsertId();
  }

  /**
   * Update rows in a table.
   *
   * @param string $table Table name
   * @param array $data Column => value pairs to update
   * @param array $where Where conditions
   * @return int Number of affected rows
   */
  public function update(string $table, array $data, array $where = []): int
  {
    if (empty($data)) {
      return 0;
    }

    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $set = implode(', ', array_map(fn($col) => "`" . str_replace("`", "``", $col) . "` = ?", array_keys($data)));
    $sql = "UPDATE `$table` SET $set";
    $bindings = array_values($data);

    if (!empty($where)) {
      $conditions = [];
      foreach ($where as $key => $value) {
        if (is_array($value)) {
          $operator = preg_replace('/[^a-zA-Z0-9_<>=!]/', '', $value[0]);
          if (!in_array($operator, ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'IS NULL', 'IS NOT NULL'], true)) {
            $operator = '=';
          }
          $conditions[] = "`" . str_replace("`", "``", $key) . "` " . $operator . ' ?';
          $bindings[] = $value[1] ?? null;
        } else {
          $conditions[] = "`" . str_replace("`", "``", $key) . "` = ?";
          $bindings[] = $value;
        }
      }
      $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }

    return $this->statement($sql, $bindings)->rowCount();
  }

  /**
   * Delete rows from a table.
   *
   * @param string $table Table name
   * @param array $where Where conditions
   * @return int Number of deleted rows
   */
  public function delete(string $table, array $where = []): int
  {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $sql = "DELETE FROM `$table`";

    if (!empty($where)) {
      $conditions = [];
      $bindings = [];
      foreach ($where as $key => $value) {
        if (is_array($value)) {
          $operator = preg_replace('/[^a-zA-Z0-9_<>=!]/', '', $value[0]);
          if (!in_array($operator, ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'IS NULL', 'IS NOT NULL'], true)) {
            $operator = '=';
          }
          $conditions[] = "`" . str_replace("`", "``", $key) . "` " . $operator . ' ?';
          $bindings[] = $value[1] ?? null;
        } else {
          $conditions[] = "`" . str_replace("`", "``", $key) . "` = ?";
          $bindings[] = $value;
        }
      }
      $sql .= ' WHERE ' . implode(' AND ', $conditions);
      return $this->statement($sql, $bindings)->rowCount();
    }

    // Warning: delete all rows
    return $this->statement($sql)->rowCount();
  }

  /**
   * Begin a transaction.
   */
  public function beginTransaction(): void
  {
    $this->getConnection()->beginTransaction();
  }

  /**
   * Commit a transaction.
   */
  public function commit(): void
  {
    $this->getConnection()->commit();
  }

  /**
   * Rollback a transaction.
   */
  public function rollback(): void
  {
    $this->getConnection()->rollBack();
  }

  // -----------------------------------------------------------------------
  // Transaction helper
  // -----------------------------------------------------------------------

  /**
   * Execute a callback within a transaction.
   */
  public function transaction(callable $callback): mixed
  {
    $this->beginTransaction();

    try {
      $result = $callback($this);
      $this->commit();
      return $result;
    } catch (\Throwable $e) {
      $this->rollback();
      throw $e;
    }
  }
}