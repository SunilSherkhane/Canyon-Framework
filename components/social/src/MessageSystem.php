<?php

namespace Canyon\Core;

/**
 * Canyon Framework - Message System
 *
 * Private one-to-one messaging between users. Each pair of users owns a single
 * conversation (deduplicated regardless of who started it) holding an ordered
 * message thread with read receipts, soft deletes per participant, blocking,
 * and per-user rate limiting.
 *
 * Elgg's messages plugin and Laravel's notification channels.
 */
class MessageSystem implements \Canyon\Contracts\MessagingInterface
{
    // -----------------------------------------------------------------------
    // Constants
    // -----------------------------------------------------------------------

    /** Message lifecycle. */
    public const STATUS_SENT = 'sent';
    public const STATUS_READ = 'read';

    /** Per-participant visibility. */
    public const VISIBILITY_VISIBLE = 'visible';
    public const VISIBILITY_DELETED = 'deleted';

    /** Default ceiling on messages per user per minute. */
    public const DEFAULT_RATE_LIMIT = 60;
    public const DEFAULT_RATE_DECAY = 60;

    /** Maximum message body length. */
    public const MAX_BODY_LENGTH = 10000;

    // -----------------------------------------------------------------------
    // Configuration
    // -----------------------------------------------------------------------

    /** @var int Messages a user may send inside the decay window. */
    protected static int $rateLimit = self::DEFAULT_RATE_LIMIT;

    /** @var int Decay window in seconds. */
    protected static int $rateDecay = self::DEFAULT_RATE_DECAY;

    // -----------------------------------------------------------------------
    // Schema
    // -----------------------------------------------------------------------

    /**
     * Create the conversations and messages tables.
     */
    public static function install(): void
    {
        $db = (new Database())->getConnection();
        $sqlite = Migration::isSqlite($db);
        $pk = fn (string $column = 'id') => $sqlite
            ? "{$column} INTEGER PRIMARY KEY AUTOINCREMENT"
            : "{$column} INT UNSIGNED AUTO_INCREMENT PRIMARY KEY";
        $ts = fn (string $column, bool $nullable = false, bool $default = true, bool $update = false) => $sqlite
            ? "{$column} DATETIME" . ($nullable ? '' : ' NOT NULL') . ($default ? ' DEFAULT CURRENT_TIMESTAMP' : '')
            : "{$column} TIMESTAMP" . ($nullable ? '' : ' NOT NULL')
                . ($default ? ' DEFAULT CURRENT_TIMESTAMP' : '')
                . ($update ? ' ON UPDATE CURRENT_TIMESTAMP' : '');
        $options = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $db->exec(
            "CREATE TABLE IF NOT EXISTS `conversations` (
                " . $pk() . ",
                user_one VARCHAR(64) NOT NULL,
                user_two VARCHAR(64) NOT NULL,
                last_message_id INT UNSIGNED NULL,
                " . $ts('last_message_at', nullable: true, default: false) . ",
                " . $ts('user_one_read_at', nullable: true, default: false) . ",
                " . $ts('user_two_read_at', nullable: true, default: false) . ",
                " . $ts('created_at') . ",
                " . $ts('updated_at', update: true) . "
            ){$options}"
        );

        $db->exec(
            "CREATE TABLE IF NOT EXISTS `messages` (
                " . $pk() . ",
                conversation_id INT UNSIGNED NOT NULL,
                sender_id VARCHAR(64) NOT NULL,
                recipient_id VARCHAR(64) NOT NULL,
                body TEXT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT '" . self::STATUS_SENT . "',
                sender_visibility VARCHAR(20) NOT NULL DEFAULT '" . self::VISIBILITY_VISIBLE . "',
                recipient_visibility VARCHAR(20) NOT NULL DEFAULT '" . self::VISIBILITY_VISIBLE . "',
                " . $ts('read_at', nullable: true, default: false) . ",
                " . $ts('created_at') . "
            ){$options}"
        );

        self::createIndexes();
    }

    /**
     * Create the secondary indexes.
     *
     * SQLite has no inline INDEX/UNIQUE KEY clauses inside CREATE TABLE, so
     * these are issued as separate statements; the MySQL inline equivalents
     * are covered by the same names on the CREATE TABLE path.
     */
    
    /**
     * Create the secondary indexes.
     *
     * SQLite has no inline INDEX/UNIQUE KEY clauses inside CREATE TABLE, so
     * these are issued as separate statements; the MySQL inline equivalents
     * are covered by the same names on the CREATE TABLE path.
     */
    protected static function createIndexes(): void
    {
        $db = (new Database())->getConnection();

        $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS `uq_pair` ON `conversations` (user_one, user_two)");
        $db->exec("CREATE INDEX IF NOT EXISTS `idx_conv_last_message` ON `conversations` (last_message_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS `idx_conv_user_one` ON `conversations` (user_one)");
        $db->exec("CREATE INDEX IF NOT EXISTS `idx_conv_user_two` ON `conversations` (user_two)");
        $db->exec("CREATE INDEX IF NOT EXISTS `idx_msg_conversation` ON `messages` (conversation_id, id)");
        $db->exec("CREATE INDEX IF NOT EXISTS `idx_msg_recipient` ON `messages` (recipient_id, status)");
        $db->exec("CREATE INDEX IF NOT EXISTS `idx_msg_sender` ON `messages` (sender_id)");
    }


    /**
     * Lazily create the schema on first use.
     */
    protected static function ensureSchema(): void
    {
        static $installed = false;

        if ($installed) {
            return;
        }

        self::install();
        $installed = true;
    }

    /**
     * Configure the per-user send limiter.
     */
    public static function setRateLimit(int $maxMessages = self::DEFAULT_RATE_LIMIT, int $decaySeconds = self::DEFAULT_RATE_DECAY): void
    {
        if ($maxMessages < 1 || $decaySeconds < 1) {
            throw new \InvalidArgumentException('Rate limit and decay must be at least 1.');
        }

        self::$rateLimit = $maxMessages;
        self::$rateDecay = $decaySeconds;
    }

    // -----------------------------------------------------------------------
    // Sending
    // -----------------------------------------------------------------------

    /**
     * Send a private message, creating the conversation on first contact.
     *
     * @return array{id: int, conversation_id: int, status: string}|null Null when blocked or rate limited.
     */
    public static function send(string $senderId, string $recipientId, string $body, array $options = []): ?array
    {
        if ($senderId === $recipientId) {
            return null;
        }

        $body = trim($body);
        if ($body === '' || mb_strlen($body) > self::MAX_BODY_LENGTH) {
            return null;
        }

        // A blocked pair may not exchange messages in either direction.
        if (self::isBlocked($senderId, $recipientId)) {
            return null;
        }

        if (!self::checkSendLimit($senderId)) {
            return null;
        }

        self::ensureSchema();

        $conversationId = self::conversationId($senderId, $recipientId);
        if ($conversationId === null) {
            $conversationId = self::createConversation($senderId, $recipientId);
        }

        $db = (new Database())->getConnection();
        $stmt = $db->prepare(
            "INSERT INTO `messages`
                (conversation_id, sender_id, recipient_id, body, status, sender_visibility, recipient_visibility)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->execute([
            $conversationId,
            $senderId,
            $recipientId,
            Waf::sanitizeValue($body),
            self::STATUS_SENT,
            self::VISIBILITY_VISIBLE,
            self::VISIBILITY_VISIBLE,
        ]);

        $messageId = (int) $db->lastInsertId();

        self::touchConversation($conversationId, $messageId, $senderId, $recipientId);

        EventDispatcher::dispatch('message.sent', [
            'message_id' => $messageId,
            'conversation_id' => $conversationId,
            'sender_id' => $senderId,
            'recipient_id' => $recipientId,
        ]);

        return [
            'id' => $messageId,
            'conversation_id' => $conversationId,
            'status' => self::STATUS_SENT,
        ];
    }

    /**
     * Create (or fetch) the conversation row for a user pair.
     */
    protected static function createConversation(string $userOne, string $userTwo): int
    {
        // Canonical ordering keeps one row per pair.
        [$a, $b] = self::orderPair($userOne, $userTwo);

        $db = (new Database())->getConnection();

        // Portable upsert — SQLite has no ON DUPLICATE KEY UPDATE.
        $existing = $db->prepare(
            "SELECT id FROM `conversations` WHERE user_one = ? AND user_two = ?"
        );
        $existing->execute([$a, $b]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);

        if ($row !== false) {
            return (int) $row['id'];
        }

        $db->prepare(
            "INSERT INTO `conversations` (user_one, user_two) VALUES (?, ?)"
        )->execute([$a, $b]);

        return (int) $db->lastInsertId();
    }

    /**
     * The conversation ID for a pair, or null when none exists.
     */
    public static function conversationId(string $userId, string $otherUserId): ?int
    {
        self::ensureSchema();

        [$a, $b] = self::orderPair($userId, $otherUserId);

        $db = (new Database())->getConnection();
        $stmt = $db->prepare("SELECT id FROM `conversations` WHERE user_one = ? AND user_two = ? LIMIT 1");
        $stmt->execute([$a, $b]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Update the conversation's last-message pointers and read state.
     */
    protected static function touchConversation(int $conversationId, int $messageId, string $senderId, string $recipientId): void
    {
        $db = (new Database())->getConnection();

        $db->prepare(
            "UPDATE `conversations`
             SET last_message_id = ?, last_message_at = NOW(),
                 user_one_read_at = CASE WHEN user_one = ? THEN NOW() ELSE user_one_read_at END,
                 user_two_read_at = CASE WHEN user_two = ? THEN NOW() ELSE user_two_read_at END
             WHERE id = ?"
        )->execute([$messageId, $senderId, $senderId, $conversationId]);

        // The recipient has not read it yet.
        $db->prepare(
            "UPDATE `conversations`
             SET user_one_read_at = CASE WHEN user_one = ? THEN NULL ELSE user_one_read_at END,
                 user_two_read_at = CASE WHEN user_two = ? THEN NULL ELSE user_two_read_at END
             WHERE id = ?"
        )->execute([$recipientId, $recipientId, $conversationId]);
    }

    // -----------------------------------------------------------------------
    // Rate limiting
    // -----------------------------------------------------------------------

    /**
     * Consume one send against the user's budget.
     */
    public static function checkSendLimit(string $userId): bool
    {
        $key = 'message_send_' . md5($userId);
        $now = time();

        $bucket = Cache::get($key);
        if (!is_array($bucket) || ($bucket['reset'] ?? 0) < $now) {
            $bucket = ['count' => 0, 'reset' => $now + self::$rateDecay];
        }

        $bucket['count']++;
        Cache::put($key, $bucket, self::$rateDecay);

        return $bucket['count'] <= self::$rateLimit;
    }

    /**
     * Seconds until the sender's rate window resets.
     */
    public static function sendRetryAfter(string $userId): int
    {
        $bucket = Cache::get('message_send_' . md5($userId));
        if (!is_array($bucket)) {
            return 0;
        }

        return max(0, (int) ($bucket['reset'] ?? 0) - time());
    }

    /**
     * Whether the sender is currently over their send budget.
     */
    public static function isRateLimited(string $userId): bool
    {
        $bucket = Cache::get('message_send_' . md5($userId));
        if (!is_array($bucket) || ($bucket['reset'] ?? 0) < time()) {
            return false;
        }

        return (int) ($bucket['count'] ?? 0) >= self::$rateLimit;
    }

    // -----------------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------------

    /**
     * Read a page of messages in a conversation, newest first.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, has_more: bool}
     */
    public static function conversation(int $conversationId, ?string $viewerId = null, int $limit = 20, int $offset = 0): array
    {
        self::ensureSchema();

        $viewerId = $viewerId ?? self::currentUserId();

        $db = (new Database())->getConnection();

        // Scope to the viewer's own conversation.
        if ($viewerId !== null) {
            $participant = $db->prepare(
                "SELECT COUNT(*) FROM `conversations`
                 WHERE id = ? AND (user_one = ? OR user_two = ?)"
            );
            $participant->execute([$conversationId, $viewerId, $viewerId]);
            if ((int) $participant->fetchColumn() === 0) {
                return ['items' => [], 'total' => 0, 'has_more' => false];
            }
        }

        $countStmt = $db->prepare(
            "SELECT COUNT(*) FROM `messages`
             WHERE conversation_id = ?
               AND CASE WHEN sender_id = ? THEN sender_visibility ELSE recipient_visibility END = ?"
        );
        $countStmt->execute([$conversationId, $viewerId ?? '', self::VISIBILITY_VISIBLE]);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT * FROM `messages`
             WHERE conversation_id = ?
               AND CASE WHEN sender_id = ? THEN sender_visibility ELSE recipient_visibility END = ?
             ORDER BY id DESC LIMIT ? OFFSET ?"
        );
        $stmt->execute([$conversationId, $viewerId ?? '', self::VISIBILITY_VISIBLE, $limit, $offset]);

        $items = array_map([self::class, 'normalize'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
        $items = array_reverse($items); // Oldest first for display.

        return [
            'items' => $items,
            'total' => $total,
            'has_more' => ($offset + $limit) < $total,
        ];
    }

    /**
     * Fetch a single message if the viewer is a participant.
     */
    public static function find(int $messageId, ?string $viewerId = null): ?array
    {
        self::ensureSchema();

        $viewerId = $viewerId ?? self::currentUserId();

        $db = (new Database())->getConnection();
        $stmt = $db->prepare("SELECT * FROM `messages` WHERE id = ? LIMIT 1");
        $stmt->execute([$messageId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        if ($viewerId !== null && !in_array($viewerId, [$row['sender_id'], $row['recipient_id']], true)) {
            return null;
        }

        $key = $row['sender_id'] === $viewerId ? 'sender_visibility' : 'recipient_visibility';
        if (($row[$key] ?? '') !== self::VISIBILITY_VISIBLE) {
            return null;
        }

        return self::normalize($row);
    }

    /**
     * Mark every message in a conversation as read for the viewer.
     */
    public static function markRead(int $conversationId, ?string $viewerId = null): int
    {
        self::ensureSchema();

        $viewerId = $viewerId ?? self::currentUserId();
        if ($viewerId === null) {
            return 0;
        }

        $db = (new Database())->getConnection();

        $stmt = $db->prepare(
            "UPDATE `messages`
             SET status = ?, read_at = NOW()
             WHERE conversation_id = ? AND recipient_id = ? AND status = ?"
        );
        $stmt->execute([self::STATUS_READ, $conversationId, $viewerId, self::STATUS_SENT]);

        $db->prepare(
            "UPDATE `conversations`
             SET user_one_read_at = CASE WHEN user_one = ? THEN NOW() ELSE user_one_read_at END,
                 user_two_read_at = CASE WHEN user_two = ? THEN NOW() ELSE user_two_read_at END
             WHERE id = ?"
        )->execute([$viewerId, $viewerId, $conversationId]);

        return $stmt->rowCount();
    }

    /**
     * The messages a user has not read yet, across all conversations.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function unread(string $userId, int $limit = 50): array
    {
        self::ensureSchema();

        $db = (new Database())->getConnection();
        $stmt = $db->prepare(
            "SELECT m.*, c.user_one, c.user_two
             FROM `messages` m
             INNER JOIN `conversations` c ON c.id = m.conversation_id
             WHERE m.recipient_id = ? AND m.status = ? AND m.recipient_visibility = ?
             ORDER BY m.id DESC LIMIT ?"
        );
        $stmt->execute([$userId, self::STATUS_SENT, self::VISIBILITY_VISIBLE, $limit]);

        return array_map([self::class, 'normalize'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Count of unread messages for a user.
     */
    public static function unreadCount(string $userId): int
    {
        self::ensureSchema();

        $db = (new Database())->getConnection();
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM `messages`
             WHERE recipient_id = ? AND status = ? AND recipient_visibility = ?"
        );
        $stmt->execute([$userId, self::STATUS_SENT, self::VISIBILITY_VISIBLE]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * The conversations a user participates in, most recent first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function conversations(string $userId, int $limit = 20, int $offset = 0): array
    {
        self::ensureSchema();

        $db = (new Database())->getConnection();

        $countStmt = $db->prepare(
            "SELECT COUNT(*) FROM `conversations` WHERE user_one = ? OR user_two = ?"
        );
        $countStmt->execute([$userId, $userId]);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT c.*,
                    (SELECT body FROM `messages` m
                      WHERE m.conversation_id = c.id
                        AND CASE WHEN m.sender_id = ? THEN m.sender_visibility ELSE m.recipient_visibility END = ?
                      ORDER BY m.id DESC LIMIT 1) AS last_body,
                    (SELECT COUNT(*) FROM `messages` m
                      WHERE m.conversation_id = c.id
                        AND m.recipient_id = ? AND m.status = ?
                        AND m.recipient_visibility = ?) AS unread_count
             FROM `conversations` c
             WHERE c.user_one = ? OR c.user_two = ?
             ORDER BY c.last_message_at DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute([
            $userId, self::VISIBILITY_VISIBLE,
            $userId, self::STATUS_SENT, self::VISIBILITY_VISIBLE,
            $userId, $userId,
            $limit, $offset,
        ]);

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $items = array_map(fn ($row) => self::normalizeConversation($row, $userId), $rows);

        return [
            'items' => $items,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * The other participant of a conversation from the viewer's perspective.
     */
    public static function otherParticipant(int $conversationId, ?string $viewerId = null): ?string
    {
        self::ensureSchema();

        $viewerId = $viewerId ?? self::currentUserId();

        $db = (new Database())->getConnection();
        $stmt = $db->prepare("SELECT user_one, user_two FROM `conversations` WHERE id = ? LIMIT 1");
        $stmt->execute([$conversationId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return $row['user_one'] === $viewerId ? $row['user_two'] : $row['user_one'];
    }

    // -----------------------------------------------------------------------
    // Deletion
    // -----------------------------------------------------------------------

    /**
     * Soft-delete a conversation for one participant only.
     */
    public static function deleteConversation(int $conversationId, ?string $viewerId = null): bool
    {
        self::ensureSchema();

        $viewerId = $viewerId ?? self::currentUserId();
        if ($viewerId === null) {
            return false;
        }

        $db = (new Database())->getConnection();
        $stmt = $db->prepare(
            "UPDATE `messages`
             SET sender_visibility = CASE WHEN sender_id = ? THEN ? ELSE sender_visibility END,
                 recipient_visibility = CASE WHEN recipient_id = ? THEN ? ELSE recipient_visibility END
             WHERE conversation_id = ?"
        );
        $stmt->execute([$viewerId, self::VISIBILITY_DELETED, $viewerId, self::VISIBILITY_DELETED, $conversationId]);

        // Hard-delete once neither side can see anything.
        $db->prepare(
            "DELETE FROM `messages`
             WHERE conversation_id = ? AND sender_visibility = ? AND recipient_visibility = ?"
        )->execute([$conversationId, self::VISIBILITY_DELETED, self::VISIBILITY_DELETED]);

        return true;
    }

    /**
     * Soft-delete a single message for the viewer.
     */
    public static function deleteMessage(int $messageId, ?string $viewerId = null): bool
    {
        self::ensureSchema();

        $viewerId = $viewerId ?? self::currentUserId();
        if ($viewerId === null) {
            return false;
        }

        $db = (new Database())->getConnection();
        $stmt = $db->prepare(
            "UPDATE `messages`
             SET sender_visibility = CASE WHEN sender_id = ? THEN ? ELSE sender_visibility END,
                 recipient_visibility = CASE WHEN recipient_id = ? THEN ? ELSE recipient_visibility END
             WHERE id = ? AND (sender_id = ? OR recipient_id = ?)"
        );
        $stmt->execute([
            $viewerId, self::VISIBILITY_DELETED,
            $viewerId, self::VISIBILITY_DELETED,
            $messageId, $viewerId, $viewerId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Permanently remove a conversation row (admin/mod use).
     */
    public static function purgeConversation(int $conversationId): bool
    {
        self::ensureSchema();

        $db = (new Database())->getConnection();
        $db->prepare("DELETE FROM `messages` WHERE conversation_id = ?")->execute([$conversationId]);
        $stmt = $db->prepare("DELETE FROM `conversations` WHERE id = ?");
        $stmt->execute([$conversationId]);

        return $stmt->rowCount() > 0;
    }

    // -----------------------------------------------------------------------
    // Blocking
    // -----------------------------------------------------------------------

    /**
     * Whether the two users may message each other.
     */
    public static function isBlocked(string $userId, string $otherUserId): bool
    {
        // Delegate to the profile block list when it exists.
        if (class_exists(UserProfile::class)) {
            return UserProfile::isBlocked($userId, $otherUserId);
        }

        return false;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * The signed-in user's ID, or null.
     */
    public static function currentUserId(): ?string
    {
        $userId = $_SESSION['user_id'] ?? null;

        return $userId !== null ? (string) $userId : null;
    }

    /**
     * Sort a user pair so the conversation row is unique.
     *
     * @return array{0: string, 1: string}
     */
    protected static function orderPair(string $userOne, string $userTwo): array
    {
        return strcmp($userOne, $userTwo) <= 0 ? [$userOne, $userTwo] : [$userTwo, $userOne];
    }

    /**
     * Normalize a message row.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected static function normalize(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['conversation_id'] = (int) $row['conversation_id'];
        $row['is_read'] = ($row['status'] ?? '') === self::STATUS_READ;
        $row['created_at'] = (string) ($row['created_at'] ?? '');

        return $row;
    }

    /**
     * Normalize a conversation row, resolving the counterpart.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected static function normalizeConversation(array $row, string $viewerId): array
    {
        $row['id'] = (int) $row['id'];
        $row['other_user_id'] = $row['user_one'] === $viewerId ? $row['user_two'] : $row['user_one'];
        $row['unread_count'] = (int) ($row['unread_count'] ?? 0);
        $row['last_body'] = (string) ($row['last_body'] ?? '');

        return $row;
    }
}
