<?php

namespace Canyon\Core;

/**
 * Canyon Framework - Lock
 *
 * Distributed locking with file, database, and memory stores.
 */
class Lock
{
  /** @var string Lock name */
  protected string $name;

  /** @var string Lock owner token */
  protected string $token;

  /** @var bool Whether lock is acquired */
  protected bool $acquired = false;

  /** @var int Max wait time (seconds) */
  protected int $waitTimeout;

  /** @var int Lock TTL (seconds) */
  protected int $ttl;

  /** @var string Lock store: file|database|memory */
  protected string $store;

  /** @var array Memory locks */
  protected static array $memoryLocks = [];

  /** @var string Lock directory */
  protected static string $lockDir;

  /** @var Database|null Database instance */
  protected ?Database $db = null;

  /**
   * Constructor.
   */
  public function __construct(string $name, int $ttl = 30, int $waitTimeout = 10, string $store = 'file')
  {
    $this->name = $name;
    $this->ttl = $ttl;
    $this->waitTimeout = $waitTimeout;
    $this->store = $store;
    $this->token = bin2hex(random_bytes(16));

    if ($store === 'file') {
      self::initFileStore();
    } elseif ($store === 'database') {
      $this->db = new Database();
    }
  }

  /**
   * Create a new lock.
   */
  public static function create(string $name, int $ttl = 30, int $waitTimeout = 10, string $store = 'file'): self
  {
    return new self($name, $ttl, $waitTimeout, $store);
  }

  // -----------------------------------------------------------------------
  // Lock Operations
  // -----------------------------------------------------------------------

  /**
   * Acquire the lock.
   */
  public function acquire(): bool
  {
    if ($this->acquired) {
      return true;
    }

    $startTime = time();

    while (true) {
      if ($this->tryAcquire()) {
        $this->acquired = true;
        return true;
      }

      // Check wait timeout
      if (time() - $startTime >= $this->waitTimeout) {
        return false;
      }

      // Wait before retry
      usleep(100000); // 100ms
    }
  }

  /**
   * Try to acquire lock (non-blocking).
   */
  public function tryAcquire(): bool
  {
    return match ($this->store) {
      'memory' => $this->tryAcquireMemory(),
      'database' => $this->tryAcquireDatabase(),
      default => $this->tryAcquireFile(),
    };
  }

  /**
   * Release the lock.
   */
  public function release(): bool
  {
    if (!$this->acquired) {
      return false;
    }

    $result = match ($this->store) {
      'memory' => $this->releaseMemory(),
      'database' => $this->releaseDatabase(),
      default => $this->releaseFile(),
    };

    $this->acquired = false;
    return $result;
  }

  /**
   * Check if lock is held.
   */
  public function isHeld(): bool
  {
    return match ($this->store) {
      'memory' => isset(self::$memoryLocks[$this->name]),
      'database' => $this->isHeldDatabase(),
      default => $this->isHeldFile(),
    };
  }

  /**
   * Check if lock is held by current owner.
   */
  public function isAcquired(): bool
  {
    return $this->acquired;
  }

  /**
   * Execute callback while holding lock.
   */
  public function synchronize(callable $callback): mixed
  {
    if (!$this->acquire()) {
      throw new \RuntimeException("Could not acquire lock: {$this->name}");
    }

    try {
      return $callback();
    } finally {
      $this->release();
    }
  }

  // -----------------------------------------------------------------------
  // File Store
  // -----------------------------------------------------------------------

  protected static function initFileStore(): void
  {
    if (self::$lockDir === null) {
      self::$lockDir = CANYON_STORAGE_PATH . '/locks';
      if (!is_dir(self::$lockDir)) {
        mkdir(self::$lockDir, 0750, true);
      }
    }
  }

  protected function tryAcquireFile(): bool
  {
    $file = $this->getLockFile();
    $expiresAt = time() + $this->ttl;

    // Check if lock exists and is valid
    if (file_exists($file)) {
      $data = unserialize(file_get_contents($file), ['allowed_classes' => false]);
      if (!is_array($data) || !isset($data['expires_at'])) {
        unlink($file);
      } elseif ($data['expires_at'] > time()) {
        return false;
      }
      // Lock expired, remove it
      unlink($file);
    }

    // Try to create lock file
    $data = [
      'token' => $this->token,
      'expires_at' => $expiresAt,
      'created_at' => time(),
    ];

    // Use atomic file creation
    $tempFile = $file . '.tmp.' . $this->token;
    if (file_put_contents($tempFile, serialize($data)) === false) {
      return false;
    }

    if (!rename($tempFile, $file)) {
      unlink($tempFile);
      return false;
    }

    return true;
  }

  protected function releaseFile(): bool
  {
    $file = $this->getLockFile();
    if (!file_exists($file)) {
      return true;
    }

    $data = unserialize(file_get_contents($file), ['allowed_classes' => false]);
    if (!is_array($data) || ($data['token'] ?? '') !== $this->token) {
      return false; // Not our lock
    }

    return unlink($file);
  }

  protected function isHeldFile(): bool
  {
    $file = $this->getLockFile();
    if (!file_exists($file)) {
      return false;
    }

    $data = unserialize(file_get_contents($file), ['allowed_classes' => false]);
    return is_array($data) && isset($data['expires_at']) && $data['expires_at'] > time();
  }

  protected function getLockFile(): string
  {
    return self::$lockDir . '/' . md5($this->name) . '.lock';
  }

  // -----------------------------------------------------------------------
  // Memory Store
  // -----------------------------------------------------------------------

  protected function tryAcquireMemory(): bool
  {
    if (isset(self::$memoryLocks[$this->name])) {
      $lock = self::$memoryLocks[$this->name];
      if ($lock['expires_at'] > time()) {
        return false;
      }
      unset(self::$memoryLocks[$this->name]);
    }

    self::$memoryLocks[$this->name] = [
      'token' => $this->token,
      'expires_at' => time() + $this->ttl,
      'created_at' => time(),
    ];

    return true;
  }

  protected function releaseMemory(): bool
  {
    if (!isset(self::$memoryLocks[$this->name])) {
      return true;
    }

    if (self::$memoryLocks[$this->name]['token'] !== $this->token) {
      return false;
    }

    unset(self::$memoryLocks[$this->name]);
    return true;
  }

  // -----------------------------------------------------------------------
  // Database Store
  // -----------------------------------------------------------------------

  protected function tryAcquireDatabase(): bool
  {
    $table = 'locks';
    $expiresAt = time() + $this->ttl;

    // Check existing lock
    $existing = $this->db->select($table, ['*'], ['name' => $this->name]);
    if (!empty($existing)) {
      if ($existing[0]['expires_at'] > time()) {
        return false;
      }
      $this->db->delete($table, ['name' => $this->name]);
    }

    // Insert new lock
    $this->db->insert($table, [
      'name' => $this->name,
      'token' => $this->token,
      'expires_at' => $expiresAt,
      'created_at' => time(),
    ]);

    return true;
  }

  protected function releaseDatabase(): bool
  {
    if (!$this->db) return false;

    $existing = $this->db->select('locks', ['*'], ['name' => $this->name]);
    if (empty($existing) || $existing[0]['token'] !== $this->token) {
      return false;
    }

    return $this->db->delete('locks', ['name' => $this->name]) > 0;
  }

  protected function isHeldDatabase(): bool
  {
    if (!$this->db) return false;

    $existing = $this->db->select('locks', ['*'], ['name' => $this->name]);
    return !empty($existing) && $existing[0]['expires_at'] > time();
  }

  // -----------------------------------------------------------------------
  // Utilities
  // -----------------------------------------------------------------------

  /**
   * Get lock name.
   */
  public function getName(): string
  {
    return $this->name;
  }

  /**
   * Get lock token.
   */
  public function getToken(): string
  {
    return $this->token;
  }

  /**
   * Get remaining TTL.
   */
  public function getTtl(): int
  {
    return $this->ttl;
  }

  /**
   * Extend lock TTL.
   */
  public function extend(int $ttl): bool
  {
    if (!$this->acquired) {
      return false;
    }

    $this->ttl = $ttl;

    return match ($this->store) {
      'file' => $this->extendFile($ttl),
      'database' => $this->extendDatabase($ttl),
      'memory' => $this->extendMemory($ttl),
      default => false,
    };
  }

  protected function extendFile(int $ttl): bool
  {
    $file = $this->getLockFile();
    if (!file_exists($file)) return false;

    $data = unserialize(file_get_contents($file), ['allowed_classes' => false]);
    if (!is_array($data) || ($data['token'] ?? '') !== $this->token) {
      return false;
    }

    $data['expires_at'] = time() + $ttl;
    return file_put_contents($file, serialize($data)) !== false;
  }

  protected function extendDatabase(int $ttl): bool
  {
    if (!$this->db) return false;
    return $this->db->update('locks', ['expires_at' => time() + $ttl], ['name' => $this->name, 'token' => $this->token]) > 0;
  }

  protected function extendMemory(int $ttl): bool
  {
    if (!isset(self::$memoryLocks[$this->name])) return false;
    if (self::$memoryLocks[$this->name]['token'] !== $this->token) return false;

    self::$memoryLocks[$this->name]['expires_at'] = time() + $ttl;
    return true;
  }

  /**
   * Get all memory locks.
   */
  public static function getMemoryLocks(): array
  {
    return self::$memoryLocks;
  }

  /**
   * Clear all memory locks.
   */
  public static function clearMemoryLocks(): void
  {
    self::$memoryLocks = [];
  }

  /**
   * Clear all file locks.
   */
  public static function clearFileLocks(): void
  {
    self::initFileStore();
    $files = glob(self::$lockDir . '/*.lock');
    foreach ($files as $file) {
      unlink($file);
    }
  }

  /**
   * Clear all database locks.
   */
  public static function clearDatabaseLocks(): void
  {
    $db = new Database();
    $db->delete('locks', []);
  }
}