<?php

namespace Canyon\Core;

/**
 * Canyon Framework - Database Optimizer
 *
 * Database cleaning and optimization tools.
 * QUIC.cloud database cleaner.
 */
class DatabaseOptimizer
{
  /** @var \PDO Database connection */
  protected \PDO $db;

  /** @var array Optimization log */
  protected static array $log = [];

  /**
   * Create a new DatabaseOptimizer instance.
   */
  public function __construct(?\PDO $pdo = null)
  {
    $this->db = $pdo ?? (new Database())->getConnection();
  }

  /**
   * Clean expired sessions.
   */
  public function cleanSessions(): int
  {
    $maxLifetime = ini_get('session.gc_maxlifetime') ?: 1440;
    $stmt = $this->db->prepare(
      "DELETE FROM sessions WHERE last_access < DATE_SUB(NOW(), INTERVAL ? SECOND)"
    );
    $stmt->execute([$maxLifetime]);
    $count = $stmt->rowCount();
    self::$log[] = ['action' => 'clean_sessions', 'affected' => $count];
    return $count;
  }

  /**
   * Clean expired cache entries.
   */
  public function cleanCache(): int
  {
    $cacheDir = CANYON_STORAGE_PATH . '/cache';
    if (!is_dir($cacheDir)) {
      return 0;
    }

    $count = 0;
    $files = glob($cacheDir . '/*') ?: [];
    $now = time();

    foreach ($files as $file) {
      if (is_file($file) && $now - filemtime($file) > 3600) {
        unlink($file);
        $count++;
      }
    }

    self::$log[] = ['action' => 'clean_cache', 'affected' => $count];
    return $count;
  }

  /**
   * Clean expired queue jobs.
   */
  public function cleanQueue(): int
  {
    $queueDir = CANYON_STORAGE_PATH . '/queue';
    if (!is_dir($queueDir)) {
      return 0;
    }

    $count = 0;
    $files = glob($queueDir . '/*.job') ?: [];
    $now = time();

    foreach ($files as $file) {
      if (is_file($file) && $now - filemtime($file) > 86400) {
        unlink($file);
        $count++;
      }
    }

    self::$log[] = ['action' => 'clean_queue', 'affected' => $count];
    return $count;
  }

  /**
   * Clean old notifications.
   */
  public function cleanNotifications(int $days = 30): int
  {
    $stmt = $this->db->prepare(
      "DELETE FROM notifications WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
    );
    $stmt->execute([$days]);
    $count = $stmt->rowCount();
    self::$log[] = ['action' => 'clean_notifications', 'affected' => $count];
    return $count;
  }

  /**
   * Clean old river items.
   */
  public function cleanRiver(int $days = 90): int
  {
    $stmt = $this->db->prepare(
      "DELETE FROM river WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
    );
    $stmt->execute([$days]);
    $count = $stmt->rowCount();
    self::$log[] = ['action' => 'clean_river', 'affected' => $count];
    return $count;
  }

  /**
   * Clean old log files.
   */
  public function cleanLogs(int $days = 30): int
  {
    $logDir = CANYON_STORAGE_PATH . '/logs';
    if (!is_dir($logDir)) {
      return 0;
    }

    $count = 0;
    $files = glob($logDir . '/*') ?: [];
    $now = time();

    foreach ($files as $file) {
      if (is_file($file) && $now - filemtime($file) > ($days * 86400)) {
        unlink($file);
        $count++;
      }
    }

    self::$log[] = ['action' => 'clean_logs', 'affected' => $count];
    return $count;
  }

  /**
   * Optimize database tables.
   */
  public function optimizeTables(): int
  {
    $tables = $this->db->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);
    $count = 0;

    foreach ($tables as $table) {
      $this->db->exec("OPTIMIZE TABLE `$table`");
      $count++;
    }

    self::$log[] = ['action' => 'optimize_tables', 'affected' => $count];
    return $count;
  }

  /**
   * Analyze database tables.
   */
  public function analyzeTables(): int
  {
    $tables = $this->db->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);
    $count = 0;

    foreach ($tables as $table) {
      $this->db->exec("ANALYZE TABLE `$table`");
      $count++;
    }

    self::$log[] = ['action' => 'analyze_tables', 'affected' => $count];
    return $count;
  }

  /**
   * Get database size.
   */
  public function getDatabaseSize(): array
  {
    $stmt = $this->db->prepare(
      "SELECT table_schema AS database_name,
          SUM(data_length + index_length) AS size_bytes,
          SUM(data_length) AS data_bytes,
          SUM(index_length) AS index_bytes
      FROM information_schema.tables
      WHERE table_schema = DATABASE()
      GROUP BY table_schema"
    );
    $stmt->execute();
    return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
  }

  /**
   * Get table sizes.
   */
  public function getTableSizes(): array
  {
    $stmt = $this->db->prepare(
      "SELECT table_name,
          table_rows,
          data_length,
          index_length,
          (data_length + index_length) AS total_size
      FROM information_schema.tables
      WHERE table_schema = DATABASE()
      ORDER BY total_size DESC"
    );
    $stmt->execute();
    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Clean orphaned metadata.
   */
  public function cleanOrphanedMetadata(): int
  {
    $stmt = $this->db->prepare(
      "DELETE em FROM entity_metadata em
      LEFT JOIN entities e ON em.entity_id = e.id
      WHERE e.id IS NULL"
    );
    $stmt->execute();
    $count = $stmt->rowCount();
    self::$log[] = ['action' => 'clean_orphaned_metadata', 'affected' => $count];
    return $count;
  }

  /**
   * Run all cleanup tasks.
   */
  public function runAllCleanup(): array
  {
    return [
      'sessions' => $this->cleanSessions(),
      'cache' => $this->cleanCache(),
      'queue' => $this->cleanQueue(),
      'notifications' => $this->cleanNotifications(),
      'river' => $this->cleanRiver(),
      'logs' => $this->cleanLogs(),
      'metadata' => $this->cleanOrphanedMetadata(),
    ];
  }

  /**
   * Get optimization log.
   */
  public static function getLog(): array
  {
    return self::$log;
  }

  /**
   * Clear optimization log.
   */
  public static function clearLog(): void
  {
    self::$log = [];
  }

  /**
   * Get database health report.
   */
  public function healthReport(): array
  {
    return [
      'size' => $this->getDatabaseSize(),
      'tables' => $this->getTableSizes(),
      'cleanup_log' => self::$log,
      'timestamp' => date('Y-m-d H:i:s'),
    ];
  }
}