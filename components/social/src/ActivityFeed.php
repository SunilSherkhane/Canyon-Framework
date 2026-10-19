<?php

namespace Canyon\Core;

/**
 * Canyon Framework - Activity Feed
 *
 * A user wall / newsfeed with posts, likes, comments, and shares. Posts carry
 * an audience (public, friends, private), optional attachments, and moderation
 * flags; interactions are idempotent so a double-click never double-counts.
 *
 * Inspired by Facebook's newsfeed and Elgg's river.
 */
class ActivityFeed
{
    // -----------------------------------------------------------------------
    // Constants
    // -----------------------------------------------------------------------

    /** Audience levels. */
    public const AUDIENCE_PUBLIC = 'public';
    public const AUDIENCE_FRIENDS = 'friends';
    public const AUDIENCE_PRIVATE = 'private';

    /** Interaction kinds. */
    public const LIKE = 'like';
    public const COMMENT = 'comment';
    public const SHARE = 'share';

    /** Post moderation state. */
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_DRAFT = 'draft';
    public const STATUS_HIDDEN = 'hidden';

    /** Maximum body length. */
    public const MAX_BODY_LENGTH = 20000;

    // -----------------------------------------------------------------------
    // Schema
    // -----------------------------------------------------------------------

    /**
     * Create the posts, likes, comments, and shares tables.
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
            "CREATE TABLE IF NOT EXISTS `posts` (
                " . $pk() . ",
                user_id VARCHAR(64) NOT NULL,
                content TEXT NOT NULL,
                audience VARCHAR(20) NOT NULL DEFAULT '" . self::AUDIENCE_PUBLIC . "',
                status VARCHAR(20) NOT NULL DEFAULT '" . self::STATUS_PUBLISHED . "',
                parent_id INT UNSIGNED NULL,
                shared_post_id INT UNSIGNED NULL,
                attachment VARCHAR(255) NOT NULL DEFAULT '',
                attachment_type VARCHAR(20) NOT NULL DEFAULT '',
                location VARCHAR(255) NOT NULL DEFAULT '',
                like_count INT UNSIGNED NOT NULL DEFAULT 0,
                comment_count INT UNSIGNED NOT NULL DEFAULT 0,
                share_count INT UNSIGNED NOT NULL DEFAULT 0,
                is_pinned TINYINT(1) NOT NULL DEFAULT 0,
                " . $ts('created_at') . ",
                " . $ts('updated_at', update: true) . "
            ){$options}"
        );

        $db->exec(
            "CREATE TABLE IF NOT EXISTS `post_likes` (
                " . $pk() . ",
                post_id INT UNSIGNED NOT NULL,
                user_id VARCHAR(64) NOT NULL,
                " . $ts('created_at') . "
            ){$options}"
        );

        $db->exec(
            "CREATE TABLE IF NOT EXISTS `post_comments` (
                " . $pk() . ",
                post_id INT UNSIGNED NOT NULL,
                parent_id INT UNSIGNED NULL,
                user_id VARCHAR(64) NOT NULL,
                content TEXT NOT NULL,
                " . $ts('created_at') . ",
                " . $ts('updated_at', update: true) . "
            ){$options}"
        );

        $db->exec(
            "CREATE TABLE IF NOT EXISTS `post_shares` (
                " . $pk() . ",
                post_id INT UNSIGNED NOT NULL,
                user_id VARCHAR(64) NOT NULL,
                comment TEXT NULL,
                audience VARCHAR(20) NOT NULL DEFAULT '" . self::AUDIENCE_PUBLIC . "',
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

        $db->exec("CREATE INDEX IF NOT EXISTS `idx_post_user` ON `posts` (user_id, created_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS `idx_post_audience` ON `posts` (audience)");
        $db->exec("CREATE INDEX IF NOT EXISTS `idx_post_parent` ON `posts` (parent_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS `idx_post_created` ON `posts` (created_at)");
        $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS `uq_like` ON `post_likes` (post_id, user_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS `idx_like_user` ON `post_likes` (user_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS `idx_comment_post` ON `post_comments` (post_id, id)");
        $db->exec("CREATE INDEX IF NOT EXISTS `idx_comment_parent` ON `post_comments` (parent_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS `idx_comment_user` ON `post_comments` (user_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS `idx_share_post` ON `post_shares` (post_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS `idx_share_user` ON `post_shares` (user_id)");
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

    // -----------------------------------------------------------------------
    // Posts
    // -----------------------------------------------------------------------

    /**
     * Publish a post to a wall / feed.
     *
     * @param array<string, mixed> $options audience, parent_id, attachment, location, status
     * @return array<string, mixed>|null Null when the content is empty or too long.
     */
    public static function create(string $userId, string $content, array $options = []): ?array
    {
        $content = trim($content);
        if ($content === '' || mb_strlen($content) > self::MAX_BODY_LENGTH) {
            return null;
        }

        self::ensureSchema();

        $db = (new Database())->getConnection();
        $stmt = $db->prepare(
            "INSERT INTO `posts`
                (user_id, content, audience, status, parent_id, attachment, attachment_type, location)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->execute([
            $userId,
            Waf::sanitizeValue($content),
            self::normalizeAudience($options['audience'] ?? null),
            self::normalizeStatus($options['status'] ?? null),
            !empty($options['parent_id']) ? (int) $options['parent_id'] : null,
            (string) ($options['attachment'] ?? ''),
            (string) ($options['attachment_type'] ?? ''),
            substr((string) ($options['location'] ?? ''), 0, 255),
        ]);

        $postId = (int) $db->lastInsertId();

        // Replying to a post bumps the parent's comment count.
        if (!empty($options['parent_id'])) {
            self::incrementCounter((int) $options['parent_id'], 'comment_count');
        }

        EventDispatcher::dispatch('feed.post.created', [
            'post_id' => $postId,
            'user_id' => $userId,
            'audience' => $options['audience'] ?? self::AUDIENCE_PUBLIC,
        ]);

        return self::find($postId, $userId);
    }

    /**
     * Update a post's content or audience. Only the author may do this.
     */
    public static function update(int $postId, string $userId, array $data): bool
    {
        self::ensureSchema();

        $post = self::find($postId);
        if ($post === null || $post['user_id'] !== $userId) {
            return false;
        }

        $updates = [];
        $bindings = [];

        if (isset($data['content'])) {
            $content = trim((string) $data['content']);
            if ($content === '' || mb_strlen($content) > self::MAX_BODY_LENGTH) {
                return false;
            }
            $updates[] = 'content = ?';
            $bindings[] = Waf::sanitizeValue($content);
        }

        if (isset($data['audience'])) {
            $updates[] = 'audience = ?';
            $bindings[] = self::normalizeAudience($data['audience']);
        }

        if (isset($data['status'])) {
            $updates[] = 'status = ?';
            $bindings[] = self::normalizeStatus($data['status']);
        }

        if (isset($data['location'])) {
            $updates[] = 'location = ?';
            $bindings[] = substr((string) $data['location'], 0, 255);
        }

        if (isset($data['is_pinned'])) {
            $updates[] = 'is_pinned = ?';
            $bindings[] = $data['is_pinned'] ? 1 : 0;
        }

        if (empty($updates)) {
            return false;
        }

        $bindings[] = $postId;
        $bindings[] = $userId;

        $db = (new Database())->getConnection();
        $stmt = $db->prepare(
            "UPDATE `posts` SET " . implode(', ', $updates) . " WHERE id = ? AND user_id = ?"
        );

        return $stmt->execute($bindings);
    }

    /**
     * Soft-hide a post (author) or hard-delete it (author/admin).
     */
    public static function delete(int $postId, ?string $userId = null, bool $hard = false): bool
    {
        self::ensureSchema();

        $post = self::find($postId);
        if ($post === null) {
            return false;
        }

        $viewerId = $userId ?? self::currentUserId();
        if ($viewerId !== null && $post['user_id'] !== $viewerId) {
            return false;
        }

        $db = (new Database())->getConnection();

        if ($hard) {
            $db->prepare("DELETE FROM `post_comments` WHERE post_id = ?")->execute([$postId]);
            $db->prepare("DELETE FROM `post_likes` WHERE post_id = ?")->execute([$postId]);
            $db->prepare("DELETE FROM `post_shares` WHERE post_id = ?")->execute([$postId]);
            $db->prepare("DELETE FROM `posts` WHERE id = ?")->execute([$postId]);

            EventDispatcher::dispatch('feed.post.deleted', ['post_id' => $postId]);

            return true;
        }

        $stmt = $db->prepare("UPDATE `posts` SET status = ? WHERE id = ?");
        $stmt->execute([self::STATUS_HIDDEN, $postId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Fetch a single post when the viewer may see it.
     *
     * @return array<string, mixed>|null
     */
    public static function find(int $postId, ?string $viewerId = null): ?array
    {
        self::ensureSchema();

        $db = (new Database())->getConnection();
        $stmt = $db->prepare("SELECT * FROM `posts` WHERE id = ? LIMIT 1");
        $stmt->execute([$postId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $post = self::normalize($row);

        if (!self::canView($post, $viewerId)) {
            return null;
        }

        return $post;
    }

    /**
     * Read a post with its author, viewer's like state, and interaction counts.
     *
     * @return array<string, mixed>|null
     */
    public static function getPost(int $postId, ?string $viewerId = null): ?array
    {
        $post = self::find($postId, $viewerId);
        if ($post === null) {
            return null;
        }

        $viewerId = $viewerId ?? self::currentUserId();

        $post['author'] = self::author($post['user_id']);
        $post['liked_by_viewer'] = $viewerId !== null && self::hasLiked($postId, $viewerId);
        $post['like_users'] = self::likeUsers($postId, 10);

        return $post;
    }

    /**
     * A user's own wall posts.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, has_more: bool}
     */
    public static function wall(string $userId, ?string $viewerId = null, int $limit = 20, int $offset = 0): array
    {
        return self::feed([
            'user_id' => $userId,
            'viewer_id' => $viewerId,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * A user's friends' posts plus their own.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, has_more: bool}
     */
    public static function friendsFeed(string $userId, int $limit = 20, int $offset = 0): array
    {
        $friendIds = array_column(UserProfile::friends($userId, 500), 'user_id');
        $friendIds[] = $userId;

        return self::feed([
            'user_ids' => $friendIds,
            'viewer_id' => $userId,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * The global public feed.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, has_more: bool}
     */
    public static function publicFeed(int $limit = 20, int $offset = 0): array
    {
        return self::feed([
            'audience' => self::AUDIENCE_PUBLIC,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * Build a filtered feed.
     *
     * Supported filters: user_id, user_ids, audience, viewer_id, q, since_id, limit, offset.
     *
     * @param array<string, mixed> $filters
     * @return array{items: array<int, array<string, mixed>>, total: int, has_more: bool}
     */
    public static function feed(array $filters = []): array
    {
        self::ensureSchema();

        $limit = max(1, (int) ($filters['limit'] ?? 20));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $viewerId = $filters['viewer_id'] ?? self::currentUserId();

        [$where, $bindings] = self::buildFeedWhere($filters, $viewerId);

        $db = (new Database())->getConnection();

        $countSql = "SELECT COUNT(*) FROM `posts` p" . $where['sql'];
        $countStmt = $db->prepare($countSql);
        $countStmt->execute($where['bindings']);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT p.* FROM `posts` p{$where['sql']} ORDER BY p.is_pinned DESC, p.created_at DESC LIMIT ? OFFSET ?";
        $bindings = array_merge($where['bindings'], [$limit, $offset]);

        $stmt = $db->prepare($sql);
        $stmt->execute($bindings);

        $items = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $items[] = self::getPost((int) $row['id'], $viewerId);
        }

        return [
            'items' => array_filter($items),
            'total' => $total,
            'has_more' => ($offset + $limit) < $total,
        ];
    }

    /**
     * Assemble the WHERE clause shared by every feed variant.
     *
     * @param array<string, mixed> $filters
     * @return array{0: array{sql: string, bindings: array<int, mixed>}, 1: array<int, mixed>}
     */
    protected static function buildFeedWhere(array $filters, ?string $viewerId): array
    {
        $conditions = ['p.status = ?'];
        $bindings = [self::STATUS_PUBLISHED];

        if (!empty($filters['user_id'])) {
            $conditions[] = 'p.user_id = ?';
            $bindings[] = (string) $filters['user_id'];
        }

        if (!empty($filters['user_ids']) && is_array($filters['user_ids'])) {
            $ids = array_values(array_filter(array_map('strval', $filters['user_ids'])));
            if (!empty($ids)) {
                $conditions[] = 'p.user_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')';
                array_push($bindings, ...$ids);
            }
        }

        if (!empty($filters['audience'])) {
            $conditions[] = 'p.audience = ?';
            $bindings[] = self::normalizeAudience($filters['audience']);
        }

        if (!empty($filters['since_id'])) {
            $conditions[] = 'p.id > ?';
            $bindings[] = (int) $filters['since_id'];
        }

        if (!empty($filters['q'])) {
            $conditions[] = 'p.content LIKE ?';
            $bindings[] = '%' . $filters['q'] . '%';
        }

        // Audience is resolved against the viewer when one is supplied.
        if ($viewerId !== null) {
            if (!empty($filters['user_id']) && $filters['user_id'] === $viewerId) {
                // A user always sees their own posts regardless of audience.
                $conditions[] = '(p.audience = ? OR p.user_id = ?)';
                array_push($bindings, self::AUDIENCE_PUBLIC, $viewerId);
            } else {
                $conditions[] = '(
                    p.audience = ?
                    OR (p.audience = ? AND EXISTS (
                        SELECT 1 FROM `user_friends` f
                        WHERE f.status = ? AND (
                            (f.user_id = p.user_id AND f.friend_id = ?)
                            OR (f.user_id = ? AND f.friend_id = p.user_id)
                        )
                    ))
                )';
                array_push(
                    $bindings,
                    self::AUDIENCE_PUBLIC,
                    self::AUDIENCE_FRIENDS,
                    UserProfile::FRIEND_ACCEPTED,
                    $viewerId,
                    $viewerId
                );
            }
        }

        return [
            ['sql' => ' WHERE ' . implode(' AND ', $conditions), 'bindings' => $bindings],
            $bindings,
        ];
    }

    // -----------------------------------------------------------------------
    // Likes
    // -----------------------------------------------------------------------

    /**
     * Like a post. Idempotent: a repeat call is a no-op.
     */
    public static function like(int $postId, string $userId): bool
    {
        self::ensureSchema();

        if (self::hasLiked($postId, $userId)) {
            return true;
        }

        $db = (new Database())->getConnection();
        $ignoreSql = Migration::insertIgnorePrefix($db);
        $stmt = $db->prepare($ignoreSql . " `post_likes` (post_id, user_id) VALUES (?, ?)");

        if (!$stmt->execute([$postId, $userId]) || $stmt->rowCount() === 0) {
            return self::hasLiked($postId, $userId);
        }

        self::incrementCounter($postId, 'like_count');

        EventDispatcher::dispatch('feed.post.liked', [
            'post_id' => $postId,
            'user_id' => $userId,
        ]);

        return true;
    }

    /**
     * Remove a like. Idempotent.
     */
    public static function unlike(int $postId, string $userId): bool
    {
        self::ensureSchema();

        if (!self::hasLiked($postId, $userId)) {
            return true;
        }

        $db = (new Database())->getConnection();
        $db->prepare("DELETE FROM `post_likes` WHERE post_id = ? AND user_id = ?")->execute([$postId, $userId]);

        self::decrementCounter($postId, 'like_count');

        EventDispatcher::dispatch('feed.post.unliked', [
            'post_id' => $postId,
            'user_id' => $userId,
        ]);

        return true;
    }

    /**
     * Toggle a like; returns the resulting state (true = now liked).
     */
    public static function toggleLike(int $postId, string $userId): bool
    {
        if (self::hasLiked($postId, $userId)) {
            self::unlike($postId, $userId);

            return false;
        }

        self::like($postId, $userId);

        return true;
    }

    /**
     * Whether a user has liked a post.
     */
    public static function hasLiked(int $postId, string $userId): bool
    {
        self::ensureSchema();

        $db = (new Database())->getConnection();
        $stmt = $db->prepare("SELECT COUNT(*) FROM `post_likes` WHERE post_id = ? AND user_id = ?");
        $stmt->execute([$postId, $userId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * The most recent users who liked a post.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function likeUsers(int $postId, int $limit = 10): array
    {
        self::ensureSchema();

        $db = (new Database())->getConnection();
        $stmt = $db->prepare(
            "SELECT l.user_id FROM `post_likes` l
             WHERE l.post_id = ? ORDER BY l.created_at DESC LIMIT ?"
        );
        $stmt->execute([$postId, $limit]);

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    // -----------------------------------------------------------------------
    // Comments
    // -----------------------------------------------------------------------

    /**
     * Add a comment, optionally threaded under a parent comment.
     *
     * @return array<string, mixed>|null
     */
    public static function comment(int $postId, string $userId, string $content, ?int $parentId = null): ?array
    {
        $content = trim($content);
        if ($content === '' || mb_strlen($content) > self::MAX_BODY_LENGTH) {
            return null;
        }

        self::ensureSchema();

        $post = self::find($postId);
        if ($post === null) {
            return null;
        }

        $db = (new Database())->getConnection();
        $stmt = $db->prepare(
            "INSERT INTO `post_comments` (post_id, parent_id, user_id, content) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$postId, $parentId, $userId, Waf::sanitizeValue($content)]);

        $commentId = (int) $db->lastInsertId();

        self::incrementCounter($postId, 'comment_count');

        EventDispatcher::dispatch('feed.comment.created', [
            'comment_id' => $commentId,
            'post_id' => $postId,
            'user_id' => $userId,
        ]);

        return self::findComment($commentId);
    }

    /**
     * Fetch a comment.
     *
     * @return array<string, mixed>|null
     */
    public static function findComment(int $commentId): ?array
    {
        self::ensureSchema();

        $db = (new Database())->getConnection();
        $stmt = $db->prepare("SELECT * FROM `post_comments` WHERE id = ? LIMIT 1");
        $stmt->execute([$commentId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ? self::normalizeComment($row) : null;
    }

    /**
     * Edit a comment. Only the author may do this.
     */
    public static function updateComment(int $commentId, string $userId, string $content): bool
    {
        $content = trim($content);
        if ($content === '' || mb_strlen($content) > self::MAX_BODY_LENGTH) {
            return false;
        }

        self::ensureSchema();

        $db = (new Database())->getConnection();
        $stmt = $db->prepare(
            "UPDATE `post_comments` SET content = ? WHERE id = ? AND user_id = ?"
        );

        return $stmt->execute([Waf::sanitizeValue($content), $commentId, $userId]);
    }

    /**
     * Delete a comment and, when it is a thread root, its replies.
     */
    public static function deleteComment(int $commentId, string $userId, bool $isAdmin = false): bool
    {
        self::ensureSchema();

        $comment = self::findComment($commentId);
        if ($comment === null) {
            return false;
        }

        if (!$isAdmin && $comment['user_id'] !== $userId) {
            return false;
        }

        $db = (new Database())->getConnection();
        $db->prepare("DELETE FROM `post_comments` WHERE id = ? OR parent_id = ?")->execute([$commentId, $commentId]);

        $stmt = $db->prepare("SELECT COUNT(*) FROM `post_comments` WHERE post_id = ?");
        $stmt->execute([$comment['post_id']]);
        $remaining = (int) $stmt->fetchColumn();

        $db->prepare("UPDATE `posts` SET comment_count = ? WHERE id = ?")->execute([$remaining, $comment['post_id']]);

        return true;
    }

    /**
     * Read a page of comments, optionally threaded under one parent.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, has_more: bool}
     */
    public static function comments(int $postId, int $limit = 20, int $offset = 0, ?int $parentId = null): array
    {
        self::ensureSchema();

        $db = (new Database())->getConnection();

        $parentCondition = $parentId === null ? 'AND parent_id IS NULL' : 'AND parent_id = ?';
        $parentBindings = $parentId === null ? [] : [$parentId];

        $countStmt = $db->prepare("SELECT COUNT(*) FROM `post_comments` WHERE post_id = ? {$parentCondition}");
        $countStmt->execute(array_merge([$postId], $parentBindings));
        $total = (int) $countStmt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT * FROM `post_comments` WHERE post_id = ? {$parentCondition}
             ORDER BY id ASC LIMIT ? OFFSET ?"
        );
        $stmt->execute(array_merge([$postId], $parentBindings, [$limit, $offset]));

        $items = array_map([self::class, 'normalizeComment'], $stmt->fetchAll(\PDO::FETCH_ASSOC));

        return [
            'items' => $items,
            'total' => $total,
            'has_more' => ($offset + $limit) < $total,
        ];
    }

    // -----------------------------------------------------------------------
    // Shares
    // -----------------------------------------------------------------------

    /**
     * Share a post to the viewer's own feed.
     *
     * @return array<string, mixed>|null
     */
    public static function share(int $postId, string $userId, string $comment = '', array $options = []): ?array
    {
        self::ensureSchema();

        $source = self::find($postId, $userId);
        if ($source === null) {
            return null;
        }

        $db = (new Database())->getConnection();
        $stmt = $db->prepare(
            "INSERT INTO `post_shares` (post_id, user_id, comment, audience) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([
            $postId,
            $userId,
            $comment !== '' ? Waf::sanitizeValue($comment) : null,
            self::normalizeAudience($options['audience'] ?? null),
        ]);

        self::incrementCounter($postId, 'share_count');

        // Materialise the share as a feed post so it appears in timelines.
        $share = self::create($userId, $comment, [
            'parent_id' => null,
            'audience' => $options['audience'] ?? self::AUDIENCE_PUBLIC,
        ]);

        if ($share !== null) {
            $db->prepare("UPDATE `posts` SET shared_post_id = ? WHERE id = ?")->execute([$postId, $share['id']]);
        }

        EventDispatcher::dispatch('feed.post.shared', [
            'post_id' => $postId,
            'user_id' => $userId,
        ]);

        return self::find($postId, $userId);
    }

    /**
     * Who shared a post.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function shares(int $postId, int $limit = 20): array
    {
        self::ensureSchema();

        $db = (new Database())->getConnection();
        $stmt = $db->prepare(
            "SELECT s.*, p.content AS shared_content, p.user_id AS original_user_id
             FROM `post_shares` s
             INNER JOIN `posts` p ON p.id = s.post_id
             WHERE s.post_id = ? ORDER BY s.created_at DESC LIMIT ?"
        );
        $stmt->execute([$postId, $limit]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Remove one of the viewer's shares.
     */
    public static function unshare(int $postId, string $userId): bool
    {
        self::ensureSchema();

        $db = (new Database())->getConnection();
        $stmt = $db->prepare("DELETE FROM `post_shares` WHERE post_id = ? AND user_id = ?");
        $stmt->execute([$postId, $userId]);

        if ($stmt->rowCount() > 0) {
            self::decrementCounter($postId, 'share_count');
        }

        return true;
    }

    // -----------------------------------------------------------------------
    // Visibility & author
    // -----------------------------------------------------------------------

    /**
     * Whether a viewer may see a post.
     *
     * @param array<string, mixed> $post
     */
    public static function canView(array $post, ?string $viewerId = null): bool
    {
        if (($post['status'] ?? '') !== self::STATUS_PUBLISHED) {
            // Drafts and hidden posts are only visible to their author.
            return $viewerId !== null && ($post['user_id'] ?? '') === $viewerId;
        }

        return match ($post['audience'] ?? self::AUDIENCE_PUBLIC) {
            self::AUDIENCE_PUBLIC => true,
            self::AUDIENCE_FRIENDS => $viewerId !== null && UserProfile::areFriends($post['user_id'], $viewerId),
            default => $viewerId !== null && ($post['user_id'] ?? '') === $viewerId,
        };
    }

    /**
     * Whether the viewer may act on (like/comment/delete) a post.
     *
     * @param array<string, mixed> $post
     */
    public static function canInteract(array $post, ?string $viewerId = null): bool
    {
        $viewerId = $viewerId ?? self::currentUserId();
        if ($viewerId === null) {
            return false;
        }

        // A blocked pair cannot interact.
        if (UserProfile::isBlocked($post['user_id'], $viewerId)) {
            return false;
        }

        return self::canView($post, $viewerId);
    }

    /**
     * The author's public profile summary.
     *
     * @return array<string, mixed>
     */
    public static function author(string $userId): array
    {
        $profile = UserProfile::find($userId);

        if ($profile === null) {
            return [
                'user_id' => $userId,
                'display_name' => $userId,
                'avatar' => UserProfile::DEFAULT_AVATAR,
            ];
        }

        return [
            'user_id' => $profile['user_id'],
            'display_name' => $profile['display_name'],
            'avatar' => UserProfile::avatarUrl($userId),
            'initials' => $profile['initials'],
            'is_verified' => $profile['is_verified'],
        ];
    }

    /**
     * Strip posts whose authors blocked the viewer.
     *
     * @param array<int, array<string, mixed>> $posts
     * @return array<int, array<string, mixed>>
     */
    public static function filterBlocked(array $posts, ?string $viewerId = null): array
    {
        $viewerId = $viewerId ?? self::currentUserId();
        if ($viewerId === null || empty($posts)) {
            return $posts;
        }

        $blocked = UserProfile::blockedBy($viewerId);

        return array_values(array_filter(
            $posts,
            fn ($post) => !in_array($post['user_id'] ?? '', $blocked, true)
        ));
    }

    // -----------------------------------------------------------------------
    // Counters
    // -----------------------------------------------------------------------

    /**
     * Increment a post counter column.
     */
    public static function incrementCounter(int $postId, string $column): void
    {
        if (!in_array($column, ['like_count', 'comment_count', 'share_count'], true)) {
            throw new \InvalidArgumentException("Unknown counter column: {$column}");
        }

        $db = (new Database())->getConnection();
        $db->prepare("UPDATE `posts` SET {$column} = {$column} + 1 WHERE id = ?")->execute([$postId]);
    }

    /**
     * Decrement a post counter column, never below zero.
     */
    public static function decrementCounter(int $postId, string $column): void
    {
        if (!in_array($column, ['like_count', 'comment_count', 'share_count'], true)) {
            throw new \InvalidArgumentException("Unknown counter column: {$column}");
        }

        $db = (new Database())->getConnection();
        $db->prepare("UPDATE `posts` SET {$column} = GREATEST({$column} - 1, 0) WHERE id = ?")->execute([$postId]);
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
     * Coerce an audience string.
     */
    public static function normalizeAudience(mixed $value): string
    {
        $value = strtolower((string) ($value ?? ''));

        return in_array($value, [self::AUDIENCE_PUBLIC, self::AUDIENCE_FRIENDS, self::AUDIENCE_PRIVATE], true)
            ? $value
            : self::AUDIENCE_PUBLIC;
    }

    /**
     * Coerce a status string.
     */
    public static function normalizeStatus(mixed $value): string
    {
        $value = strtolower((string) ($value ?? ''));

        return in_array($value, [self::STATUS_PUBLISHED, self::STATUS_DRAFT, self::STATUS_HIDDEN], true)
            ? $value
            : self::STATUS_PUBLISHED;
    }

    /**
     * Normalize a post row.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected static function normalize(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['parent_id'] = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
        $row['shared_post_id'] = $row['shared_post_id'] !== null ? (int) $row['shared_post_id'] : null;
        $row['like_count'] = (int) ($row['like_count'] ?? 0);
        $row['comment_count'] = (int) ($row['comment_count'] ?? 0);
        $row['share_count'] = (int) ($row['share_count'] ?? 0);
        $row['is_pinned'] = (bool) ($row['is_pinned'] ?? false);
        $row['audience'] = self::normalizeAudience($row['audience'] ?? null);
        $row['status'] = self::normalizeStatus($row['status'] ?? null);
        $row['created_at'] = (string) ($row['created_at'] ?? '');

        return $row;
    }

    /**
     * Normalize a comment row.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected static function normalizeComment(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['post_id'] = (int) $row['post_id'];
        $row['parent_id'] = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
        $row['author'] = self::author((string) $row['user_id']);

        return $row;
    }
}
