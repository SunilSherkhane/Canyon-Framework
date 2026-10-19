<?php

namespace Canyon\Core;

/**
 * Canyon Framework - Group System
 *
 * User-created groups with membership tiers, join requests, privacy levels,
 * and threaded discussions. Group content inherits the group's audience, so a
 * private group keeps its discussions out of the public feed.
 *
 * Facebook Groups and Elgg's group containers.
 */
class GroupSystem implements \Canyon\Contracts\GroupInterface
{
  // -----------------------------------------------------------------------
  // Constants
  // -----------------------------------------------------------------------

  /** Group privacy. */
  public const PRIVACY_PUBLIC = 'public';
  public const PRIVACY_CLOSED = 'closed';
  public const PRIVACY_SECRET = 'secret';

  /** Membership roles, ordered from least to most powerful. */
  public const ROLE_MEMBER = 'member';
  public const ROLE_MODERATOR = 'moderator';
  public const ROLE_ADMIN = 'admin';
  public const ROLE_OWNER = 'owner';

  /** Join-request lifecycle. */
  public const JOIN_PENDING = 'pending';
  public const JOIN_APPROVED = 'approved';
  public const JOIN_DECLINED = 'declined';

  /** Discussion state. */
  public const DISCUSSION_OPEN = 'open';
  public const DISCUSSION_CLOSED = 'closed';

  /** Maximum name length. */
  public const MAX_NAME_LENGTH = 100;

  // -----------------------------------------------------------------------
  // Schema
  // -----------------------------------------------------------------------

  /**
   * Create the groups, memberships, join requests, and discussions tables.
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
      "CREATE TABLE IF NOT EXISTS `groups` (
        " . $pk() . ",
        slug VARCHAR(120) NOT NULL,
        name VARCHAR(100) NOT NULL,
        description TEXT NULL,
        cover_photo VARCHAR(255) NOT NULL DEFAULT '',
        avatar VARCHAR(255) NOT NULL DEFAULT '',
        privacy VARCHAR(20) NOT NULL DEFAULT '" . self::PRIVACY_PUBLIC . "',
        join_approval TINYINT(1) NOT NULL DEFAULT 0,
        owner_id VARCHAR(64) NOT NULL,
        member_count INT UNSIGNED NOT NULL DEFAULT 0,
        discussion_count INT UNSIGNED NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        " . $ts('created_at') . ",
        " . $ts('updated_at', update: true) . "
      ){$options}"
    );

    $db->exec(
      "CREATE TABLE IF NOT EXISTS `group_members` (
        " . $pk() . ",
        group_id INT UNSIGNED NOT NULL,
        user_id VARCHAR(64) NOT NULL,
        role VARCHAR(20) NOT NULL DEFAULT '" . self::ROLE_MEMBER . "',
        status VARCHAR(20) NOT NULL DEFAULT '" . self::JOIN_APPROVED . "',
        " . $ts('created_at') . "
      ){$options}"
    );

    $db->exec(
      "CREATE TABLE IF NOT EXISTS `group_join_requests` (
        " . $pk() . ",
        group_id INT UNSIGNED NOT NULL,
        user_id VARCHAR(64) NOT NULL,
        message TEXT NULL,
        status VARCHAR(20) NOT NULL DEFAULT '" . self::JOIN_PENDING . "',
        decided_by VARCHAR(64) NULL,
        " . $ts('created_at') . "
      ){$options}"
    );

    $db->exec(
      "CREATE TABLE IF NOT EXISTS `group_discussions` (
        " . $pk() . ",
        group_id INT UNSIGNED NOT NULL,
        user_id VARCHAR(64) NOT NULL,
        title VARCHAR(255) NOT NULL,
        body TEXT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT '" . self::DISCUSSION_OPEN . "',
        reply_count INT UNSIGNED NOT NULL DEFAULT 0,
        is_pinned TINYINT(1) NOT NULL DEFAULT 0,
        " . $ts('created_at') . ",
        " . $ts('updated_at', update: true) . "
      ){$options}"
    );

    $db->exec(
      "CREATE TABLE IF NOT EXISTS `group_discussion_replies` (
        " . $pk() . ",
        discussion_id INT UNSIGNED NOT NULL,
        user_id VARCHAR(64) NOT NULL,
        body TEXT NOT NULL,
        " . $ts('created_at') . ",
        " . $ts('updated_at', update: true) . "
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

    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS `uq_slug` ON `groups` (slug)");
    $db->exec("CREATE INDEX IF NOT EXISTS `idx_group_privacy` ON `groups` (privacy)");
    $db->exec("CREATE INDEX IF NOT EXISTS `idx_group_owner` ON `groups` (owner_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS `idx_group_active` ON `groups` (is_active)");
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS `uq_membership` ON `group_members` (group_id, user_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS `idx_member_user_groups` ON `group_members` (user_id, role)");
    $db->exec("CREATE INDEX IF NOT EXISTS `idx_member_role` ON `group_members` (group_id, role)");
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS `uq_request` ON `group_join_requests` (group_id, user_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS `idx_request_pending` ON `group_join_requests` (group_id, status)");
    $db->exec("CREATE INDEX IF NOT EXISTS `idx_discussion_group` ON `group_discussions` (group_id, is_pinned, created_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS `idx_discussion_user` ON `group_discussions` (user_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS `idx_reply_discussion` ON `group_discussion_replies` (discussion_id, id)");
    $db->exec("CREATE INDEX IF NOT EXISTS `idx_reply_user` ON `group_discussion_replies` (user_id)");
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
  // Groups
  // -----------------------------------------------------------------------

  /**
   * Create a group; the creator becomes its owner.
   *
   * @param array<string, mixed> $data
   * @return array<string, mixed>|null
   */
  public static function create(string $ownerId, array $data = []): ?array
  {
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
      return null;
    }

    self::ensureSchema();

    $slug = self::uniqueSlug(self::slugify($data['slug'] ?? $name));

    $db = (new Database())->getConnection();
    $stmt = $db->prepare(
      "INSERT INTO `groups`
        (slug, name, description, cover_photo, avatar, privacy, join_approval, owner_id, member_count)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)"
    );

    $stmt->execute([
      $slug,
      Waf::sanitizeValue($name),
      Waf::sanitizeValue((string) ($data['description'] ?? '')),
      (string) ($data['cover_photo'] ?? ''),
      (string) ($data['avatar'] ?? ''),
      self::normalizePrivacy($data['privacy'] ?? null),
      !empty($data['join_approval']) ? 1 : 0,
      $ownerId,
    ]);

    $groupId = (int) $db->lastInsertId();

    // The owner is a full member from the start.
    self::addMember($groupId, $ownerId, self::ROLE_OWNER);

    EventDispatcher::dispatch('group.created', [
      'group_id' => $groupId,
      'owner_id' => $ownerId,
    ]);

    return self::find($groupId);
  }

  /**
   * Fetch a group.
   *
   * @return array<string, mixed>|null
   */
  public static function find(int $groupId): ?array
  {
    self::ensureSchema();

    $db = (new Database())->getConnection();
    $stmt = $db->prepare("SELECT * FROM `groups` WHERE id = ? LIMIT 1");
    $stmt->execute([$groupId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    return $row ? self::normalizeGroup($row) : null;
  }

  /**
   * Fetch a group by slug.
   *
   * @return array<string, mixed>|null
   */
  public static function findBySlug(string $slug): ?array
  {
    self::ensureSchema();

    $db = (new Database())->getConnection();
    $stmt = $db->prepare("SELECT * FROM `groups` WHERE slug = ? LIMIT 1");
    $stmt->execute([$slug]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    return $row ? self::normalizeGroup($row) : null;
  }

  /**
   * Update group settings. Only owners and admins may do this.
   *
   * @param array<string, mixed> $data
   */
  public static function update(int $groupId, string $userId, array $data): bool
  {
    self::ensureSchema();

    if (!self::canManage($groupId, $userId)) {
      return false;
    }

    $updates = [];
    $bindings = [];

    $allowed = [
      'name' => fn ($v) => Waf::sanitizeValue(substr((string) $v, 0, self::MAX_NAME_LENGTH)),
      'description' => fn ($v) => Waf::sanitizeValue((string) $v),
      'cover_photo' => fn ($v) => (string) $v,
      'avatar' => fn ($v) => (string) $v,
      'privacy' => fn ($v) => self::normalizePrivacy($v),
      'join_approval' => fn ($v) => $v ? 1 : 0,
      'is_active' => fn ($v) => $v ? 1 : 0,
    ];

    foreach ($data as $key => $value) {
      if (isset($allowed[$key])) {
        $updates[] = "`{$key}` = ?";
        $bindings[] = $allowed[$key]($value);
      }
    }

    // A slug can only change while the group is still unique.
    if (!empty($data['slug'])) {
      $candidate = self::slugify((string) $data['slug']);
      if ($candidate !== '' && !self::slugTaken($candidate, $groupId)) {
        $updates[] = 'slug = ?';
        $bindings[] = $candidate;
      }
    }

    if (empty($updates)) {
      return false;
    }

    $bindings[] = $groupId;

    $db = (new Database())->getConnection();
    $stmt = $db->prepare("UPDATE `groups` SET " . implode(', ', $updates) . " WHERE id = ?");

    return $stmt->execute($bindings);
  }

  /**
   * Soft-deactivate a group.
   */
  public static function deactivate(int $groupId, string $userId): bool
  {
    return self::update($groupId, $userId, ['is_active' => false]);
  }

  /**
   * Permanently delete a group and everything inside it.
   */
  public static function delete(int $groupId, string $userId, bool $isAdmin = false): bool
  {
    self::ensureSchema();

    if (!$isAdmin && !self::isOwner($groupId, $userId)) {
      return false;
    }

    $db = (new Database())->getConnection();
    $db->prepare("DELETE FROM `group_discussion_replies` WHERE discussion_id IN (SELECT id FROM `group_discussions` WHERE group_id = ?)")->execute([$groupId]);
    $db->prepare("DELETE FROM `group_discussions` WHERE group_id = ?")->execute([$groupId]);
    $db->prepare("DELETE FROM `group_join_requests` WHERE group_id = ?")->execute([$groupId]);
    $db->prepare("DELETE FROM `group_members` WHERE group_id = ?")->execute([$groupId]);

    $stmt = $db->prepare("DELETE FROM `groups` WHERE id = ?");
    $stmt->execute([$groupId]);

    EventDispatcher::dispatch('group.deleted', ['group_id' => $groupId]);

    return $stmt->rowCount() > 0;
  }

  /**
   * List groups with filters and pagination.
   *
   * @param array<string, mixed> $filters Keys: q, privacy, member_id, viewer_id, limit, offset, order
   * @return array{items: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
   */
  public static function list(array $filters = []): array
  {
    self::ensureSchema();

    $limit = max(1, (int) ($filters['limit'] ?? 20));
    $offset = max(0, (int) ($filters['offset'] ?? 0));
    $viewerId = $filters['viewer_id'] ?? self::currentUserId();

    $where = ['g.is_active = 1'];
    $bindings = [];

    if (!empty($filters['q'])) {
      $where[] = '(g.name LIKE ? OR g.description LIKE ?)';
      $like = '%' . $filters['q'] . '%';
      array_push($bindings, $like, $like);
    }

    // Privacy is resolved against the viewer: secret groups are only
    // discoverable by members.
    if (!empty($filters['privacy'])) {
      $where[] = 'g.privacy = ?';
      $bindings[] = self::normalizePrivacy($filters['privacy']);
    } elseif ($viewerId !== null) {
      $where[] = '(
        g.privacy IN (?, ?)
        OR (g.privacy = ? AND EXISTS (
          SELECT 1 FROM `group_members` m WHERE m.group_id = g.id AND m.user_id = ?
        ))
      )';
      array_push($bindings, self::PRIVACY_PUBLIC, self::PRIVACY_CLOSED, self::PRIVACY_SECRET, $viewerId);
    } else {
      $where[] = 'g.privacy IN (?, ?)';
      array_push($bindings, self::PRIVACY_PUBLIC, self::PRIVACY_CLOSED);
    }

    if (!empty($filters['member_id'])) {
      $where[] = 'EXISTS (SELECT 1 FROM `group_members` m WHERE m.group_id = g.id AND m.user_id = ?)';
      $bindings[] = (string) $filters['member_id'];
    }

    $whereSql = ' WHERE ' . implode(' AND ', $where);

    $db = (new Database())->getConnection();

    $countStmt = $db->prepare("SELECT COUNT(*) FROM `groups` g{$whereSql}");
    $countStmt->execute($bindings);
    $total = (int) $countStmt->fetchColumn();

    $bindings[] = $limit;
    $bindings[] = $offset;

    $stmt = $db->prepare(
      "SELECT g.* FROM `groups` g{$whereSql}
       ORDER BY g.member_count DESC, g.created_at DESC LIMIT ? OFFSET ?"
    );
    $stmt->execute($bindings);

    $items = array_map([self::class, 'normalizeGroup'], $stmt->fetchAll(\PDO::FETCH_ASSOC));

    return [
      'items' => $items,
      'total' => $total,
      'limit' => $limit,
      'offset' => $offset,
    ];
  }

  /**
   * The groups a user belongs to.
   *
   * @return array<int, array<string, mixed>>
   */
  public static function forUser(string $userId, int $limit = 50, int $offset = 0): array
  {
    self::ensureSchema();

    $db = (new Database())->getConnection();
    $stmt = $db->prepare(
      "SELECT g.* FROM `group_members` m
       INNER JOIN `groups` g ON g.id = m.group_id
       WHERE m.user_id = ? AND m.status = ? AND g.is_active = 1
       ORDER BY m.role DESC, g.name ASC LIMIT ? OFFSET ?"
    );
    $stmt->execute([$userId, self::JOIN_APPROVED, $limit, $offset]);

    return array_map([self::class, 'normalizeGroup'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
  }

  // -----------------------------------------------------------------------
  // Membership
  // -----------------------------------------------------------------------

  /**
   * Add a member directly (owner/admin action, or open-group join).
   */
  public static function addMember(int $groupId, string $userId, string $role = self::ROLE_MEMBER): bool
  {
    self::ensureSchema();

    $db = (new Database())->getConnection();
    $stmt = $db->prepare(
      "INSERT INTO `group_members` (group_id, user_id, role, status) VALUES (?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE status = VALUES(status)"
    );

    if (!$stmt->execute([$groupId, $userId, self::normalizeRole($role), self::JOIN_APPROVED])) {
      return false;
    }

    self::recountMembers($groupId);

    EventDispatcher::dispatch('group.member.added', [
      'group_id' => $groupId,
      'user_id' => $userId,
      'role' => $role,
    ]);

    return true;
  }

  /**
   * Change a member's role.
   */
  public static function setRole(int $groupId, string $userId, string $role): bool
  {
    self::ensureSchema();

    $db = (new Database())->getConnection();
    $stmt = $db->prepare(
      "UPDATE `group_members` SET role = ? WHERE group_id = ? AND user_id = ?"
    );

    return $stmt->execute([self::normalizeRole($role), $groupId, $userId]);
  }

  /**
   * Remove a member from a group.
   */
  public static function removeMember(int $groupId, string $userId): bool
  {
    self::ensureSchema();

    $db = (new Database())->getConnection();
    $stmt = $db->prepare("DELETE FROM `group_members` WHERE group_id = ? AND user_id = ?");
    $stmt->execute([$groupId, $userId]);

    if ($stmt->rowCount() > 0) {
      self::recountMembers($groupId);
    }

    return true;
  }

  /**
   * Whether the user is an approved member.
   */
  public static function isMember(int $groupId, ?string $userId = null): bool
  {
    self::ensureSchema();

    $userId = $userId ?? self::currentUserId();
    if ($userId === null) {
      return false;
    }

    $db = (new Database())->getConnection();
    $stmt = $db->prepare(
      "SELECT COUNT(*) FROM `group_members`
       WHERE group_id = ? AND user_id = ? AND status = ?"
    );
    $stmt->execute([$groupId, $userId, self::JOIN_APPROVED]);

    return (int) $stmt->fetchColumn() > 0;
  }

  /**
   * The user's role in a group, or null when not a member.
   */
  public static function role(int $groupId, ?string $userId = null): ?string
  {
    self::ensureSchema();

    $userId = $userId ?? self::currentUserId();
    if ($userId === null) {
      return null;
    }

    $db = (new Database())->getConnection();
    $stmt = $db->prepare(
      "SELECT role FROM `group_members`
       WHERE group_id = ? AND user_id = ? AND status = ? LIMIT 1"
    );
    $stmt->execute([$groupId, $userId, self::JOIN_APPROVED]);
    $role = $stmt->fetchColumn();

    return $role === false ? null : (string) $role;
  }

  /**
   * Whether the user created the group.
   */
  public static function isOwner(int $groupId, ?string $userId = null): bool
  {
    return self::role($groupId, $userId) === self::ROLE_OWNER;
  }

  /**
   * Whether the user can moderate (moderator, admin, or owner).
   */
  public static function canModerate(int $groupId, ?string $userId = null): bool
  {
    return self::roleRank(self::role($groupId, $userId)) >= self::roleRank(self::ROLE_MODERATOR);
  }

  /**
   * Whether the user can manage the group itself (admin or owner).
   */
  public static function canManage(int $groupId, ?string $userId = null): bool
  {
    return self::roleRank(self::role($groupId, $userId)) >= self::roleRank(self::ROLE_ADMIN);
  }

  /**
   * The members of a group, optionally filtered by role.
   *
   * @return array<int, array<string, mixed>>
   */
  public static function members(int $groupId, ?string $role = null, int $limit = 50, int $offset = 0): array
  {
    self::ensureSchema();

    $db = (new Database())->getConnection();

    $roleSql = $role !== null ? 'AND m.role = ?' : '';
    $bindings = [$groupId, self::JOIN_APPROVED];
    if ($role !== null) {
      $bindings[] = self::normalizeRole($role);
    }
    $bindings[] = $limit;
    $bindings[] = $offset;

    $stmt = $db->prepare(
      "SELECT m.user_id, m.role, m.created_at AS joined_at, p.display_name, p.avatar
       FROM `group_members` m
       LEFT JOIN `user_profiles` p ON p.user_id = m.user_id
       WHERE m.group_id = ? AND m.status = ? {$roleSql}
       ORDER BY FIELD(m.role, ?, ?, ?, ?), p.display_name ASC
       LIMIT ? OFFSET ?"
    );
    $stmt->execute(array_merge($bindings, [
      self::ROLE_OWNER, self::ROLE_ADMIN, self::ROLE_MODERATOR, self::ROLE_MEMBER,
    ]));

    $members = [];
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
      $members[] = [
        'user_id' => $row['user_id'],
        'role' => $row['role'],
        'joined_at' => $row['joined_at'],
        'display_name' => $row['display_name'] ?? $row['user_id'],
        'avatar' => $row['avatar'] ? $row['avatar'] : UserProfile::DEFAULT_AVATAR,
      ];
    }

    return $members;
  }

  /**
   * Count members, optionally by role.
   */
  public static function memberCount(int $groupId, ?string $role = null): int
  {
    self::ensureSchema();

    $db = (new Database())->getConnection();

    $sql = "SELECT COUNT(*) FROM `group_members` WHERE group_id = ? AND status = ?";
    $bindings = [$groupId, self::JOIN_APPROVED];

    if ($role !== null) {
      $sql .= ' AND role = ?';
      $bindings[] = self::normalizeRole($role);
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($bindings);

    return (int) $stmt->fetchColumn();
  }

  /**
   * Refresh the denormalised member counter from the membership table.
   */
  public static function recountMembers(int $groupId): void
  {
    $db = (new Database())->getConnection();
    $db->prepare(
      "UPDATE `groups` SET member_count = (
        SELECT COUNT(*) FROM `group_members` WHERE group_id = ? AND status = ?
       ) WHERE id = ?"
    )->execute([$groupId, self::JOIN_APPROVED, $groupId]);
  }

  // -----------------------------------------------------------------------
  // Join requests
  // -----------------------------------------------------------------------

  /**
   * Request to join a group. Open groups join instantly; closed and secret
   * groups queue a request for a moderator to decide.
   *
   * @return array{status: string, membership: string}|null
   */
  public static function requestJoin(int $groupId, string $userId, string $message = ''): ?array
  {
    self::ensureSchema();

    if (self::isMember($groupId, $userId) || self::hasPendingRequest($groupId, $userId)) {
      return null;
    }

    $group = self::find($groupId);
    if ($group === null || !$group['is_active']) {
      return null;
    }

    // Blocked users may not join.
    if (UserProfile::isBlocked($group['owner_id'], $userId)) {
      return null;
    }

    $db = (new Database())->getConnection();

    if (!$group['join_approval'] && $group['privacy'] === self::PRIVACY_PUBLIC) {
      self::addMember($groupId, $userId, self::ROLE_MEMBER);

      return ['status' => self::JOIN_APPROVED, 'membership' => self::ROLE_MEMBER];
    }

    $stmt = $db->prepare(
      "INSERT INTO `group_join_requests` (group_id, user_id, message) VALUES (?, ?, ?)
       ON DUPLICATE KEY UPDATE status = VALUES(status), message = VALUES(message)"
    );
    $stmt->execute([$groupId, $userId, Waf::sanitizeValue($message)]);

    EventDispatcher::dispatch('group.join.requested', [
      'group_id' => $groupId,
      'user_id' => $userId,
    ]);

    return ['status' => self::JOIN_PENDING, 'membership' => null];
  }

  /**
   * Approve a join request and add the user as a member.
   */
  public static function approveJoin(int $groupId, string $userId, ?string $decidedBy = null, string $role = self::ROLE_MEMBER): bool
  {
    self::ensureSchema();

    $db = (new Database())->getConnection();
    $stmt = $db->prepare(
      "UPDATE `group_join_requests` SET status = ?, decided_by = ?
       WHERE group_id = ? AND user_id = ? AND status = ?"
    );
    $stmt->execute([self::JOIN_APPROVED, $decidedBy, $groupId, $userId, self::JOIN_PENDING]);

    if ($stmt->rowCount() === 0) {
      return false;
    }

    self::addMember($groupId, $userId, $role);

    EventDispatcher::dispatch('group.join.approved', [
      'group_id' => $groupId,
      'user_id' => $userId,
    ]);

    return true;
  }

  /**
   * Decline a join request.
   */
  public static function declineJoin(int $groupId, string $userId, ?string $decidedBy = null): bool
  {
    self::ensureSchema();

    $db = (new Database())->getConnection();
    $stmt = $db->prepare(
      "UPDATE `group_join_requests` SET status = ?, decided_by = ?
       WHERE group_id = ? AND user_id = ? AND status = ?"
    );
    $stmt->execute([self::JOIN_DECLINED, $decidedBy, $groupId, $userId, self::JOIN_PENDING]);

    return $stmt->rowCount() > 0;
  }

  /**
   * Whether a pending request exists.
   */
  public static function hasPendingRequest(int $groupId, ?string $userId = null): bool
  {
    self::ensureSchema();

    $userId = $userId ?? self::currentUserId();
    if ($userId === null) {
      return false;
    }

    $db = (new Database())->getConnection();
    $stmt = $db->prepare(
      "SELECT COUNT(*) FROM `group_join_requests`
       WHERE group_id = ? AND user_id = ? AND status = ?"
    );
    $stmt->execute([$groupId, $userId, self::JOIN_PENDING]);

    return (int) $stmt->fetchColumn() > 0;
  }

  /**
   * Withdraw a pending request.
   */
  public static function cancelJoinRequest(int $groupId, string $userId): bool
  {
    self::ensureSchema();

    $db = (new Database())->getConnection();
    $stmt = $db->prepare(
      "DELETE FROM `group_join_requests`
       WHERE group_id = ? AND user_id = ? AND status = ?"
    );
    $stmt->execute([$groupId, $userId, self::JOIN_PENDING]);

    return $stmt->rowCount() > 0;
  }

  /**
   * Pending join requests for a group.
   *
   * @return array<int, array<string, mixed>>
   */
  public static function pendingRequests(int $groupId, int $limit = 50): array
  {
    self::ensureSchema();

    $db = (new Database())->getConnection();
    $stmt = $db->prepare(
      "SELECT r.*, p.display_name, p.avatar
       FROM `group_join_requests` r
       LEFT JOIN `user_profiles` p ON p.user_id = r.user_id
       WHERE r.group_id = ? AND r.status = ?
       ORDER BY r.created_at ASC LIMIT ?"
    );
    $stmt->execute([$groupId, self::JOIN_PENDING, $limit]);

    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Leave a group. The owner cannot leave while they are the only member.
   */
  public static function leave(int $groupId, string $userId): bool
  {
    self::ensureSchema();

    if (self::isOwner($groupId, $userId) && self::memberCount($groupId, self::ROLE_OWNER) <= 1) {
      return false;
    }

    return self::removeMember($groupId, $userId);
  }

  // -----------------------------------------------------------------------
  // Discussions
  // -----------------------------------------------------------------------

  /**
   * Start a discussion thread.
   *
   * @return array<string, mixed>|null
   */
  public static function createDiscussion(int $groupId, string $userId, string $title, string $body, array $options = []): ?array
  {
    $title = trim($title);
    $body = trim($body);

    if ($title === '' || $body === '' || mb_strlen($title) > 255) {
      return null;
    }

    self::ensureSchema();

    // Only members may post inside a group.
    if (!self::isMember($groupId, $userId)) {
      return null;
    }

    $db = (new Database())->getConnection();
    $stmt = $db->prepare(
      "INSERT INTO `group_discussions` (group_id, user_id, title, body, is_pinned)
       VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->execute([
      $groupId,
      $userId,
      Waf::sanitizeValue($title),
      Waf::sanitizeValue($body),
      !empty($options['is_pinned']) && self::canModerate($groupId, $userId) ? 1 : 0,
    ]);

    $discussionId = (int) $db->lastInsertId();

    $db->prepare(
      "UPDATE `groups` SET discussion_count = discussion_count + 1 WHERE id = ?"
    )->execute([$groupId]);

    EventDispatcher::dispatch('group.discussion.created', [
      'discussion_id' => $discussionId,
      'group_id' => $groupId,
      'user_id' => $userId,
    ]);

    return self::findDiscussion($discussionId);
  }

  /**
   * Fetch a discussion.
   *
   * @return array<string, mixed>|null
   */
  public static function findDiscussion(int $discussionId): ?array
  {
    self::ensureSchema();

    $db = (new Database())->getConnection();
    $stmt = $db->prepare("SELECT * FROM `group_discussions` WHERE id = ? LIMIT 1");
    $stmt->execute([$discussionId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    return $row ? self::normalizeDiscussion($row) : null;
  }

  /**
   * Update a discussion (author, or a moderator for moderation).
   *
   * @param array<string, mixed> $data
   */
  public static function updateDiscussion(int $discussionId, string $userId, array $data): bool
  {
    self::ensureSchema();

    $discussion = self::findDiscussion($discussionId);
    if ($discussion === null) {
      return false;
    }

    $isAuthor = $discussion['user_id'] === $userId;
    if (!$isAuthor && !self::canModerate($discussion['group_id'], $userId)) {
      return false;
    }

    $updates = [];
    $bindings = [];

    if (isset($data['title'])) {
      $title = trim((string) $data['title']);
      if ($title === '' || mb_strlen($title) > 255) {
        return false;
      }
      $updates[] = 'title = ?';
      $bindings[] = Waf::sanitizeValue($title);
    }

    if (isset($data['body'])) {
      $body = trim((string) $data['body']);
      if ($body === '') {
        return false;
      }
      $updates[] = 'body = ?';
      $bindings[] = Waf::sanitizeValue($body);
    }

    if (isset($data['status'])) {
      $updates[] = 'status = ?';
      $bindings[] = self::normalizeDiscussionStatus($data['status']);
    }

    // Pinning is a moderation action.
    if (isset($data['is_pinned']) && self::canModerate($discussion['group_id'], $userId)) {
      $updates[] = 'is_pinned = ?';
      $bindings[] = $data['is_pinned'] ? 1 : 0;
    }

    if (empty($updates)) {
      return false;
    }

    $bindings[] = $discussionId;

    $db = (new Database())->getConnection();
    $stmt = $db->prepare(
      "UPDATE `group_discussions` SET " . implode(', ', $updates) . " WHERE id = ?"
    );

    return $stmt->execute($bindings);
  }

  /**
   * Delete a discussion and its replies.
   */
  public static function deleteDiscussion(int $discussionId, string $userId, bool $isAdmin = false): bool
  {
    self::ensureSchema();

    $discussion = self::findDiscussion($discussionId);
    if ($discussion === null) {
      return false;
    }

    $isAuthor = $discussion['user_id'] === $userId;
    if (!$isAdmin && !$isAuthor && !self::canModerate($discussion['group_id'], $userId)) {
      return false;
    }

    $db = (new Database())->getConnection();
    $db->prepare("DELETE FROM `group_discussion_replies` WHERE discussion_id = ?")->execute([$discussionId]);
    $stmt = $db->prepare("DELETE FROM `group_discussions` WHERE id = ?");
    $stmt->execute([$discussionId]);

    if ($stmt->rowCount() > 0) {
      self::recountDiscussions($discussion['group_id']);
    }

    return true;
  }

  /**
   * List a group's discussions.
   *
   * @return array{items: array<int, array<string, mixed>>, total: int, has_more: bool}
   */
  public static function discussions(int $groupId, int $limit = 20, int $offset = 0, ?string $viewerId = null): array
  {
    self::ensureSchema();

    // Secret groups are only readable by members.
    if (!self::canViewGroup($groupId, $viewerId)) {
      return ['items' => [], 'total' => 0, 'has_more' => false];
    }

    $db = (new Database())->getConnection();

    $countStmt = $db->prepare("SELECT COUNT(*) FROM `group_discussions` WHERE group_id = ?");
    $countStmt->execute([$groupId]);
    $total = (int) $countStmt->fetchColumn();

    $stmt = $db->prepare(
      "SELECT d.*, p.display_name, p.avatar
       FROM `group_discussions` d
       LEFT JOIN `user_profiles` p ON p.user_id = d.user_id
       WHERE d.group_id = ?
       ORDER BY d.is_pinned DESC, d.created_at DESC LIMIT ? OFFSET ?"
    );
    $stmt->execute([$groupId, $limit, $offset]);

    $items = [];
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
      $items[] = array_merge(
        self::normalizeDiscussion($row),
        [
          'display_name' => $row['display_name'] ?? $row['user_id'],
          'avatar' => $row['avatar'] ? $row['avatar'] : UserProfile::DEFAULT_AVATAR,
        ]
      );
    }

    return [
      'items' => $items,
      'total' => $total,
      'has_more' => ($offset + $limit) < $total,
    ];
  }

  /**
   * Add a reply to a discussion.
   *
   * @return array<string, mixed>|null
   */
  public static function reply(int $discussionId, string $userId, string $body): ?array
  {
    $body = trim($body);
    if ($body === '') {
      return null;
    }

    self::ensureSchema();

    $discussion = self::findDiscussion($discussionId);
    if ($discussion === null || $discussion['status'] !== self::DISCUSSION_OPEN) {
      return null;
    }

    if (!self::isMember($discussion['group_id'], $userId)) {
      return null;
    }

    $db = (new Database())->getConnection();
    $stmt = $db->prepare(
      "INSERT INTO `group_discussion_replies` (discussion_id, user_id, body) VALUES (?, ?, ?)"
    );
    $stmt->execute([$discussionId, $userId, Waf::sanitizeValue($body)]);

    $replyId = (int) $db->lastInsertId();

    $db->prepare(
      "UPDATE `group_discussions` SET reply_count = reply_count + 1 WHERE id = ?"
    )->execute([$discussionId]);

    EventDispatcher::dispatch('group.discussion.replied', [
      'reply_id' => $replyId,
      'discussion_id' => $discussionId,
      'user_id' => $userId,
    ]);

    return [
      'id' => $replyId,
      'discussion_id' => $discussionId,
      'user_id' => $userId,
      'body' => Waf::sanitizeValue($body),
    ];
  }

  /**
   * Read a page of replies, oldest first.
   *
   * @return array{items: array<int, array<string, mixed>>, total: int, has_more: bool}
   */
  public static function replies(int $discussionId, int $limit = 20, int $offset = 0): array
  {
    self::ensureSchema();

    $db = (new Database())->getConnection();

    $countStmt = $db->prepare("SELECT COUNT(*) FROM `group_discussion_replies` WHERE discussion_id = ?");
    $countStmt->execute([$discussionId]);
    $total = (int) $countStmt->fetchColumn();

    $stmt = $db->prepare(
      "SELECT r.*, p.display_name, p.avatar
       FROM `group_discussion_replies` r
       LEFT JOIN `user_profiles` p ON p.user_id = r.user_id
       WHERE r.discussion_id = ? ORDER BY r.id ASC LIMIT ? OFFSET ?"
    );
    $stmt->execute([$discussionId, $limit, $offset]);

    $items = [];
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
      $items[] = array_merge(self::normalizeReply($row), [
        'display_name' => $row['display_name'] ?? $row['user_id'],
        'avatar' => $row['avatar'] ? $row['avatar'] : UserProfile::DEFAULT_AVATAR,
      ]);
    }

    return [
      'items' => $items,
      'total' => $total,
      'has_more' => ($offset + $limit) < $total,
    ];
  }

  /**
   * Refresh the denormalised discussion counter.
   */
  public static function recountDiscussions(int $groupId): void
  {
    $db = (new Database())->getConnection();
    $db->prepare(
      "UPDATE `groups` SET discussion_count = (
        SELECT COUNT(*) FROM `group_discussions` WHERE group_id = ?
       ) WHERE id = ?"
    )->execute([$groupId, $groupId]);
  }

  // -----------------------------------------------------------------------
  // Visibility
  // -----------------------------------------------------------------------

  /**
   * Whether a viewer may see a group at all.
   */
  public static function canViewGroup(int $groupId, ?string $viewerId = null): bool
  {
    $group = self::find($groupId);
    if ($group === null || !$group['is_active']) {
      return false;
    }

    if (in_array($group['privacy'], [self::PRIVACY_PUBLIC, self::PRIVACY_CLOSED], true)) {
      return true;
    }

    return self::isMember($groupId, $viewerId);
  }

  /**
   * Whether a viewer may post or reply inside a group.
   */
  public static function canParticipate(int $groupId, ?string $viewerId = null): bool
  {
    if (!self::canViewGroup($groupId, $viewerId)) {
      return false;
    }

    return self::isMember($groupId, $viewerId);
  }

  // -----------------------------------------------------------------------
  // Slugs & roles
  // -----------------------------------------------------------------------

  /**
   * Convert free text into a URL-safe slug.
   */
  public static function slugify(string $value): string
  {
    $slug = strtolower(trim($value));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');

    return substr($slug, 0, 120);
  }

  /**
   * Guarantee a slug is unique within the groups table.
   */
  protected static function uniqueSlug(string $base): string
  {
    $base = $base !== '' ? $base : 'group';
    $candidate = $base;
    $suffix = 1;

    while (self::slugTaken($candidate)) {
      $candidate = $base . '-' . (++$suffix);
    }

    return $candidate;
  }

  /**
   * Whether a slug already belongs to a group.
   */
  public static function slugTaken(string $slug, ?int $exceptGroupId = null): bool
  {
    self::ensureSchema();

    $db = (new Database())->getConnection();
    $sql = 'SELECT COUNT(*) FROM `groups` WHERE slug = ?';
    $bindings = [$slug];

    if ($exceptGroupId !== null) {
      $sql .= ' AND id != ?';
      $bindings[] = $exceptGroupId;
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($bindings);

    return (int) $stmt->fetchColumn() > 0;
  }

  /**
   * Coerce a role to a known value.
   */
  public static function normalizeRole(mixed $role): string
  {
    $role = strtolower((string) ($role ?? ''));

    return in_array($role, [self::ROLE_MEMBER, self::ROLE_MODERATOR, self::ROLE_ADMIN, self::ROLE_OWNER], true)
      ? $role
      : self::ROLE_MEMBER;
  }

  /**
   * Numeric rank for role comparisons; null (non-member) ranks lowest.
   */
  protected static function roleRank(?string $role): int
  {
    return match ($role) {
      self::ROLE_OWNER => 4,
      self::ROLE_ADMIN => 3,
      self::ROLE_MODERATOR => 2,
      self::ROLE_MEMBER => 1,
      default => 0,
    };
  }

  /**
   * Coerce a privacy string.
   */
  public static function normalizePrivacy(mixed $value): string
  {
    $value = strtolower((string) ($value ?? ''));

    return in_array($value, [self::PRIVACY_PUBLIC, self::PRIVACY_CLOSED, self::PRIVACY_SECRET], true)
      ? $value
      : self::PRIVACY_PUBLIC;
  }

  /**
   * Coerce a discussion status string.
   */
  protected static function normalizeDiscussionStatus(mixed $value): string
  {
    $value = strtolower((string) ($value ?? ''));

    return in_array($value, [self::DISCUSSION_OPEN, self::DISCUSSION_CLOSED], true)
      ? $value
      : self::DISCUSSION_OPEN;
  }

  // -----------------------------------------------------------------------
  // Normalization
  // -----------------------------------------------------------------------

  /**
   * Normalize a group row.
   *
   * @param array<string, mixed> $row
   * @return array<string, mixed>
   */
  protected static function normalizeGroup(array $row): array
  {
    $row['id'] = (int) $row['id'];
    $row['member_count'] = (int) ($row['member_count'] ?? 0);
    $row['discussion_count'] = (int) ($row['discussion_count'] ?? 0);
    $row['join_approval'] = (bool) ($row['join_approval'] ?? false);
    $row['is_active'] = (bool) ($row['is_active'] ?? true);
    $row['privacy'] = self::normalizePrivacy($row['privacy'] ?? null);
    $row['url'] = '/groups/' . $row['slug'];

    return $row;
  }

  /**
   * Normalize a discussion row.
   *
   * @param array<string, mixed> $row
   * @return array<string, mixed>
   */
  protected static function normalizeDiscussion(array $row): array
  {
    $row['id'] = (int) $row['id'];
    $row['group_id'] = (int) $row['group_id'];
    $row['reply_count'] = (int) ($row['reply_count'] ?? 0);
    $row['is_pinned'] = (bool) ($row['is_pinned'] ?? false);
    $row['status'] = self::normalizeDiscussionStatus($row['status'] ?? null);

    return $row;
  }

  /**
   * Normalize a reply row.
   *
   * @param array<string, mixed> $row
   * @return array<string, mixed>
   */
  protected static function normalizeReply(array $row): array
  {
    $row['id'] = (int) $row['id'];
    $row['discussion_id'] = (int) $row['discussion_id'];

    return $row;
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
}
