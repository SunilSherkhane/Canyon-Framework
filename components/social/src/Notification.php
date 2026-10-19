<?php

namespace Canyon\Core;

/**
 * Canyon Framework - Notification System
 *
 * Event-driven, multi-channel notification service with database persistence.
 *
 * Supported channels:
 *  - database / onsite : always persisted, rendered in the user's notification feed.
 *  - email       : delivered through {@see Email}.
 *  - push       : delivered through a custom event listener.
 *
 * Features:
 *  - Templates (DB-backed or file-backed) with {{placeholder}} interpolation.
 *  - Per-user, per-channel preferences (silent and not).
 *  - Batch sending with transactional per-user delivery.
 *  - Read / unread tracking and unread counts.
 *  - Delivery ledger (one row per channel) with status, attempts and error log.
 *
 * Usage:
 *  $notification = new Notification();
 *  $notification->send(123, 'welcome', ['name' => 'Ada'], ['channels' => ['email']]);
 *  $notification->sendTemplate(123, 'order.placed', ['order_id' => 7]);
 *  $notification->batch([1, 2, 3], 'system.maintenance', ['window' => '02:00']);
 *
 * @package Canyon\Core
 */
class Notification
{
  // -----------------------------------------------------------------------
  // Constants
  // -----------------------------------------------------------------------

  /** Delivery channels. */
  public const CHANNEL_DATABASE = 'database';
  public const CHANNEL_EMAIL  = 'email';
  public const CHANNEL_PUSH   = 'push';

  /** Delivery status values. */
  public const STATUS_PENDING = 'pending';
  public const STATUS_SENT  = 'sent';
  public const STATUS_FAILED = 'failed';
  public const STATUS_SKIPPED = 'skipped';

  /** Template syntax. */
  public const TEMPLATE_PLACEHOLDER_OPEN = '{{';
  public const TEMPLATE_PLACEHOLDER_CLOSE = '}}';
  public const TEMPLATE_SEPARATOR     = '.';

  /** Delivery ledger table. */
  public const TABLE_NOTIFICATIONS = 'notifications';
  public const TABLE_DELIVERIES  = 'notification_deliveries';
  public const TABLE_TEMPLATES   = 'notification_templates';
  public const TABLE_PREFERENCES  = 'notification_preferences';
  public const TABLE_SUBSCRIPTIONS = 'notification_subscriptions';

  /** @var string[] All supported channels. */
  public const CHANNELS = [
    self::CHANNEL_DATABASE,
    self::CHANNEL_EMAIL,
    self::CHANNEL_PUSH,
  ];

  /** @var string[] Columns of the notifications table, in DDL order. */
  public const NOTIFICATION_COLUMNS = [
    'id', 'user_id', 'type', 'event', 'notifiable_id', 'notifiable_type',
    'subject', 'body', 'data', 'channel', 'channels', 'template',
    'priority', 'is_read', 'read_at', 'created_at', 'updated_at',
  ];

  /** @var string[] Columns of the delivery ledger. */
  public const DELIVERY_COLUMNS = [
    'id', 'notification_id', 'user_id', 'channel', 'status',
    'attempts', 'last_error', 'sent_at', 'created_at', 'updated_at',
  ];

  // -----------------------------------------------------------------------
  // Properties
  // -----------------------------------------------------------------------

  /** @var \PDO Database connection */
  protected \PDO $db;

  /** @var string Primary notifications table. */
  protected string $table = self::TABLE_NOTIFICATIONS;

  /** @var string Delivery ledger table. */
  protected string $deliveryTable = self::TABLE_DELIVERIES;

  /** @var string Templates table. */
  protected string $templateTable = self::TABLE_TEMPLATES;

  /** @var string Preferences table. */
  protected string $preferenceTable = self::TABLE_PREFERENCES;

  /** @var string Subscriptions table. */
  protected string $subscriptionTable = self::TABLE_SUBSCRIPTIONS;

  /** @var array Runtime channel listeners, keyed by channel name. */
  protected array $listeners = [];

  // -----------------------------------------------------------------------
  // Construction
  // -----------------------------------------------------------------------

  /**
   * Create a new Notification service.
   *
   * @param \PDO|null $pdo Optional PDO connection (defaults to the shared Database connection).
   */
  public function __construct(?\PDO $pdo = null)
  {
    $this->db = $pdo ?? (new Database())->getConnection();
    $this->ensureTables();
  }

  /**
   * Create the supporting tables if they do not yet exist.
   *
   * The schema is emitted per driver: MySQL supports native JSON and
   * `ON UPDATE CURRENT_TIMESTAMP`, SQLite does not.
   */
  protected function ensureTables(): void
  {
    $isSqlite = $this->driver() === 'sqlite';

    $jsonType = $isSqlite ? 'TEXT' : 'JSON';
    $updatedAt = $isSqlite
      ? 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
      : 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP';

    $suffix = $isSqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    // MySQL indexes are declared inline; SQLite gets separate statements.
    $index = fn(string $name, string $columns): string =>
      $isSqlite ? '' : ",\n        INDEX {$name} ({$columns})";

    // ------------------------------------------------------------------
    // Notifications
    // ------------------------------------------------------------------
    $this->db->exec(
      "CREATE TABLE IF NOT EXISTS `{$this->table}` (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id VARCHAR(255) NOT NULL,
        type VARCHAR(100) NOT NULL DEFAULT 'default',
        event VARCHAR(100) NOT NULL DEFAULT '',
        notifiable_id VARCHAR(255) DEFAULT NULL,
        notifiable_type VARCHAR(100) DEFAULT NULL,
        subject VARCHAR(255) DEFAULT NULL,
        body TEXT,
        data {$jsonType} DEFAULT NULL,
        channel VARCHAR(50) NOT NULL DEFAULT 'database',
        channels {$jsonType} DEFAULT NULL,
        template VARCHAR(255) DEFAULT NULL,
        priority TINYINT NOT NULL DEFAULT 0,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        read_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at {$updatedAt}{$index('idx_notifications_user', 'user_id')}{$index('idx_notifications_user_read', 'user_id, is_read')}{$index('idx_notifications_user_created', 'user_id, created_at')}{$index('idx_notifications_event', 'event')}{$index('idx_notifications_notifiable', 'notifiable_type, notifiable_id')}
      ){$suffix}"
    );
    $this->createIndexes($this->table, [
      'idx_notifications_user'     => 'user_id',
      'idx_notifications_user_read'  => 'user_id, is_read',
      'idx_notifications_user_created' => 'user_id, created_at',
      'idx_notifications_event'    => 'event',
      'idx_notifications_notifiable'  => 'notifiable_type, notifiable_id',
    ], $isSqlite);

    // ------------------------------------------------------------------
    // Delivery ledger - one row per (notification, channel)
    // ------------------------------------------------------------------
    $this->db->exec(
      "CREATE TABLE IF NOT EXISTS `{$this->deliveryTable}` (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        notification_id INT UNSIGNED NOT NULL,
        user_id VARCHAR(255) NOT NULL,
        channel VARCHAR(50) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        last_error TEXT DEFAULT NULL,
        sent_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at {$updatedAt}{$index('idx_deliveries_notification', 'notification_id')}{$index('idx_deliveries_user_channel', 'user_id, channel')}{$index('idx_deliveries_status', 'status')}
      ){$suffix}"
    );
    $this->createIndexes($this->deliveryTable, [
      'idx_deliveries_notification'  => 'notification_id',
      'idx_deliveries_user_channel'  => 'user_id, channel',
      'idx_deliveries_status'     => 'status',
    ], $isSqlite);

    // ------------------------------------------------------------------
    // Templates
    // ------------------------------------------------------------------
    $unique = $isSqlite ? 'UNIQUE' : 'UNIQUE KEY uniq_template_slug';

    $this->db->exec(
      "CREATE TABLE IF NOT EXISTS `{$this->templateTable}` (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        slug VARCHAR(255) NOT NULL,
        name VARCHAR(255) DEFAULT NULL,
        subject VARCHAR(255) DEFAULT NULL,
        body TEXT,
        default_channels {$jsonType} DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at {$updatedAt},
        {$unique} (slug)
      ){$suffix}"
    );

    // ------------------------------------------------------------------
    // Preferences - one row per (user, channel), one row per (user, channel, type)
    // ------------------------------------------------------------------
    $unique = $isSqlite ? 'UNIQUE' : 'UNIQUE KEY uniq_preference';

    $this->db->exec(
      "CREATE TABLE IF NOT EXISTS `{$this->preferenceTable}` (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id VARCHAR(255) NOT NULL,
        channel VARCHAR(50) NOT NULL,
        type VARCHAR(100) NOT NULL DEFAULT '*',
        is_enabled TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at {$updatedAt},
        {$unique} (user_id, channel, type){$index('idx_preferences_user', 'user_id')}
      ){$suffix}"
    );
    $this->createIndexes($this->preferenceTable, ['idx_preferences_user' => 'user_id'], $isSqlite);

    // ------------------------------------------------------------------
    // Subscriptions - opt-in per (user, type)
    // ------------------------------------------------------------------
    $unique = $isSqlite ? 'UNIQUE' : 'UNIQUE KEY uniq_subscription';

    $this->db->exec(
      "CREATE TABLE IF NOT EXISTS `{$this->subscriptionTable}` (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id VARCHAR(255) NOT NULL,
        type VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        {$unique} (user_id, type)
      ){$suffix}"
    );
  }

  /**
   * Create indexes for a table.
   *
   * MySQL inlines indexes in the CREATE TABLE statement; SQLite has no
   * `CREATE INDEX IF NOT EXISTS` counterpart, so we issue them separately
   * and swallow "already exists" errors.
   *
   * @param array<string, string> $indexes Index name => column list.
   */
  protected function createIndexes(string $table, array $indexes, bool $isSqlite): void
  {
    if (!$isSqlite) {
      return;
    }

    foreach ($indexes as $name => $columns) {
      try {
        $this->db->exec("CREATE INDEX IF NOT EXISTS `{$name}` ON `{$table}` ({$columns})");
      } catch (\PDOException $e) {
        // Index already present - safe to ignore.
      }
    }
  }

  /**
   * Detect the PDO driver in use.
   */
  protected function driver(): string
  {
    return $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME) ?: 'mysql';
  }

  /**
   * SQL expression for "now", driver-aware.
   */
  protected function nowExpression(): string
  {
    return $this->driver() === 'sqlite' ? "datetime('now')" : 'NOW()';
  }

  /**
   * SQL predicate matching rows older than N days, driver-aware.
   */
  protected function olderThanExpression(string $column, int $days): string
  {
    return $this->driver() === 'sqlite'
      ? "{$column} < datetime('now', '-{$days} days')"
      : "{$column} < DATE_SUB(NOW(), INTERVAL {$days} DAY)";
  }

  // -----------------------------------------------------------------------
  // Sending
  // -----------------------------------------------------------------------

  /**
   * Send a notification to a single recipient.
   *
   * @param string|int $userId  Recipient identifier.
   * @param string   $type   Notification type (e.g. "order.placed").
   * @param array   $data   Payload available to templates and listeners.
   * @param array   $options channels, subject, body, template, event, priority, notifiable.
   * @return int The persisted notification ID.
   */
  public function send(string|int $userId, string $type, array $data = [], array $options = []): int
  {
    $this->assertUserId($userId);
    $this->assertType($type);

    $channels = $this->resolveChannels((string) $userId, $type, $data, $options);
    if (empty($channels)) {
      // Nothing is enabled for this user/type - record nothing.
      return 0;
    }

    // Allow a listener to short-circuit or rewrite the payload.
    $dispatch = EventDispatcher::dispatch('notification.beforeSend', [
      'user_id' => $userId,
      'type'  => $type,
      'data'  => $data,
      'options' => $options,
    ]);
    if ($dispatch === null) {
      return 0;
    }
    $type  = $dispatch['type'] ?? $type;
    $data  = $dispatch['data'] ?? $data;
    $options = array_replace($options, $dispatch['options'] ?? []);

    $payload = $this->buildPayload((string) $userId, $type, $data, $options);

    $notificationId = $this->persist($userId, $payload, $channels, $type, $options);

    foreach ($channels as $channel) {
      $this->recordDelivery($notificationId, $userId, $channel);
    }

    EventDispatcher::dispatch('notification.sent', [
      'notification_id' => $notificationId,
      'user_id'     => $userId,
      'type'      => $type,
      'channels'    => $channels,
      'data'      => $payload,
    ]);

    return $notificationId;
  }

  /**
   * Send a notification using a registered template.
   *
   * @param string|int $userId Recipient identifier.
   * @param string   $slug  Template slug (e.g. "order.placed").
   * @param array   $data  Interpolation data.
   * @param array   $options Overrides (subject, body, channels).
   * @return int Notification ID, or 0 when no template matched.
   */
  public function sendTemplate(string|int $userId, string $slug, array $data = [], array $options = []): int
  {
    $template = $this->getTemplate($slug);
    if ($template === null) {
      // Fall back to a plain notification so callers do not silently drop messages.
      return $this->send($userId, $slug, $data, $options);
    }

    $options['template'] = $template['slug'];
    $options['subject'] = $options['subject'] ?? $template['subject'];
    $options['body']   = $options['body'] ?? $this->renderBody($template['body'], $data);
    $options['channels'] = $options['channels'] ?? $template['default_channels'];

    return $this->send($userId, $slug, $data, $options);
  }

  /**
   * Send one notification to many recipients.
   *
   * Each recipient is persisted and delivered independently, so a failure
   * for one user never rolls back the others.
   *
   * @param array  $userIds Recipient identifiers.
   * @param string  $type  Notification type.
   * @param array  $data  Shared payload (may contain a per-user "per_user" map).
   * @param array  $options Delivery options.
   * @return array{notification_id: int, channels: string[]}|array
   */
  public function batch(array $userIds, string $type, array $data = [], array $options = []): array
  {
    $this->assertType($type);

    $results = [];
    foreach (array_unique(array_filter($userIds)) as $userId) {
      $results[$userId] = $this->send((string) $userId, $type, $data, $options);
    }

    EventDispatcher::dispatch('notification.batch', [
      'type'   => $type,
      'count'  => count($results),
      'user_ids' => array_keys($results),
    ]);

    return $results;
  }

  /**
   * Persist a notification row.
   */
  protected function persist(
    string|int $userId,
    array $payload,
    array $channels,
    string $type,
    array $options
  ): int {
    $notifiableId  = $options['notifiable_id'] ?? $payload['notifiable_id'] ?? null;
    $notifiableType = $options['notifiable_type'] ?? $payload['notifiable_type'] ?? null;

    $sql = "INSERT INTO `{$this->table}`
      (user_id, type, event, notifiable_id, notifiable_type, subject, body, data, channel, channels, template, priority)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $this->db->prepare($sql)->execute([
      (string) $userId,
      $type,
      $options['event'] ?? $type,
      $notifiableId,
      $notifiableType,
      $payload['subject'],
      $payload['body'],
      $payload['data'],
      $channels[0],
      $payload['channels'],
      $options['template'] ?? null,
      (int) ($options['priority'] ?? 0),
    ]);

    return (int) $this->db->lastInsertId();
  }

  // -----------------------------------------------------------------------
  // Delivery ledger
  // -----------------------------------------------------------------------

  /**
   * Create a pending delivery ledger row and attempt delivery.
   */
  protected function recordDelivery(int $notificationId, string|int $userId, string $channel): void
  {
    $sql = "INSERT INTO `{$this->deliveryTable}`
      (notification_id, user_id, channel, status)
      VALUES (?, ?, ?, ?)";

    $this->db->prepare($sql)->execute([
      $notificationId,
      (string) $userId,
      $channel,
      self::STATUS_PENDING,
    ]);

    $this->attemptDelivery((int) $this->db->lastInsertId());
  }

  /**
   * Attempt delivery for a ledger row, honouring preferences and listeners.
   */
  protected function attemptDelivery(int $deliveryId): void
  {
    $delivery = $this->db->prepare(
      "SELECT d.*, n.subject, n.body, n.data, n.type, n.channels, n.priority
       FROM `{$this->deliveryTable}` d
       JOIN `{$this->table}` n ON n.id = d.notification_id
       WHERE d.id = ?"
    );
    $delivery->execute([$deliveryId]);
    $row = $delivery->fetch(\PDO::FETCH_ASSOC);

    if (!$row) {
      return;
    }

    $userId = $row['user_id'];
    $channel = $row['channel'];
    $type  = $row['type'];

    // Respect per-user preferences before touching any external service.
    if (!$this->isEnabled($userId, $channel, $type)) {
      $this->updateDelivery($deliveryId, self::STATUS_SKIPPED, null);
      return;
    }

    $result = $this->deliver($channel, $userId, $type, $row);

    if ($result === true) {
      $this->updateDelivery($deliveryId, self::STATUS_SENT, null);
      return;
    }

    $this->updateDelivery($deliveryId, self::STATUS_FAILED, $this->stringifyError($result));
  }

  /**
   * Route a delivery to its channel.
   *
   * @return true|string True on success, error string on failure.
   */
  protected function deliver(string $channel, string $userId, string $type, array $notification): true|string
  {
    // A listener registered for this exact channel takes priority.
    if (isset($this->listeners[$channel])) {
      return $this->invokeListener($this->listeners[$channel], $channel, $userId, $notification);
    }

    return match ($channel) {
      self::CHANNEL_DATABASE => $this->deliverOnsite($userId, $notification),
      self::CHANNEL_EMAIL  => $this->deliverEmail($userId, $notification),
      self::CHANNEL_PUSH   => $this->deliverPush($userId, $notification),
      default        => $this->deliverCustom($channel, $userId, $notification),
    };
  }

  /**
   * On-site delivery is persistence itself - nothing further to do.
   */
  protected function deliverOnsite(string $userId, array $notification): true
  {
    return true;
  }

  /**
   * Deliver through the framework Email service.
   */
  protected function deliverEmail(string $userId, array $notification): true|string
  {
    $data   = $this->decodeJson($notification['data']);
    $recipient = $data['email'] ?? null;

    if (!is_string($recipient) || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
      return "No valid email address for user {$userId}";
    }

    try {
      $sent = Email::send($recipient, $notification['subject'], $notification['body']);
      return $sent ? true : 'Email service reported failure';
    } catch (\Throwable $e) {
      return $e->getMessage();
    }
  }

  /**
   * Deliver a push notification via the "notification.push" event.
   *
   * A listener should return true on success or an error string.
   */
  protected function deliverPush(string $userId, array $notification): true|string
  {
    $result = EventDispatcher::dispatch('notification.push', [
      'user_id' => $userId,
      'type'   => $notification['type'],
      'subject' => $notification['subject'],
      'body'   => $notification['body'],
      'data'   => $this->decodeJson($notification['data']),
      'channels' => $this->decodeJson($notification['channels']),
    ]);

    return $result === true ? true : (is_string($result) ? $result : 'Push channel is not configured');
  }

  /**
   * Deliver through a listener registered at runtime.
   */
  protected function deliverCustom(string $channel, string $userId, array $notification): true|string
  {
    $listener = $this->listeners[$channel] ?? null;
    if ($listener === null) {
      return "Unknown channel: {$channel}";
    }

    return $this->invokeListener($listener, $channel, $userId, $notification);
  }

  /**
   * Invoke a channel listener with a normalised payload.
   */
  protected function invokeListener(callable $listener, string $channel, string $userId, array $notification): true|string
  {
    try {
      $result = $listener([
        'channel' => $channel,
        'user_id' => $userId,
        'type'   => $notification['type'],
        'subject' => $notification['subject'],
        'body'   => $notification['body'],
        'data'   => $this->decodeJson($notification['data']),
        'channels' => $this->decodeJson($notification['channels']),
      ]);
    } catch (\Throwable $e) {
      return $e->getMessage();
    }

    if ($result === true) {
      return true;
    }

    return is_string($result) ? $result : "Channel {$channel} delivery failed";
  }

  /**
   * Update a delivery ledger row.
   */
  protected function updateDelivery(int $deliveryId, string $status, ?string $error): void
  {
    $attempts = self::STATUS_PENDING === $status ? 0 : 1;

    $now = $this->nowExpression();

    $sql = "UPDATE `{$this->deliveryTable}`
        SET status = ?,
          attempts = ?,
          last_error = ?,
          sent_at = " . ($status === self::STATUS_SENT ? $now : 'sent_at') . "
        WHERE id = ?";

    $this->db->prepare($sql)->execute([$status, $attempts, $error, $deliveryId]);
  }

  // -----------------------------------------------------------------------
  // Reading
  // -----------------------------------------------------------------------

  /**
   * Fetch notifications for a user, newest first.
   *
   * @param string|int $userId
   * @param array   $filters read, unread, type, channel, limit, offset.
   * @return array
   */
  public function get(string|int $userId, array $filters = []): array
  {
    [$where, $params] = $this->buildFeedQuery($userId, $filters);
    $limit = isset($filters['limit']) ? max(1, (int) $filters['limit']) : 50;
    $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;

    $sql = "SELECT * FROM `{$this->table}` WHERE {$where}
        ORDER BY priority DESC, created_at DESC, id DESC LIMIT {$limit} OFFSET {$offset}";

    $stmt = $this->db->prepare($sql);
    $stmt->execute($params);

    return $this->normaliseRows($stmt->fetchAll(\PDO::FETCH_ASSOC));
  }

  /**
   * Count unread notifications for a user.
   */
  public function unreadCount(string|int $userId): int
  {
    $stmt = $this->db->prepare(
      "SELECT COUNT(*) FROM `{$this->table}` WHERE user_id = ? AND is_read = 0"
    );
    $stmt->execute([(string) $userId]);

    return (int) $stmt->fetchColumn();
  }

  /**
   * Build the WHERE clause and bindings for a feed query.
   *
   * @return array{0: string, 1: array}
   */
  protected function buildFeedQuery(string|int $userId, array $filters): array
  {
    $where = ['user_id = ?'];
    $params = [(string) $userId];

    if (array_key_exists('unread', $filters) && $filters['unread'] === true) {
      $where[] = 'is_read = 0';
    } elseif (array_key_exists('read', $filters) && $filters['read'] === true) {
      $where[] = 'is_read = 1';
    }

    if (!empty($filters['type'])) {
      $where[] = 'type = ?';
      $params[] = $filters['type'];
    }

    if (!empty($filters['event'])) {
      $where[] = 'event = ?';
      $params[] = $filters['event'];
    }

    if (!empty($filters['channel'])) {
      $where[] = 'channel = ?';
      $params[] = $filters['channel'];
    }

    if (!empty($filters['notifiable_type']) && isset($filters['notifiable_id'])) {
      $where[] = 'notifiable_type = ? AND notifiable_id = ?';
      $params[] = $filters['notifiable_type'];
      $params[] = (string) $filters['notifiable_id'];
    }

    return [implode(' AND ', $where), $params];
  }

  /**
   * Hydrate JSON columns and cast scalar types for display.
   *
   * @param array $rows
   * @return array
   */
  protected function normaliseRows(array $rows): array
  {
    return array_map(function (array $row): array {
      $row['id']    = (int) $row['id'];
      $row['priority'] = (int) $row['priority'];
      $row['is_read']  = (bool) $row['is_read'];
      $row['data']   = $this->decodeJson($row['data']);
      $row['channels'] = $this->decodeJson($row['channels']) ?? [];
      $row['read_at']  = $row['read_at'] ? strtotime($row['read_at']) : null;
      $row['created_at'] = isset($row['created_at']) ? strtotime($row['created_at']) : null;
      return $row;
    }, $rows);
  }

  /**
   * Decode a JSON column, returning an empty array on null or failure.
   */
  protected function decodeJson(mixed $value): array
  {
    if (!is_string($value) || $value === '') {
      return [];
    }

    $decoded = json_decode($value, true);

    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Normalise a single row for the get()/recent()/find() surface.
   */
  protected function normaliseRow(array $row): array
  {
    return $this->normaliseRows([$row])[0];
  }

  // -----------------------------------------------------------------------
  // Read state
  // -----------------------------------------------------------------------

  /**
   * Mark a notification as read.
   */
  public function markRead(int $notificationId, ?string $userId = null): bool
  {
    $sql  = "UPDATE `{$this->table}` SET is_read = 1, read_at = " . $this->nowExpression() . " WHERE id = ?";
    $params = [$notificationId];

    if ($userId !== null) {
      $sql  .= ' AND user_id = ?';
      $params[] = $userId;
    }

    return $this->db->prepare($sql)->execute($params);
  }

  /**
   * Mark a notification as unread.
   */
  public function markUnread(int $notificationId, ?string $userId = null): bool
  {
    $sql  = "UPDATE `{$this->table}` SET is_read = 0, read_at = NULL WHERE id = ?";
    $params = [$notificationId];

    if ($userId !== null) {
      $sql  .= ' AND user_id = ?';
      $params[] = $userId;
    }

    return $this->db->prepare($sql)->execute($params);
  }

  /**
   * Mark every notification for a user as read.
   *
   * @return int Number of rows affected.
   */
  public function markAllRead(string|int $userId): int
  {
    $stmt = $this->db->prepare(
      "UPDATE `{$this->table}` SET is_read = 1, read_at = " . $this->nowExpression() . "
       WHERE user_id = ? AND is_read = 0"
    );
    $stmt->execute([(string) $userId]);

    return $stmt->rowCount();
  }

  /**
   * Toggle read state.
   */
  public function toggleRead(int $notificationId, ?string $userId = null): bool
  {
    $notification = $this->find($notificationId);
    if ($notification === null) {
      return false;
    }

    return $notification['is_read'] ? $this->markUnread($notificationId, $userId) : $this->markRead($notificationId, $userId);
  }

  // -----------------------------------------------------------------------
  // Retrieval by ID
  // -----------------------------------------------------------------------

  /**
   * Find a single notification by ID.
   *
   * @return array|null
   */
  public function find(int $notificationId): ?array
  {
    $stmt = $this->db->prepare("SELECT * FROM `{$this->table}` WHERE id = ?");
    $stmt->execute([$notificationId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    return $row ? $this->normaliseRow($row) : null;
  }

  /**
   * Fetch a notification and all of its delivery ledger rows.
   *
   * @return array{notification: array, deliveries: array}|null
   */
  public function withDeliveries(int $notificationId): ?array
  {
    $notification = $this->find($notificationId);
    if ($notification === null) {
      return null;
    }

    $stmt = $this->db->prepare(
      "SELECT * FROM `{$this->deliveryTable}` WHERE notification_id = ? ORDER BY id ASC"
    );
    $stmt->execute([$notificationId]);

    return [
      'notification' => $notification,
      'deliveries'  => $stmt->fetchAll(\PDO::FETCH_ASSOC),
    ];
  }

  /**
   * Recent notifications for a user (newest first).
   */
  public function recent(string|int $userId, int $limit = 5): array
  {
    return $this->get($userId, ['limit' => $limit]);
  }

  /**
   * Delivery ledger for a user.
   */
  public function deliveries(string|int $userId, ?string $channel = null, int $limit = 100): array
  {
    $sql  = "SELECT * FROM `{$this->deliveryTable}` WHERE user_id = ?";
    $params = [(string) $userId];

    if ($channel !== null) {
      $sql  .= ' AND channel = ?';
      $params[] = $channel;
    }

    $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit);

    $stmt = $this->db->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
  }

  // -----------------------------------------------------------------------
  // Deletion
  // -----------------------------------------------------------------------

  /**
   * Delete a single notification (and its deliveries).
   */
  public function delete(int $notificationId): bool
  {
    $this->db->prepare("DELETE FROM `{$this->deliveryTable}` WHERE notification_id = ?")->execute([$notificationId]);

    return $this->db->prepare("DELETE FROM `{$this->table}` WHERE id = ?")->execute([$notificationId]);
  }

  /**
   * Delete every notification for a user.
   *
   * @return int Number of deleted notifications.
   */
  public function deleteAll(string|int $userId): int
  {
    $ids = $this->userNotificationIds($userId);

    $this->db->prepare("DELETE FROM `{$this->deliveryTable}` WHERE user_id = ?")->execute([(string) $userId]);
    $this->db->prepare("DELETE FROM `{$this->table}` WHERE user_id = ?")->execute([(string) $userId]);

    return count($ids);
  }

  /**
   * Delete notifications matching a filter (e.g. read-only, older than N days).
   *
   * @return int Number of deleted notifications.
   */
  public function prune(array $filters = []): int
  {
    $where = [];
    $params = [];

    if (isset($filters['user_id'])) {
      $where[] = 'user_id = ?';
      $params[] = (string) $filters['user_id'];
    }
    if (isset($filters['type'])) {
      $where[] = 'type = ?';
      $params[] = $filters['type'];
    }
    if (isset($filters['channel'])) {
      $where[] = 'channel = ?';
      $params[] = $filters['channel'];
    }
    if (!empty($filters['read'])) {
      $where[] = 'is_read = 1';
    }
    if (isset($filters['older_than_days'])) {
      $where[] = $this->olderThanExpression('created_at', max(1, (int) $filters['older_than_days']));
    }

    if (empty($where)) {
      return 0;
    }

    $clause = implode(' AND ', $where);

    $ids = $this->db->prepare("SELECT id FROM `{$this->table}` WHERE {$clause}");
    $ids->execute($params);
    $idList = array_map('intval', $ids->fetchAll(\PDO::FETCH_COLUMN));

    if (empty($idList)) {
      return 0;
    }

    $this->deleteByIds($idList);

    return count($idList);
  }

  /**
   * Remove notifications (and deliveries) by ID list.
   *
   * @param int[] $ids
   */
  protected function deleteByIds(array $ids): void
  {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $this->db->prepare(
      "DELETE FROM `{$this->deliveryTable}` WHERE notification_id IN ({$placeholders})"
    )->execute($ids);

    $this->db->prepare("DELETE FROM `{$this->table}` WHERE id IN ({$placeholders})")->execute($ids);
  }

  /**
   * All notification IDs for a user.
   *
   * @return int[]
   */
  protected function userNotificationIds(string|int $userId): array
  {
    $stmt = $this->db->prepare("SELECT id FROM `{$this->table}` WHERE user_id = ?");
    $stmt->execute([(string) $userId]);

    return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
  }

  // -----------------------------------------------------------------------
  // Preferences
  // -----------------------------------------------------------------------

  /**
   * Determine whether a channel is enabled for a user and notification type.
   *
   * Type-specific rules win over the channel-wide default ("*").
   */
  public function isEnabled(string|int $userId, string $channel, ?string $type = null): bool
  {
    $params = [(string) $userId, $channel];
    $sql  = "SELECT is_enabled FROM `{$this->preferenceTable}` WHERE user_id = ? AND channel = ?";

    if ($type !== null) {
      $sql .= ' AND type = ?';
      $params[] = $type;

      $stmt = $this->db->prepare($sql);
      $stmt->execute($params);
      $value = $stmt->fetchColumn();

      if ($value !== false) {
        return (bool) $value;
      }
    }

    $stmt = $this->db->prepare(
      "SELECT is_enabled FROM `{$this->preferenceTable}` WHERE user_id = ? AND channel = ? AND type = '*'"
    );
    $stmt->execute([(string) $userId, $channel]);
    $value = $stmt->fetchColumn();

    // Default: every channel enabled unless explicitly disabled.
    return $value === false ? true : (bool) $value;
  }

  /**
   * Persist a channel preference for a user (and optionally a single type).
   */
  public function setPreference(string|int $userId, string $channel, bool $enabled, ?string $type = null): bool
  {
    $this->assertChannel($channel);

    return $this->upsert(
      $this->preferenceTable,
      ['user_id' => (string) $userId, 'channel' => $channel, 'type' => $type ?? '*'],
      ['is_enabled' => $enabled ? 1 : 0]
    );
  }

  /**
   * Read all preferences for a user, keyed by "channel" and "channel.type".
   *
   * @return array<string, bool>
   */
  public function preferences(string|int $userId): array
  {
    $stmt = $this->db->prepare("SELECT channel, type, is_enabled FROM `{$this->preferenceTable}` WHERE user_id = ?");
    $stmt->execute([(string) $userId]);

    $preferences = [];
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
      $key = $row['type'] === '*' ? $row['channel'] : $row['channel'] . self::TEMPLATE_SEPARATOR . $row['type'];
      $preferences[$key] = (bool) $row['is_enabled'];
    }

    return $preferences;
  }

  /**
   * Reset a user's preferences (opt back in to everything).
   */
  public function resetPreferences(string|int $userId): bool
  {
    return $this->db->prepare("DELETE FROM `{$this->preferenceTable}` WHERE user_id = ?")->execute([(string) $userId]);
  }

  /**
   * Enable every channel for a user.
   */
  public function enableAll(string|int $userId): void
  {
    foreach (self::CHANNELS as $channel) {
      $this->setPreference($userId, $channel, true);
    }
  }

  /**
   * Disable every channel for a user.
   */
  public function disableAll(string|int $userId): void
  {
    foreach (self::CHANNELS as $channel) {
      $this->setPreference($userId, $channel, false);
    }
  }

  // -----------------------------------------------------------------------
  // Subscriptions
  // -----------------------------------------------------------------------

  /**
   * Subscribe a user to a notification type.
   */
  public function subscribe(string|int $userId, string $type): bool
  {
    $this->assertType($type);

    // INSERT OR IGNORE is SQLite; INSERT IGNORE is MySQL.
    $ignore = $this->driver() === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';

    return $this->db->prepare(
      "{$ignore} INTO `{$this->subscriptionTable}` (user_id, type) VALUES (?, ?)"
    )->execute([(string) $userId, $type]);
  }

  /**
   * Unsubscribe a user from a notification type.
   */
  public function unsubscribe(string|int $userId, string $type): bool
  {
    return $this->db->prepare(
      "DELETE FROM `{$this->subscriptionTable}` WHERE user_id = ? AND type = ?"
    )->execute([(string) $userId, $type]);
  }

  /**
   * Check whether a user is subscribed to a notification type.
   */
  public function isSubscribed(string|int $userId, string $type): bool
  {
    $stmt = $this->db->prepare(
      "SELECT COUNT(*) FROM `{$this->subscriptionTable}` WHERE user_id = ? AND type = ?"
    );
    $stmt->execute([(string) $userId, $type]);

    return (int) $stmt->fetchColumn() > 0;
  }

  /**
   * Every type a user is subscribed to.
   *
   * @return string[]
   */
  public function subscriptions(string|int $userId): array
  {
    $stmt = $this->db->prepare("SELECT type FROM `{$this->subscriptionTable}` WHERE user_id = ? ORDER BY type ASC");
    $stmt->execute([(string) $userId]);

    return $stmt->fetchAll(\PDO::FETCH_COLUMN);
  }

  /**
   * All subscribers of a notification type.
   *
   * @return string[]
   */
  public function getSubscribers(string $type): array
  {
    $stmt = $this->db->prepare("SELECT user_id FROM `{$this->subscriptionTable}` WHERE type = ?");
    $stmt->execute([$type]);

    return $stmt->fetchAll(\PDO::FETCH_COLUMN);
  }

  // -----------------------------------------------------------------------
  // Templates
  // -----------------------------------------------------------------------

  /**
   * Register or update a template.
   *
   * @param array $template slug, name, subject, body, default_channels.
   * @return int Template ID.
   */
  public function registerTemplate(array $template): int
  {
    $validator = new Validation($template, [
      'slug' => 'required|string|max_length:255',
      'body' => 'required|string',
    ]);
    $validator->validateOrFail();

    $values = [
      'name'       => $template['name'] ?? $template['slug'],
      'subject'     => $template['subject'] ?? null,
      'body'       => $template['body'],
      'default_channels' => $this->encodeChannels($template['default_channels'] ?? [self::CHANNEL_DATABASE]),
      'is_active'    => isset($template['is_active']) ? (int) (bool) $template['is_active'] : 1,
    ];

    $this->upsert($this->templateTable, ['slug' => $template['slug']], $values);

    return (int) $this->templateId($template['slug']);
  }

  /**
   * Insert a row, or update it when the unique key already exists.
   *
   * Portable across MySQL and SQLite (no ON DUPLICATE KEY / VALUES()).
   *
   * @param array<string, mixed> $match  Columns forming the unique key.
   * @param array<string, mixed> $values Columns to insert or update.
   */
  protected function upsert(string $table, array $match, array $values): bool
  {
    $columns = array_keys($match + $values);
    $row   = $match + $values;
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));

    $sql = "INSERT INTO `{$table}` (" . implode(', ', $columns) . ") VALUES ({$placeholders})";

    try {
      return $this->db->prepare($sql)->execute(array_values($row));
    } catch (\PDOException $e) {
      // Unique constraint hit - fall back to an update.
      $set = implode(', ', array_map(fn($col) => "`{$col}` = ?", array_keys($values)));
      $where = implode(' AND ', array_map(fn($col) => "`{$col}` = ?", array_keys($match)));

      return $this->db->prepare("UPDATE `{$table}` SET {$set} WHERE {$where}")
        ->execute(array_merge(array_values($values), array_values($match)));
    }
  }

  /**
   * Look up an active template by slug.
   *
   * @return array|null
   */
  public function getTemplate(string $slug): ?array
  {
    $stmt = $this->db->prepare(
      "SELECT * FROM `{$this->templateTable}` WHERE slug = ? AND is_active = 1"
    );
    $stmt->execute([$slug]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$row) {
      return null;
    }

    $row['default_channels'] = $this->decodeJson($row['default_channels']);

    return $row;
  }

  /**
   * All registered templates.
   */
  public function templates(): array
  {
    return $this->db->query("SELECT * FROM `{$this->templateTable}` ORDER BY slug ASC")
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Delete a template.
   */
  public function deleteTemplate(string $slug): bool
  {
    return $this->db->prepare("DELETE FROM `{$this->templateTable}` WHERE slug = ?")->execute([$slug]);
  }

  /**
   * Render a template body with {{placeholder}} interpolation.
   *
   * Missing placeholders resolve to an empty string.
   */
  public function renderBody(string $body, array $data = []): string
  {
    return preg_replace_callback(
      '/' . preg_quote(self::TEMPLATE_PLACEHOLDER_OPEN, '/') . '\s*([\w.\-]+)\s*' . preg_quote(self::TEMPLATE_PLACEHOLDER_CLOSE, '/') . '/',
      fn(array $matches): string => (string) ($this->dataValue($data, $matches[1]) ?? ''),
      $body
    ) ?? $body;
  }

  /**
   * Render a subject line with {{placeholder}} interpolation.
   */
  public function renderSubject(string $subject, array $data = []): string
  {
    return $this->renderBody($subject, $data);
  }

  /**
   * Resolve a dotted data path, supporting "order.id" style access.
   */
  protected function dataValue(array $data, string $path): mixed
  {
    $segments = explode('.', $path);
    $value  = $data;

    foreach ($segments as $segment) {
      if (!is_array($value) || !array_key_exists($segment, $value)) {
        return null;
      }
      $value = $value[$segment];
    }

    return $value;
  }

  // -----------------------------------------------------------------------
  // Listeners
  // -----------------------------------------------------------------------

  /**
   * Register a custom delivery channel.
   *
   * @param callable $listener Receives ['channel','user_id','type','subject','body','data','channels'].
   */
  public function extend(string $channel, callable $listener): void
  {
    $channel = trim($channel);
    if ($channel === '') {
      throw new \InvalidArgumentException('Channel name must not be empty.');
    }

    $this->listeners[$channel] = $listener;
  }

  /**
   * Remove a custom delivery channel.
   */
  public function forget(string $channel): void
  {
    unset($this->listeners[$channel]);
  }

  // -----------------------------------------------------------------------
  // Statistics
  // -----------------------------------------------------------------------

  /**
   * Per-user notification statistics.
   *
   * @return array{total:int,unread:int,read:int}
   */
  public function stats(string|int $userId): array
  {
    $total = (int) $this->scalarCount("SELECT COUNT(*) FROM `{$this->table}` WHERE user_id = ?", [(string) $userId]);
    $unread = $this->unreadCount($userId);

    return [
      'total' => $total,
      'unread' => $unread,
      'read'  => max(0, $total - $unread),
    ];
  }

  /**
   * Delivery statistics for a user, keyed by channel.
   *
   * @return array<string, array{sent:int, failed:int, skipped:int, pending:int}>
   */
  public function deliveryStats(string|int $userId): array
  {
    $stmt = $this->db->prepare(
      "SELECT channel, status, COUNT(*) AS total
       FROM `{$this->deliveryTable}`
       WHERE user_id = ?
       GROUP BY channel, status"
    );
    $stmt->execute([(string) $userId]);

    $stats = [];
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
      $stats[$row['channel']][$row['status']] = (int) $row['total'];
    }

    return $stats;
  }

  /**
   * Count rows for a simple aggregate query.
   */
  protected function scalarCount(string $sql, array $params = []): int
  {
    $stmt = $this->db->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
  }

  // -----------------------------------------------------------------------
  // Maintenance
  // -----------------------------------------------------------------------

  /**
   * Delete notifications (and deliveries) older than N days.
   *
   * @return int Number of deleted notifications.
   */
  public function cleanup(int $days = 30): int
  {
    return $this->prune(['older_than_days' => $days]);
  }

  /**
   * Retry failed deliveries.
   *
   * @param array $filters user_id, channel, max_attempts.
   * @return array{attempted:int, sent:int, failed:int}
   */
  public function retryFailed(array $filters = []): array
  {
    $where = ["status = '" . self::STATUS_FAILED . "'"];
    $params = [];

    if (isset($filters['user_id'])) {
      $where[] = 'user_id = ?';
      $params[] = (string) $filters['user_id'];
    }
    if (isset($filters['channel'])) {
      $where[] = 'channel = ?';
      $params[] = $filters['channel'];
    }
    if (isset($filters['max_attempts'])) {
      $where[] = 'attempts < ?';
      $params[] = max(1, (int) $filters['max_attempts']);
    }

    $stmt = $this->db->prepare(
      "SELECT id FROM `{$this->deliveryTable}` WHERE " . implode(' AND ', $where)
    );
    $stmt->execute($params);
    $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));

    $result = ['attempted' => count($ids), 'sent' => 0, 'failed' => 0];

    foreach ($ids as $deliveryId) {
      $this->db->prepare("UPDATE `{$this->deliveryTable}` SET status = ? WHERE id = ?")
        ->execute([self::STATUS_PENDING, $deliveryId]);
      $this->attemptDelivery($deliveryId);

      $status = $this->db->prepare("SELECT status FROM `{$this->deliveryTable}` WHERE id = ?");
      $status->execute([$deliveryId]);

      if ($status->fetchColumn() === self::STATUS_SENT) {
        $result['sent']++;
      } else {
        $result['failed']++;
      }
    }

    return $result;
  }

  /**
   * Delete the notification tables. Destructive - use in tests or teardown only.
   */
  public function dropTables(): void
  {
    foreach ([$this->table, $this->deliveryTable, $this->templateTable, $this->preferenceTable, $this->subscriptionTable] as $table) {
      $this->db->exec("DROP TABLE IF EXISTS `{$table}`");
    }
  }

  // -----------------------------------------------------------------------
  // Payload / channel resolution
  // -----------------------------------------------------------------------

  /**
   * Resolve the payload sent to templates and listeners.
   *
   * @return array{subject:string, body:string, data:string, channels:string}
   */
  protected function buildPayload(string $userId, string $type, array $data, array $options): array
  {
    $subject = $this->renderSubject(
      (string) ($options['subject'] ?? $this->defaultSubject($type)),
      $data
    );

    $body = $this->renderBody(
      (string) ($options['body'] ?? $data['message'] ?? $subject),
      $data
    );

    return [
      'subject' => $subject,
      'body'   => $body,
      'data'   => json_encode($this->encodeData($data)),
      'channels' => json_encode($this->resolveChannels($userId, $type, $data, $options)),
    ];
  }

  /**
   * Resolve which channels should carry this notification.
   *
   * @return string[]
   */
  protected function resolveChannels(string $userId, string $type, array $data, array $options): array
  {
    $requested = $options['channels'] ?? $data['channels'] ?? null;

    if (is_string($requested)) {
      $requested = array_map('trim', explode(',', $requested));
    }

    $channels = $this->filterChannels($requested ?? [self::CHANNEL_DATABASE]);

    if ($channels === []) {
      return [];
    }

    // Drop channels the recipient has muted for this type.
    return array_values(array_filter(
      $channels,
      fn(string $channel): bool => $this->isEnabled($userId, $channel, $type)
    ));
  }

  /**
   * Validate and de-duplicate a channel list.
   *
   * @return string[]
   */
  protected function filterChannels(array $channels): array
  {
    $valid = [];
    foreach ($channels as $channel) {
      if (!is_string($channel)) {
        continue;
      }

      // Built-in channels plus any registered custom channel are accepted.
      if (in_array($channel, self::CHANNELS, true) || isset($this->listeners[$channel])) {
        $valid[$channel] = true;
      }
    }

    return array_keys($valid);
  }

  /**
   * Human-readable subject derived from the notification type.
   */
  protected function defaultSubject(string $type): string
  {
    $label = str_replace(['.', '_', '-'], ' ', $type);

    return ucwords($label);
  }

  /**
   * Prepare payload data for JSON storage.
   */
  protected function encodeData(array $data): array
  {
    return array_map(function (mixed $value): mixed {
      if (is_array($value) || is_object($value)) {
        return json_decode(json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR), true);
      }
      if (is_bool($value)) {
        return $value ? 1 : 0;
      }
      return $value;
    }, $data);
  }

  /**
   * Encode a channel list for storage.
   */
  protected function encodeChannels(array $channels): string
  {
    return json_encode($this->filterChannels($channels));
  }

  /**
   * Look up a template's primary key.
   */
  protected function templateId(string $slug): int
  {
    $stmt = $this->db->prepare("SELECT id FROM `{$this->templateTable}` WHERE slug = ?");
    $stmt->execute([$slug]);

    return (int) $stmt->fetchColumn();
  }

  // -----------------------------------------------------------------------
  // Guards
  // -----------------------------------------------------------------------

  /**
   * @throws \InvalidArgumentException
   */
  protected function assertUserId(string|int $userId): void
  {
    if ((string) $userId === '') {
      throw new \InvalidArgumentException('Notification recipient ID must not be empty.');
    }
  }

  /**
   * @throws \InvalidArgumentException
   */
  protected function assertType(string $type): void
  {
    if (trim($type) === '') {
      throw new \InvalidArgumentException('Notification type must not be empty.');
    }
  }

  /**
   * @throws \InvalidArgumentException
   */
  protected function assertChannel(string $channel): void
  {
    if (!in_array($channel, self::CHANNELS, true)) {
      throw new \InvalidArgumentException("Unsupported notification channel: {$channel}");
    }
  }

  /**
   * Normalise a delivery error into a string.
   */
  protected function stringifyError(mixed $error): string
  {
    if (is_string($error)) {
      return $error;
    }
    if ($error instanceof \Throwable) {
      return $error->getMessage();
    }

    return json_encode($error, JSON_PARTIAL_OUTPUT_ON_ERROR) ?: 'Unknown delivery error';
  }

  // -----------------------------------------------------------------------
  // Getters
  // -----------------------------------------------------------------------

  /**
   * The underlying PDO connection.
   */
  public function getConnection(): \PDO
  {
    return $this->db;
  }

  /**
   * The notifications table name.
   */
  public function getTable(): string
  {
    return $this->table;
  }

  /**
   * The delivery ledger table name.
   */
  public function getDeliveryTable(): string
  {
    return $this->deliveryTable;
  }
}
