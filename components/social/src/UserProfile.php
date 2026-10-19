<?php

namespace Canyon\Core;

/**
 * Canyon Framework - User Profiles
 *
 * Rich user profiles layered on top of the `users` table: avatar and cover
 * images, a free-form profile field bag, friend requests with accept/reject,
 * blocking in both directions, and privacy-aware lookups.
 *
 * Inspired by Elgg's user entities and Facebook-style social graphs.
 */
class UserProfile implements \Canyon\Contracts\ProfileInterface
{
    // -----------------------------------------------------------------------
    // Constants
    // -----------------------------------------------------------------------

    /** Primary profile table. */
    public const TABLE = 'user_profiles';

    /** Friend-request lifecycle. */
    public const FRIEND_PENDING = 'pending';
    public const FRIEND_ACCEPTED = 'accepted';
    public const FRIEND_REJECTED = 'rejected';

    /** Visibility levels for profile fields and the profile itself. */
    public const VISIBILITY_PUBLIC = 'public';
    public const VISIBILITY_FRIENDS = 'friends';
    public const VISIBILITY_PRIVATE = 'private';

    /** Default avatar path served when a user has no upload. */
    public const DEFAULT_AVATAR = '/assets/img/default-avatar.png';

    // -----------------------------------------------------------------------
    // Schema
    // -----------------------------------------------------------------------

    /** @var string User records are read from. */
    protected string $usersTable = 'users';

    /** @var string Friendship records. */
    protected string $friendsTable = 'user_friends';

    /** @var string Block records. */
    protected string $blocksTable = 'user_blocks';

    // -----------------------------------------------------------------------
    // Bootstrapping
    // -----------------------------------------------------------------------

    /**
     * Create the profile, friend, and block tables when they are missing.
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
            "CREATE TABLE IF NOT EXISTS `" . self::TABLE . "` (
                user_id VARCHAR(64) NOT NULL PRIMARY KEY,
                display_name VARCHAR(255) NOT NULL DEFAULT '',
                headline VARCHAR(255) NOT NULL DEFAULT '',
                bio TEXT NULL,
                location VARCHAR(255) NOT NULL DEFAULT '',
                website VARCHAR(255) NOT NULL DEFAULT '',
                avatar VARCHAR(255) NOT NULL DEFAULT '',
                cover_photo VARCHAR(255) NOT NULL DEFAULT '',
                gender VARCHAR(20) NOT NULL DEFAULT '',
                birthdate DATE NULL,
                timezone VARCHAR(64) NOT NULL DEFAULT '',
                locale VARCHAR(16) NOT NULL DEFAULT '',
                visibility VARCHAR(20) NOT NULL DEFAULT '" . self::VISIBILITY_PUBLIC . "',
                fields TEXT NULL,
                is_verified TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                " . $ts('last_seen_at', nullable: true, default: false) . ",
                " . $ts('created_at') . ",
                " . $ts('updated_at', update: true) . "
            ){$options}"
        );

        $db->exec(
            "CREATE TABLE IF NOT EXISTS `user_friends` (
                " . $pk() . ",
                user_id VARCHAR(64) NOT NULL,
                friend_id VARCHAR(64) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT '" . self::FRIEND_PENDING . "',
                " . $ts('created_at') . ",
                " . $ts('updated_at', update: true) . "
            ){$options}"
        );

        $db->exec(
            "CREATE TABLE IF NOT EXISTS `user_blocks` (
                " . $pk() . ",
                user_id VARCHAR(64) NOT NULL,
                blocked_user_id VARCHAR(64) NOT NULL,
                reason VARCHAR(255) NOT NULL DEFAULT '',
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

        $db->exec("CREATE INDEX IF NOT EXISTS `idx_up_display_name` ON `user_profiles` (display_name)");
        $db->exec("CREATE INDEX IF NOT EXISTS `idx_up_visibility` ON `user_profiles` (visibility)");
        $db->exec("CREATE INDEX IF NOT EXISTS `idx_up_is_active` ON `user_profiles` (is_active)");
        $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS `uq_friendship` ON `user_friends` (user_id, friend_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS `idx_friend_user_status` ON `user_friends` (user_id, status)");
        $db->exec("CREATE INDEX IF NOT EXISTS `idx_friend_friend_status` ON `user_friends` (friend_id, status)");
        $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS `uq_block` ON `user_blocks` (user_id, blocked_user_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS `idx_block_blocked` ON `user_blocks` (blocked_user_id)");
    }


    /**
     * Ensure the schema exists; called lazily by the read/write helpers.
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

    // -----------------------------------------------------------------------
    // Profiles
    // -----------------------------------------------------------------------

    /**
     * Load a profile, creating an empty row on first access.
     *
     * @return array<string, mixed>|null Null when the user does not exist.
     */
    public static function find(string $userId): ?array
    {
        self::ensureSchema();

        $db = (new Database())->getConnection();
        $stmt = $db->prepare("SELECT * FROM `" . self::TABLE . "` WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return self::normalize($row);
    }

    /**
     * Load a profile or create it from the supplied defaults.
     *
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    public static function findOrCreate(string $userId, array $defaults = []): array
    {
        $profile = self::find($userId);

        if ($profile !== null) {
            return $profile;
        }

        return self::create($userId, $defaults);
    }

    /**
     * Create a profile row for a user.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function create(string $userId, array $data = []): array
    {
        self::ensureSchema();

        $db = (new Database())->getConnection();

        $profile = array_merge([
            'user_id' => $userId,
            'display_name' => (string) ($data['display_name'] ?? ''),
            'headline' => (string) ($data['headline'] ?? ''),
            'bio' => (string) ($data['bio'] ?? ''),
            'location' => (string) ($data['location'] ?? ''),
            'website' => Security::sanitizeUrl((string) ($data['website'] ?? '')),
            'avatar' => (string) ($data['avatar'] ?? ''),
            'cover_photo' => (string) ($data['cover_photo'] ?? ''),
            'gender' => (string) ($data['gender'] ?? ''),
            'birthdate' => self::normalizeDate($data['birthdate'] ?? null),
            'timezone' => (string) ($data['timezone'] ?? date_default_timezone_get()),
            'locale' => (string) ($data['locale'] ?? 'en'),
            'visibility' => self::normalizeVisibility($data['visibility'] ?? null),
            'fields' => json_encode($data['fields'] ?? [], JSON_UNESCAPED_UNICODE),
            'is_verified' => !empty($data['is_verified']) ? 1 : 0,
            'is_active' => array_key_exists('is_active', $data) ? (int) (bool) $data['is_active'] : 1,
        ], $data);

        $columns = array_keys($profile);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        $stmt = $db->prepare(
            "INSERT INTO `" . self::TABLE . "` (`" . implode('`, `', $columns) . "`) VALUES ({$placeholders})"
        );

        try {
            $stmt->execute(array_values($profile));
        } catch (\PDOException $e) {
            // A concurrent request created the row first - return theirs.
            $existing = self::find($userId);
            if ($existing !== null) {
                return $existing;
            }
            throw $e;
        }

        return self::find($userId) ?? $profile;
    }

    /**
     * Update a profile. Unknown keys are ignored; `fields` may be an array.
     *
     * @param array<string, mixed> $data
     */
    public static function update(string $userId, array $data): bool
    {
        self::ensureSchema();

        if (empty($data)) {
            return false;
        }

        $allowed = [
            'display_name', 'headline', 'bio', 'location', 'website', 'avatar',
            'cover_photo', 'gender', 'birthdate', 'timezone', 'locale',
            'visibility', 'fields', 'is_verified', 'is_active', 'last_seen_at',
        ];

        $updates = [];
        $bindings = [];

        foreach ($data as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }

            $updates[] = "`{$key}` = ?";
            $bindings[] = match ($key) {
                'birthdate' => self::normalizeDate($value),
                'visibility' => self::normalizeVisibility($value),
                'website' => Security::sanitizeUrl((string) $value),
                'fields' => json_encode($value, JSON_UNESCAPED_UNICODE),
                'is_verified', 'is_active' => $value ? 1 : 0,
                default => (string) $value,
            };
        }

        if (empty($updates)) {
            return false;
        }

        $bindings[] = $userId;

        $db = (new Database())->getConnection();
        $stmt = $db->prepare(
            "UPDATE `" . self::TABLE . "` SET " . implode(', ', $updates) . " WHERE user_id = ?"
        );

        return $stmt->execute($bindings);
    }

    /**
     * Replace the whole profile (used by the admin editor).
     *
     * @param array<string, mixed> $data
     */
    public static function replace(string $userId, array $data): bool
    {
        self::ensureSchema();

        $existing = self::find($userId);
        if ($existing === null) {
            self::create($userId, $data);
            return true;
        }

        return self::update($userId, $data);
    }

    /**
     * Permanently delete a profile. Friend and block rows are cleaned up too.
     */
    public static function delete(string $userId): bool
    {
        self::ensureSchema();

        $db = (new Database())->getConnection();

        $db->prepare("DELETE FROM `" . self::TABLE . "` WHERE user_id = ?")->execute([$userId]);
        $db->prepare("DELETE FROM `user_friends` WHERE user_id = ? OR friend_id = ?")->execute([$userId, $userId]);
        $db->prepare("DELETE FROM `user_blocks` WHERE user_id = ? OR blocked_user_id = ?")->execute([$userId, $userId]);

        return true;
    }

    /**
     * Record that a user was active right now.
     */
    public static function touch(string $userId): void
    {
        self::ensureSchema();

        $db = (new Database())->getConnection();
        $db->prepare("UPDATE `" . self::TABLE . "` SET last_seen_at = NOW() WHERE user_id = ?")->execute([$userId]);
    }

    /**
     * List profiles with optional filters and pagination.
     *
     * @param array<string, mixed> $filters Keys: q, visibility, is_active, verified, limit, offset, order
     * @return array{items: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public static function list(array $filters = []): array
    {
        self::ensureSchema();

        $limit = max(1, (int) ($filters['limit'] ?? 20));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $order = ($filters['order'] ?? 'recent') === 'name' ? 'display_name ASC' : 'created_at DESC';

        $where = [];
        $bindings = [];

        if (!empty($filters['q'])) {
            $where[] = '(display_name LIKE ? OR headline LIKE ? OR bio LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            array_push($bindings, $like, $like, $like);
        }

        if (!empty($filters['visibility'])) {
            $where[] = 'visibility = ?';
            $bindings[] = self::normalizeVisibility($filters['visibility']);
        }

        if (isset($filters['is_active'])) {
            $where[] = 'is_active = ?';
            $bindings[] = $filters['is_active'] ? 1 : 0;
        }

        if (isset($filters['verified'])) {
            $where[] = 'is_verified = ?';
            $bindings[] = $filters['verified'] ? 1 : 0;
        }

        $whereSql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);

        $db = (new Database())->getConnection();

        $countStmt = $db->prepare("SELECT COUNT(*) FROM `" . self::TABLE . "`{$whereSql}");
        $countStmt->execute($bindings);
        $total = (int) $countStmt->fetchColumn();

        $bindings[] = $limit;
        $bindings[] = $offset;

        $stmt = $db->prepare(
            "SELECT * FROM `" . self::TABLE . "`{$whereSql} ORDER BY {$order} LIMIT ? OFFSET ?"
        );
        $stmt->execute($bindings);

        $items = array_map([self::class, 'normalize'], $stmt->fetchAll(\PDO::FETCH_ASSOC));

        return [
            'items' => $items,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    // -----------------------------------------------------------------------
    // Avatars & cover photos
    // -----------------------------------------------------------------------

    /**
     * Store an uploaded avatar and attach it to the profile.
     *
     * @param array $file $_FILES element
     * @return string|null Public URL of the stored avatar, null on failure.
     */
    public static function uploadAvatar(string $userId, array $file): ?string
    {
        FileStorage::setAllowedTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
        FileStorage::setMaxSize(5 * 1024 * 1024);

        $stored = FileStorage::store($file, 'user-' . $userId, 'avatars');
        if ($stored === null) {
            return null;
        }

        self::update($userId, ['avatar' => $stored['url']]);

        return $stored['url'];
    }

    /**
     * Store an uploaded cover photo and attach it to the profile.
     *
     * @param array $file $_FILES element
     * @return string|null Public URL of the stored cover, null on failure.
     */
    public static function uploadCover(string $userId, array $file): ?string
    {
        FileStorage::setAllowedTypes(['image/jpeg', 'image/png', 'image/webp']);
        FileStorage::setMaxSize(10 * 1024 * 1024);

        $stored = FileStorage::store($file, 'user-' . $userId, 'covers');
        if ($stored === null) {
            return null;
        }

        self::update($userId, ['cover_photo' => $stored['url']]);

        return $stored['url'];
    }

    /**
     * Remove an avatar (or cover) and fall back to the default.
     */
    public static function removeImage(string $userId, string $type = 'avatar'): bool
    {
        if (!in_array($type, ['avatar', 'cover_photo'], true)) {
            throw new \InvalidArgumentException("Unknown image type: {$type}");
        }

        return self::update($userId, [$type => '']);
    }

    /**
     * The avatar URL, defaulting to the placeholder.
     */
    public static function avatarUrl(string $userId): string
    {
        $profile = self::find($userId);
        $avatar = $profile['avatar'] ?? '';

        return $avatar !== '' ? $avatar : self::DEFAULT_AVATAR;
    }

    /**
     * The cover photo URL, or empty string when none is set.
     */
    public static function coverUrl(string $userId): string
    {
        $profile = self::find($userId);
        return (string) ($profile['cover_photo'] ?? '');
    }

    /**
     * Initials-based fallback avatar for a display name.
     */
    public static function initials(string $displayName): string
    {
        $parts = preg_split('/\s+/', trim($displayName)) ?: [];
        $first = $parts[0][0] ?? '';
        $last = isset($parts[1]) ? $parts[1][0] : '';

        return strtoupper($first . $last);
    }

    // -----------------------------------------------------------------------
    // Profile fields
    // -----------------------------------------------------------------------

    /**
     * Read one custom profile field.
     */
    public static function getField(string $userId, string $key, mixed $default = null): mixed
    {
        $fields = self::fields($userId);
        return $fields[$key] ?? $default;
    }

    /**
     * Read every custom profile field.
     *
     * @return array<string, mixed>
     */
    public static function fields(string $userId): array
    {
        $profile = self::find($userId);
        if ($profile === null || $profile['fields'] === null) {
            return [];
        }

        $fields = is_array($profile['fields']) ? $profile['fields'] : json_decode((string) $profile['fields'], true);
        return is_array($fields) ? $fields : [];
    }

    /**
     * Set one or more custom profile fields.
     *
     * @param array<string, mixed>|string $key Field map, or a single key when $value is given
     */
    public static function setField(string $userId, array|string $key, mixed $value = null): bool
    {
        $fields = self::fields($userId);

        if (is_array($key)) {
            $fields = array_merge($fields, $key);
        } else {
            $fields[$key] = $value;
        }

        return self::update($userId, ['fields' => $fields]);
    }

    /**
     * Remove a custom profile field.
     */
    public static function removeField(string $userId, string $key): bool
    {
        $fields = self::fields($userId);
        unset($fields[$key]);

        return self::update($userId, ['fields' => $fields]);
    }

    // -----------------------------------------------------------------------
    // Friend requests
    // -----------------------------------------------------------------------

    /**
     * Send a friend request. Returns false when the pair is already connected,
     * already pending, or blocked in either direction.
     */
    public static function sendFriendRequest(string $userId, string $friendId): bool
    {
        if ($userId === $friendId) {
            return false;
        }

        self::ensureSchema();

        if (self::areFriends($userId, $friendId) || self::hasPendingRequest($userId, $friendId)) {
            return false;
        }

        if (self::isBlocked($userId, $friendId)) {
            return false;
        }

        $db = (new Database())->getConnection();
        $ignoreSql = Migration::insertIgnorePrefix($db);
        $stmt = $db->prepare(
            $ignoreSql . " `user_friends` (user_id, friend_id, status) VALUES (?, ?, ?)"
        );
        $stmt->execute([$userId, $friendId, self::FRIEND_PENDING]);

        if ($stmt->rowCount() === 0) {
            return false;
        }

        EventDispatcher::dispatch('profile.friend.request', [
            'user_id' => $userId,
            'friend_id' => $friendId,
        ]);

        return true;
    }

    /**
     * Accept an incoming friend request.
     */
    public static function acceptFriendRequest(string $userId, string $friendId): bool
    {
        self::ensureSchema();

        $db = (new Database())->getConnection();
        $stmt = $db->prepare(
            "UPDATE `user_friends` SET status = ? WHERE user_id = ? AND friend_id = ? AND status = ?"
        );
        $stmt->execute([self::FRIEND_ACCEPTED, $friendId, $userId, self::FRIEND_PENDING]);

        if ($stmt->rowCount() === 0) {
            return false;
        }

        // The friendship is stored in both directions so either side can list it.
        $ignoreSql = Migration::insertIgnorePrefix($db);
        $db->prepare(
            $ignoreSql . " `user_friends` (user_id, friend_id, status) VALUES (?, ?, ?)"
        )->execute([$userId, $friendId, self::FRIEND_ACCEPTED]);

        $db->prepare(
            "UPDATE `user_friends` SET status = ? WHERE user_id = ? AND friend_id = ?"
        )->execute([self::FRIEND_ACCEPTED, $friendId, $userId]);

        EventDispatcher::dispatch('profile.friend.accepted', [
            'user_id' => $userId,
            'friend_id' => $friendId,
        ]);

        return true;
    }

    /**
     * Decline an incoming friend request.
     */
    public static function declineFriendRequest(string $userId, string $friendId): bool
    {
        self::ensureSchema();

        $db = (new Database())->getConnection();
        $stmt = $db->prepare(
            "UPDATE `user_friends` SET status = ? WHERE user_id = ? AND friend_id = ? AND status = ?"
        );
        $stmt->execute([self::FRIEND_REJECTED, $friendId, $userId, self::FRIEND_PENDING]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Cancel an outgoing friend request.
     */
    public static function cancelFriendRequest(string $userId, string $friendId): bool
    {
        self::ensureSchema();

        $db = (new Database())->getConnection();
        $stmt = $db->prepare(
            "DELETE FROM `user_friends` WHERE user_id = ? AND friend_id = ? AND status = ?"
        );
        $stmt->execute([$userId, $friendId, self::FRIEND_PENDING]);

        return $stmt->rowCount() > 0;
    }

    /**
     * End a friendship or withdraw a pending request.
     */
    public static function unfriend(string $userId, string $friendId): bool
    {
        self::ensureSchema();

        $db = (new Database())->getConnection();
        $stmt = $db->prepare(
            "DELETE FROM `user_friends` WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)"
        );
        $stmt->execute([$userId, $friendId, $friendId, $userId]);

        EventDispatcher::dispatch('profile.friend.removed', [
            'user_id' => $userId,
            'friend_id' => $friendId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Whether a pending request exists in either direction.
     */
    public static function hasPendingRequest(string $userId, string $friendId): bool
    {
        self::ensureSchema();

        $db = (new Database())->getConnection();
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM `user_friends`
             WHERE status = ? AND ((user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?))"
        );
        $stmt->execute([self::FRIEND_PENDING, $userId, $friendId, $friendId, $userId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Whether the two users are confirmed friends.
     */
    public static function areFriends(string $userId, string $friendId): bool
    {
        self::ensureSchema();

        $db = (new Database())->getConnection();
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM `user_friends`
             WHERE status = ? AND ((user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?))"
        );
        $stmt->execute([self::FRIEND_ACCEPTED, $userId, $friendId, $friendId, $userId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * The relationship between two users, from $userId's perspective.
     */
    public static function relationship(string $userId, string $friendId): string
    {
        if ($userId === $friendId) {
            return 'self';
        }

        if (self::areFriends($userId, $friendId)) {
            return 'friends';
        }

        if (self::hasPendingRequest($userId, $friendId)) {
            return self::hasOutgoingRequest($userId, $friendId) ? 'pending_outgoing' : 'pending_incoming';
        }

        return 'none';
    }

    /**
     * Whether $userId sent the pending request.
     */
    public static function hasOutgoingRequest(string $userId, string $friendId): bool
    {
        self::ensureSchema();

        $db = (new Database())->getConnection();
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM `user_friends` WHERE user_id = ? AND friend_id = ? AND status = ?"
        );
        $stmt->execute([$userId, $friendId, self::FRIEND_PENDING]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Confirmed friends of a user.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function friends(string $userId, int $limit = 50, int $offset = 0): array
    {
        self::ensureSchema();

        $db = (new Database())->getConnection();
        $stmt = $db->prepare(
            "SELECT u.* FROM `user_friends` f
             INNER JOIN `" . self::TABLE . "` u ON u.user_id = f.friend_id
             WHERE f.user_id = ? AND f.status = ?
             ORDER BY u.display_name ASC LIMIT ? OFFSET ?"
        );
        $stmt->execute([$userId, self::FRIEND_ACCEPTED, $limit, $offset]);

        return array_map([self::class, 'normalize'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Incoming friend requests addressed to a user.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function incomingRequests(string $userId, int $limit = 50): array
    {
        self::ensureSchema();

        $db = (new Database())->getConnection();
        $stmt = $db->prepare(
            "SELECT u.* FROM `user_friends` f
             INNER JOIN `" . self::TABLE . "` u ON u.user_id = f.user_id
             WHERE f.friend_id = ? AND f.status = ?
             ORDER BY f.created_at DESC LIMIT ?"
        );
        $stmt->execute([$userId, self::FRIEND_PENDING, $limit]);

        return array_map([self::class, 'normalize'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Outgoing friend requests sent by a user.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function outgoingRequests(string $userId, int $limit = 50): array
    {
        self::ensureSchema();

        $db = (new Database())->getConnection();
        $stmt = $db->prepare(
            "SELECT u.* FROM `user_friends` f
             INNER JOIN `" . self::TABLE . "` u ON u.user_id = f.friend_id
             WHERE f.user_id = ? AND f.status = ?
             ORDER BY f.created_at DESC LIMIT ?"
        );
        $stmt->execute([$userId, self::FRIEND_PENDING, $limit]);

        return array_map([self::class, 'normalize'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Count confirmed friends.
     */
    public static function friendCount(string $userId): int
    {
        self::ensureSchema();

        $db = (new Database())->getConnection();
        $stmt = $db->prepare("SELECT COUNT(*) FROM `user_friends` WHERE user_id = ? AND status = ?");
        $stmt->execute([$userId, self::FRIEND_ACCEPTED]);

        return (int) $stmt->fetchColumn();
    }

    // -----------------------------------------------------------------------
    // Blocking
    // -----------------------------------------------------------------------

    /**
     * Block a user. Any existing friendship or pending request is removed.
     */
    public static function block(string $userId, string $blockedUserId, string $reason = ''): bool
    {
        if ($userId === $blockedUserId) {
            return false;
        }

        self::ensureSchema();

        $db = (new Database())->getConnection();

        // Portable upsert — SQLite has no ON DUPLICATE KEY UPDATE.
        $db->prepare(
            "DELETE FROM `user_blocks` WHERE user_id = ? AND blocked_user_id = ?"
        )->execute([$userId, $blockedUserId]);
        $db->prepare(
            "INSERT INTO `user_blocks` (user_id, blocked_user_id, reason) VALUES (?, ?, ?)"
        )->execute([$userId, $blockedUserId, substr($reason, 0, 255)]);

        $db->prepare(
            "DELETE FROM `user_friends` WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)"
        )->execute([$userId, $blockedUserId, $blockedUserId, $userId]);

        EventDispatcher::dispatch('profile.blocked', [
            'user_id' => $userId,
            'blocked_user_id' => $blockedUserId,
        ]);

        return true;
    }

    /**
     * Lift a block.
     */
    public static function unblock(string $userId, string $blockedUserId): bool
    {
        self::ensureSchema();

        $db = (new Database())->getConnection();
        $stmt = $db->prepare("DELETE FROM `user_blocks` WHERE user_id = ? AND blocked_user_id = ?");
        $stmt->execute([$userId, $blockedUserId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Whether a block exists in either direction.
     */
    public static function isBlocked(string $userId, string $otherUserId): bool
    {
        self::ensureSchema();

        $db = (new Database())->getConnection();
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM `user_blocks`
             WHERE (user_id = ? AND blocked_user_id = ?) OR (user_id = ? AND blocked_user_id = ?)"
        );
        $stmt->execute([$userId, $otherUserId, $otherUserId, $userId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Whether $userId specifically blocked $otherUserId.
     */
    public static function hasBlocked(string $userId, string $otherUserId): bool
    {
        self::ensureSchema();

        $db = (new Database())->getConnection();
        $stmt = $db->prepare("SELECT COUNT(*) FROM `user_blocks` WHERE user_id = ? AND blocked_user_id = ?");
        $stmt->execute([$userId, $otherUserId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * The user IDs blocked by $userId.
     *
     * @return array<int, string>
     */
    public static function blockedIds(string $userId): array
    {
        self::ensureSchema();

        $db = (new Database())->getConnection();
        $stmt = $db->prepare("SELECT blocked_user_id FROM `user_blocks` WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->execute([$userId]);

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * The users who blocked $userId.
     *
     * @return array<int, string>
     */
    public static function blockedBy(string $userId): array
    {
        self::ensureSchema();

        $db = (new Database())->getConnection();
        $stmt = $db->prepare("SELECT user_id FROM `user_blocks` WHERE blocked_user_id = ? ORDER BY created_at DESC");
        $stmt->execute([$userId]);

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Whether $viewer may see $target's profile, honouring visibility and blocks.
     */
    public static function canView(string $targetUserId, ?string $viewerUserId = null): bool
    {
        $viewerUserId = $viewerUserId ?? self::currentUserId();

        if ($viewerUserId === null || $viewerUserId === $targetUserId) {
            return true;
        }

        if (self::isBlocked($targetUserId, $viewerUserId)) {
            return false;
        }

        $profile = self::find($targetUserId);
        if ($profile === null) {
            return false;
        }

        return match ($profile['visibility']) {
            self::VISIBILITY_PUBLIC => true,
            self::VISIBILITY_FRIENDS => self::areFriends($targetUserId, $viewerUserId),
            default => false,
        };
    }

    /**
     * Profiles that should be hidden from a viewer because they blocked them.
     *
     * @param array<int, string> $userIds
     * @return array<int, string> The subset that is visible.
     */
    public static function filterBlocked(array $userIds, ?string $viewerUserId = null): array
    {
        $viewerUserId = $viewerUserId ?? self::currentUserId();
        if ($viewerUserId === null || empty($userIds)) {
            return $userIds;
        }

        $blocked = self::blockedBy($viewerUserId);

        return array_values(array_diff($userIds, $blocked));
    }

    // -----------------------------------------------------------------------
    // Presence & stats
    // -----------------------------------------------------------------------

    /**
     * Whether a user was active within the last $seconds.
     */
    public static function isOnline(string $userId, int $seconds = 300): bool
    {
        $profile = self::find($userId);
        if ($profile === null || empty($profile['last_seen_at'])) {
            return false;
        }

        return (time() - strtotime((string) $profile['last_seen_at'])) <= $seconds;
    }

    /**
     * Summary counts for a profile card.
     *
     * @return array<string, int>
     */
    public static function stats(string $userId): array
    {
        return [
            'friends' => self::friendCount($userId),
            'incoming_requests' => count(self::incomingRequests($userId)),
            'blocked' => count(self::blockedIds($userId)),
        ];
    }

    /**
     * Public-safe representation of a profile.
     *
     * @return array<string, mixed>
     */
    public static function toArray(string $userId, ?string $viewerUserId = null): array
    {
        $profile = self::find($userId);
        if ($profile === null) {
            return [];
        }

        if (!self::canView($userId, $viewerUserId)) {
            return [
                'user_id' => $userId,
                'display_name' => $profile['display_name'],
                'avatar' => self::DEFAULT_AVATAR,
                'visibility' => self::VISIBILITY_PRIVATE,
            ];
        }

        return $profile;
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
     * Coerce a visibility string to a known value.
     */
    protected static function normalizeVisibility(mixed $value): string
    {
        $value = strtolower((string) ($value ?? ''));

        return in_array($value, [self::VISIBILITY_PUBLIC, self::VISIBILITY_FRIENDS, self::VISIBILITY_PRIVATE], true)
            ? $value
            : self::VISIBILITY_PUBLIC;
    }

    /**
     * Coerce a date to Y-m-d, or null.
     */
    protected static function normalizeDate(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }

    /**
     * Normalize a raw profile row for output.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected static function normalize(array $row): array
    {
        $row['fields'] = is_string($row['fields'] ?? null)
            ? (json_decode($row['fields'], true) ?: [])
            : (array) ($row['fields'] ?? []);

        $row['is_verified'] = (bool) ($row['is_verified'] ?? false);
        $row['is_active'] = (bool) ($row['is_active'] ?? true);
        $row['visibility'] = self::normalizeVisibility($row['visibility'] ?? null);
        $row['avatar'] = (string) ($row['avatar'] ?? '');
        $row['cover_photo'] = (string) ($row['cover_photo'] ?? '');
        $row['initials'] = self::initials((string) ($row['display_name'] ?? ''));

        return $row;
    }
}
