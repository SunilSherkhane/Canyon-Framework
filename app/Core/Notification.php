<?php

namespace Canyon\Core;

/**
 * Canyon Framework - Notification System
 *
 * On-site and email notifications for user subscriptions.
 * Inspired by Elgg's notification service.
 */
class Notification
{
    /** @var \PDO Database connection */
    protected \PDO $db;

    /** @var string Notification table */
    protected string $table = 'notifications';

    /**
     * Create a new Notification instance.
     */
    public function __construct()
    {
        $this->db = (new Database())->getConnection();
        $this->ensureTable();
    }

    /**
     * Ensure notifications table exists.
     */
    protected function ensureTable(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS `{$this->table}` (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id VARCHAR(255) NOT NULL,
                type VARCHAR(100) NOT NULL DEFAULT 'default',
                title VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                is_read TINYINT(1) DEFAULT 0,
                is_sent TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_notification (user_id, type, title)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /**
     * Send a notification to a user.
     *
     * @param string $userId User ID
     * @param string $title Notification title
     * @param string $message Notification message
     * @param string $type Notification type (default, email, sms)
     * @param array $data Additional data
     * @return int Notification ID
     */
    public function send(string $userId, string $title, string $message, string $type = 'default', array $data = []): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO `{$this->table}` (user_id, type, title, message, data) VALUES (?, ?, ?, ?, ?)"
        );

        $stmt->execute([$userId, $type, $title, $message, json_encode($data)]);

        $notificationId = (int) $this->db->lastInsertId();

        // Fire event for email/sms delivery
        if ($type === 'email') {
            EventDispatcher::dispatch('notification.email', [
                'id' => $notificationId,
                'user_id' => $userId,
                'title' => $title,
                'message' => $message,
                'data' => $data,
            ]);
        }

        return $notificationId;
    }

    /**
     * Mark notification as read.
     */
    public function markRead(int $notificationId): bool
    {
        $stmt = $this->db->prepare("UPDATE `{$this->table}` SET is_read = 1 WHERE id = ?");
        return $stmt->execute([$notificationId]);
    }

    /**
     * Mark all notifications as read for a user.
     */
    public function markAllRead(string $userId): int
    {
        $stmt = $this->db->prepare("UPDATE `{$this->table}` SET is_read = 1 WHERE user_id = ?");
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    }

    /**
     * Get notifications for a user.
     *
     * @param string $userId User ID
     * @param bool $unreadOnly Only unread
     * @param int $limit Max notifications
     * @return array
     */
    public function get(string $userId, bool $unreadOnly = false, int $limit = 50): array
    {
        $sql = "SELECT * FROM `{$this->table}` WHERE user_id = ?";
        $params = [$userId];

        if ($unreadOnly) {
            $sql .= " AND is_read = 0";
        }

        $sql .= " ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Count unread notifications for a user.
     */
    public function unreadCount(string $userId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM `{$this->table}` WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Delete a notification.
     */
    public function delete(int $notificationId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM `{$this->table}` WHERE id = ?");
        return $stmt->execute([$notificationId]);
    }

    /**
     * Delete all notifications for a user.
     */
    public function deleteAll(string $userId): int
    {
        $stmt = $this->db->prepare("DELETE FROM `{$this->table}` WHERE user_id = ?");
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    }

    /**
     * Subscribe user to notification type.
     */
    public function subscribe(string $userId, string $type): bool
    {
        $table = 'notification_subscriptions';

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS `$table` (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id VARCHAR(255) NOT NULL,
                type VARCHAR(100) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_subscription (user_id, type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $stmt = $this->db->prepare("INSERT IGNORE INTO `$table` (user_id, type) VALUES (?, ?)");
        return $stmt->execute([$userId, $type]);
    }

    /**
     * Unsubscribe user from notification type.
     */
    public function unsubscribe(string $userId, string $type): bool
    {
        $table = 'notification_subscriptions';
        $stmt = $this->db->prepare("DELETE FROM `$table` WHERE user_id = ? AND type = ?");
        return $stmt->execute([$userId, $type]);
    }

    /**
     * Check if user is subscribed to a notification type.
     */
    public function isSubscribed(string $userId, string $type): bool
    {
        $table = 'notification_subscriptions';
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM `$table` WHERE user_id = ? AND type = ?");
        $stmt->execute([$userId, $type]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Get all subscribers for a notification type.
     */
    public function getSubscribers(string $type): array
    {
        $table = 'notification_subscriptions';
        $stmt = $this->db->prepare("SELECT user_id FROM `$table` WHERE type = ?");
        $stmt->execute([$type]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Get recent notifications for display.
     */
    public function recent(string $userId, int $limit = 5): array
    {
        return $this->get($userId, false, $limit);
    }

    /**
     * Get notification stats for a user.
     */
    public function stats(string $userId): array
    {
        $total = $this->db->prepare("SELECT COUNT(*) FROM `{$this->table}` WHERE user_id = ?");
        $total->execute([$userId]);
        $totalCount = (int) $total->fetchColumn();

        $unread = $this->unreadCount($userId);

        return [
            'total' => $totalCount,
            'unread' => $unread,
            'read' => $totalCount - $unread,
        ];
    }

    /**
     * Clean old notifications (older than X days).
     */
    public function cleanup(int $days = 30): int
    {
        $stmt = $this->db->prepare(
            "DELETE FROM `{$this->table}` WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
        );
        $stmt->execute([$days]);
        return $stmt->rowCount();
    }
}