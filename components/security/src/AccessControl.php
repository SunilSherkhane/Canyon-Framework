<?php

namespace Canyon\Core;

/**
 * Canyon Framework - Access Control
 *
 * Role-based and group-based access control, inspired by Elgg's access system.
 * Supports granular content access policies, private networks, and intranets.
 */
class AccessControl
{
    /** @var array Registered roles */
    protected static array $roles = [];

    /** @var array Role hierarchy */
    protected static array $hierarchy = [];

    /** @var array Group memberships */
    protected static array $groups = [];

    /** @var array Content access levels */
    protected static array $accessLevels = [];

    /**
     * Register a role.
     */
    public static function addRole(string $role, string $description = ''): void
    {
        self::$roles[$role] = $description;
    }

    /**
     * Register a role hierarchy (parent inherits child permissions).
     */
    public static function addRoleHierarchy(string $parent, string $child): void
    {
        if (!isset(self::$hierarchy[$parent])) {
            self::$hierarchy[$parent] = [];
        }
        self::$hierarchy[$parent][] = $child;
    }

    /**
     * Check if a user has a role.
     */
    public static function hasRole(string $userId, string $role): bool
    {
        return in_array($role, self::getUserRoles($userId));
    }

    /**
     * Get user roles.
     */
    public static function getUserRoles(string $userId): array
    {
        // Default roles
        $roles = ['user'];

        // Check admin role
        if (self::isAdmin($userId)) {
            $roles[] = 'admin';
        }

        return $roles;
    }

    /**
     * Check if user is admin.
     */
    public static function isAdmin(string $userId): bool
    {
        // Check if user is in admin list
        $admins = env('APP_ADMINS', '');
        if (empty($admins)) {
            return false;
        }
        return in_array($userId, array_map('trim', explode(',', $admins)));
    }

    /**
     * Add user to group.
     */
    public static function addGroup(string $userId, string $group): void
    {
        if (!isset(self::$groups[$group])) {
            self::$groups[$group] = [];
        }
        if (!in_array($userId, self::$groups[$group])) {
            self::$groups[$group][] = $userId;
        }
    }

    /**
     * Remove user from group.
     */
    public static function removeGroup(string $userId, string $group): void
    {
        if (isset(self::$groups[$group])) {
            self::$groups[$group] = array_diff(self::$groups[$group], [$userId]);
        }
    }

    /**
     * Check if user is in a group.
     */
    public static function inGroup(string $userId, string $group): bool
    {
        return in_array($userId, self::$groups[$group] ?? []);
    }

    /**
     * Set access level for content.
     */
    public static function setAccess(string $contentId, string $level, array $groups = []): void
    {
        self::$accessLevels[$contentId] = [
            'level' => $level,
            'groups' => $groups,
        ];
    }

    /**
     * Get access level for content.
     */
    public static function getAccess(string $contentId): array
    {
        return self::$accessLevels[$contentId] ?? ['level' => 'public', 'groups' => []];
    }

    /**
     * Check if user can access content.
     */
    public static function canAccess(string $contentId, ?string $userId = null, ?string $role = null): bool
    {
        $access = self::getAccess($contentId);
        $level = $access['level'];

        // Public access
        if ($level === 'public') {
            return true;
        }

        // Logged in required
        if ($level === 'logged_in') {
            return $userId !== null;
        }

        // Specific group access
        if ($level === 'group') {
            if ($userId === null) {
                return false;
            }
            foreach ($access['groups'] as $group) {
                if (self::inGroup($userId, $group)) {
                    return true;
                }
            }
            return false;
        }

        // Private (owner only)
        if ($level === 'private') {
            return $userId === $contentId; // Assuming contentId is the owner ID for simplicity
        }

        // Custom role check
        if ($role !== null) {
            if (in_array($role, $access['groups'] ?? [])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get content accessible by a user.
     */
    public static function getAccessible(?string $userId = null, ?string $role = null): array
    {
        $accessible = [];
        foreach (self::$accessLevels as $contentId => $access) {
            if (self::canAccess($contentId, $userId, $access['level'] ?? null)) {
                $accessible[] = $contentId;
            }
        }
        return $accessible;
    }

    /**
     * Get all roles.
     */
    public static function getRoles(): array
    {
        return array_keys(self::$roles);
    }

    /**
     * Get role hierarchy.
     */
    public static function getHierarchy(): array
    {
        return self::$hierarchy;
    }

    /**
     * Reset all access control data.
     */
    public static function reset(): void
    {
        self::$roles = [];
        self::$hierarchy = [];
        self::$groups = [];
        self::$accessLevels = [];
    }
}