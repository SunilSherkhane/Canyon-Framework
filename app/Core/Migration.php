<?php

namespace Canyon\Core;

/**
 * Canyon Framework - Migration System
 *
 * Simple database migration runner, inspired by Laravel's migrations.
 */
class Migration
{
    /** @var \PDO Database connection */
    protected \PDO $pdo;

    /** @var string Migration directory */
    protected string $migrationDir;

    /** @var string Migration log table name */
    protected string $table = 'migrations';

    /**
     * Create a new migration instance.
     */
    public function __construct(?\PDO $pdo = null, ?string $migrationDir = null)
    {
        $this->pdo = $pdo ?? (new Database())->getConnection();
        $this->migrationDir = $migrationDir ?? CANYON_BASE_PATH . '/database/migrations';
        $this->ensureTable();
    }

    /**
     * Create the migrations table if it doesn't exist.
     */
    protected function ensureTable(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS `{$this->table}` (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL,
                batch INT UNSIGNED NOT NULL,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_migration (migration)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /**
     * Run all pending migrations.
     *
     * @return array List of executed migrations
     */
    public function migrate(): array
    {
        $executed = $this->getExecuted();
        $pending = $this->getPending($executed);
        $batch = $this->getNextBatch();
        $migrated = [];

        foreach ($pending as $migrationFile) {
            $name = pathinfo($migrationFile, PATHINFO_FILENAME);

            // Load and execute the migration
            $class = $this->loadMigration($migrationFile);
            if ($class && method_exists($class, 'up')) {
                $class->up($this->pdo);
                $this->log($name, $batch);
                $migrated[] = $name;
            }
        }

        return $migrated;
    }

    /**
     * Rollback the last batch of migrations.
     *
     * @param int $steps Number of batches to rollback
     * @return array List of rolled back migrations
     */
    public function rollback(int $steps = 1): array
    {
        $lastBatch = $this->getLastBatch();
        $migrations = $this->pdo->prepare(
            "SELECT migration FROM `{$this->table}` WHERE batch = ? ORDER BY id DESC"
        );
        $migrations->execute([$lastBatch]);

        $rolledBack = [];
        foreach ($migrations->fetchAll(\PDO::FETCH_COLUMN) as $migration) {
            $file = $this->findMigrationFile($migration);
            $class = $this->loadMigration($file);
            if ($class && method_exists($class, 'down')) {
                $class->down($this->pdo);
            }
            $this->removeLog($migration);
            $rolledBack[] = $migration;
        }

        return $rolledBack;
    }

    /**
     * Reset all migrations.
     *
     * @return array List of reset migrations
     */
    public function reset(): array
    {
        $all = $this->getExecuted();
        foreach (array_reverse($all) as $migration) {
            $file = $this->findMigrationFile($migration);
            $class = $this->loadMigration($file);
            if ($class && method_exists($class, 'down')) {
                $class->down($this->pdo);
            }
            $this->removeLog($migration);
        }
        return $all;
    }

    /**
     * Get all executed migrations.
     */
    public function getExecuted(): array
    {
        $stmt = $this->pdo->prepare("SELECT migration FROM `{$this->table}` ORDER BY id ASC");
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Get all pending migrations.
     */
    public function getPending(array $executed = []): array
    {
        if (empty($executed)) {
            $executed = $this->getExecuted();
        }

        $files = glob($this->migrationDir . '/*.php') ?: [];
        sort($files);

        $pending = [];
        foreach ($files as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            if (!in_array($name, $executed)) {
                $pending[] = $file;
            }
        }

        return $pending;
    }

    /**
     * Get the next batch number.
     */
    public function getNextBatch(): int
    {
        $stmt = $this->pdo->prepare("SELECT MAX(batch) FROM `{$this->table}`");
        $stmt->execute();
        return ((int) $stmt->fetchColumn()) + 1;
    }

    /**
     * Get the last batch number.
     */
    public function getLastBatch(): int
    {
        $stmt = $this->pdo->prepare("SELECT MAX(batch) FROM `{$this->table}`");
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /**
     * Find a migration file by name.
     */
    public function findMigrationFile(string $name): ?string
    {
        $files = glob($this->migrationDir . '/' . $name . '.php') ?: [];
        return $files[0] ?? null;
    }

    /**
     * Load a migration class from file.
     */
    protected function loadMigration(string $file): ?object
    {
        if (!file_exists($file)) {
            return null;
        }

        $class = 'Migration_' . pathinfo($file, PATHINFO_FILENAME);
        if (!class_exists($class)) {
            require_once $file;
        }

        if (!class_exists($class)) {
            return null;
        }

        return new $class();
    }

    /**
     * Log an executed migration.
     */
    protected function log(string $migration, int $batch): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO `{$this->table}` (migration, batch) VALUES (?, ?)"
        );
        $stmt->execute([$migration, $batch]);
    }

    /**
     * Remove a migration log entry.
     */
    protected function removeLog(string $migration): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM `{$this->table}` WHERE migration = ?");
        $stmt->execute([$migration]);
    }

    /**
     * Check if the migrations table exists.
     */
    public function tableExists(): bool
    {
        try {
            $this->pdo->query("SELECT 1 FROM `{$this->table}` LIMIT 1");
            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    /**
     * Get migration status for display.
     */
    public function status(): array
    {
        $executed = $this->getExecuted();
        $pending = $this->getPending($executed);

        return [
            'executed' => $executed,
            'pending' => array_map(fn($f) => pathinfo($f, PATHINFO_FILENAME), $pending),
            'total' => count($executed) + count($pending),
        ];
    }

    // -----------------------------------------------------------------------
    // Cross-driver schema helpers
    // -----------------------------------------------------------------------

    /**
     * Detect the driver of an open PDO connection.
     */
    public static function driver(\PDO $pdo): string
    {
        try {
            $name = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        } catch (\Throwable) {
            $name = 'mysql';
        }

        return strtolower($name);
    }

    /**
     * Whether the connection is SQLite (the shared-hosting-friendly default).
     */
    public static function isSqlite(\PDO $pdo): bool
    {
        return self::driver($pdo) === 'sqlite';
    }

    /**
     * DDL fragment for an auto-incrementing integer primary key.
     *
     * MySQL needs AUTO_INCREMENT on an INT column; SQLite only allows it on an
     * INTEGER PRIMARY KEY, so the two are spelled differently.
     */
    public static function autoIncrementPrimaryKey(string $column = 'id'): string
    {
        return self::$activeDriver === 'sqlite'
            ? "{$column} INTEGER PRIMARY KEY AUTOINCREMENT"
            : "{$column} INT UNSIGNED AUTO_INCREMENT PRIMARY KEY";
    }

    /**
     * DDL fragment for a nullable timestamp with a default.
     *
     * SQLite has no TIMESTAMP storage class with CURRENT_TIMESTAMP-on-update
     * semantics; CURRENT_TIMESTAMP defaults are supported but ON UPDATE is not.
     */
    public static function timestampColumn(string $column, bool $nullable = false, bool $defaultNow = true, bool $updateNow = false): string
    {
        if (self::$activeDriver === 'sqlite') {
            $ddl = "{$column} DATETIME";
            if (!$nullable) {
                $ddl .= ' NOT NULL';
            }
            if ($defaultNow) {
                $ddl .= ' DEFAULT CURRENT_TIMESTAMP';
            }
            return $ddl;
        }

        $ddl = "{$column} TIMESTAMP";
        if (!$nullable) {
            $ddl .= ' NOT NULL';
        }
        if ($defaultNow) {
            $ddl .= ' DEFAULT CURRENT_TIMESTAMP';
        }
        if ($updateNow) {
            $ddl .= ' ON UPDATE CURRENT_TIMESTAMP';
        }

        return $ddl;
    }

    /**
     * Table options clause. SQLite rejects ENGINE/CHARSET entirely.
     */
    public static function tableOptions(string $charset = 'utf8mb4'): string
    {
        return self::$activeDriver === 'sqlite'
            ? ''
            : " ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$charset}_unicode_ci";
    }

    /**
     * Driver used by the helpers above; set from a connection or env().
     */
    protected static string $activeDriver = '';

    /**
     * Bind the helpers to a connection's driver.
     */
    public static function useDriver(\PDO $pdo): void
    {
        self::$activeDriver = self::driver($pdo);
    }

    /**
     * Bind the helpers to the configured connection without opening one.
     */
    public static function useEnvironmentDriver(): void
    {
        self::$activeDriver = strtolower((string) env('DB_CONNECTION', 'mysql'));
    }
}