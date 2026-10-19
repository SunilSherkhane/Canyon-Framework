<?php

namespace Canyon\Core;

/**
 * Canyon Framework - RBAC (Yii2)
 *
 * Role-Based Access Control with roles, permissions, and hierarchy.
 */
class Rbac
{
    /** @var array Roles and their permissions */
    protected static array $roles = [];

    /** @var array User role assignments */
    protected static array $assignments = [];

    /** @var array Permission cache */
    protected static array $cache = [];

    /** @var string Database table prefix */
    protected static string $tablePrefix = 'rbac_';

    /**
     * Initialize RBAC.
     */
    public static function init(): void
    {
        // Load roles from config or database
        self::$roles = Config::get('rbac.roles', [
            'admin' => ['label' => 'Administrator', 'permissions' => ['*']],
            'editor' => ['label' => 'Editor', 'permissions' => ['posts.create', 'posts.edit', 'posts.publish']],
            'author' => ['label' => 'Author', 'permissions' => ['posts.create', 'posts.edit']],
            'user' => ['label' => 'User', 'permissions' => ['posts.view']],
        ]);
    }

    // -----------------------------------------------------------------------
    // Role Management
    // -----------------------------------------------------------------------

    /**
     * Create a role.
     */
    public static function createRole(string $name, string $description = '', array $permissions = []): bool
    {
        if (self::hasRole($name)) {
            return false;
        }

        self::$roles[$name] = [
            'name' => $name,
            'description' => $description,
            'permissions' => $permissions,
            'children' => [],
        ];

        self::saveToDatabase('item', self::$roles[$name]);
        return true;
    }

    /**
     * Update a role.
     */
    public static function updateRole(string $name, array $data): bool
    {
        if (!self::hasRole($name)) {
            return false;
        }

        self::$roles[$name] = array_merge(self::$roles[$name], $data);
        self::saveToDatabase('item', self::$roles[$name]);
        return true;
    }

    /**
     * Delete a role.
     */
    public static function deleteRole(string $name): bool
    {
        if (!self::hasRole($name)) {
            return false;
        }

        unset(self::$roles[$name]);
        self::deleteFromDatabase('item', $name);

        // Remove all assignments
        foreach (self::$assignments as $userId => $roles) {
            unset(self::$assignments[$userId][$name]);
        }

        return true;
    }

    /**
     * Check if role exists.
     */
    public static function hasRole(string $name): bool
    {
        return isset(self::$roles[$name]);
    }

    /**
     * Get role details.
     */
    public static function getRole(string $name): ?array
    {
        return self::$roles[$name] ?? null;
    }

    /**
     * Get all roles.
     */
    public static function getRoles(): array
    {
        return self::$roles;
    }

    // -----------------------------------------------------------------------
    // Permission Management
    // -----------------------------------------------------------------------

    /**
     * Create a permission.
     */
    public static function createPermission(string $name, string $description = ''): bool
    {
        if (self::hasPermission($name)) {
            return false;
        }

        self::$roles['_permissions'][$name] = [
            'name' => $name,
            'description' => $description,
        ];

        return true;
    }

    /**
     * Check if permission exists.
     */
    public static function hasPermission(string $name): bool
    {
        return isset(self::$roles['_permissions'][$name]);
    }

    /**
     * Add permission to role.
     */
    public static function addPermissionToRole(string $permission, string $role): bool
    {
        if (!self::hasRole($role)) {
            return false;
        }

        if (!in_array($permission, self::$roles[$role]['permissions'])) {
            self::$roles[$role]['permissions'][] = $permission;
        }

        return true;
    }

    /**
     * Remove permission from role.
     */
    public static function removePermissionFromRole(string $permission, string $role): bool
    {
        if (!self::hasRole($role)) {
            return false;
        }

        $key = array_search($permission, self::$roles[$role]['permissions']);
        if ($key !== false) {
            unset(self::$roles[$role]['permissions'][$key]);
        }

        return true;
    }

    // -----------------------------------------------------------------------
    // Role Hierarchy
    // -----------------------------------------------------------------------

    /**
     * Add child role (inherits permissions from parent).
     */
    public static function addChild(string $parent, string $child): bool
    {
        if (!self::hasRole($parent) || !self::hasRole($child)) {
            return false;
        }

        if (!in_array($child, self::$roles[$parent]['children'])) {
            self::$roles[$parent]['children'][] = $child;
        }

        return true;
    }

    /**
     * Remove child role.
     */
    public static function removeChild(string $parent, string $child): bool
    {
        if (!self::hasRole($parent)) {
            return false;
        }

        $key = array_search($child, self::$roles[$parent]['children']);
        if ($key !== false) {
            unset(self::$roles[$parent]['children'][$key]);
        }

        return true;
    }

    /**
     * Get all permissions for a role (including inherited).
     */
    public static function getPermissionsByRole(string $role): array
    {
        if (!self::hasRole($role)) {
            return [];
        }

        $permissions = self::$roles[$role]['permissions'];
        foreach (self::$roles[$role]['children'] as $child) {
            $permissions = array_merge($permissions, self::getPermissionsByRole($child));
        }

        return array_unique($permissions);
    }

    // -----------------------------------------------------------------------
    // User Assignment
    // -----------------------------------------------------------------------

    /**
     * Assign role to user.
     */
    public static function assign(string $role, int $userId): bool
    {
        if (!self::hasRole($role)) {
            return false;
        }

        if (!isset(self::$assignments[$userId])) {
            self::$assignments[$userId] = [];
        }

        if (!in_array($role, self::$assignments[$userId])) {
            self::$assignments[$userId][] = $role;
            self::saveToDatabase('assignment', [
                'role' => $role,
                'user_id' => $userId,
            ]);
        }

        return true;
    }

    /**
     * Revoke role from user.
     */
    public static function revoke(string $role, int $userId): bool
    {
        if (!isset(self::$assignments[$userId])) {
            return false;
        }

        $key = array_search($role, self::$assignments[$userId]);
        if ($key !== false) {
            unset(self::$assignments[$userId][$key]);
            self::deleteFromDatabase('assignment', null, ['role' => $role, 'user_id' => $userId]);
        }

        return true;
    }

    /**
     * Revoke all roles from user.
     */
    public static function revokeAll(int $userId): bool
    {
        if (!isset(self::$assignments[$userId])) {
            return false;
        }

        unset(self::$assignments[$userId]);
        return true;
    }

    /**
     * Get user's roles.
     */
    public static function getRolesByUser(int $userId): array
    {
        return self::$assignments[$userId] ?? [];
    }

    /**
     * Get users assigned to a role.
     */
    public static function getUsersByRole(string $role): array
    {
        $users = [];
        foreach (self::$assignments as $userId => $roles) {
            if (in_array($role, $roles)) {
                $users[] = $userId;
            }
        }
        return $users;
    }

    // -----------------------------------------------------------------------
    // Access Check
    // -----------------------------------------------------------------------

    /**
     * Check if user can perform an action.
     */
    public static function can(int $userId, string $permission, array $params = []): bool
    {
        // Check cache
        $cacheKey = "{$userId}:{$permission}";
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $roles = self::getRolesByUser($userId);

        foreach ($roles as $role) {
            $rolePermissions = self::getPermissionsByRole($role);

            // Wildcard permission
            if (in_array('*', $rolePermissions)) {
                self::$cache[$cacheKey] = true;
                return true;
            }

            // Direct permission
            if (in_array($permission, $rolePermissions)) {
                self::$cache[$cacheKey] = true;
                return true;
            }

            // Parameterized permission (e.g., "editPost" with ["postId" => 1])
            foreach ($rolePermissions as $rolePerm) {
                if (str_starts_with($rolePerm, $permission . ':')) {
                    $permParams = explode(':', $rolePerm, 2)[1] ?? '';
                    // Check if user owns the resource
                    if (self::checkOwnership($permParams, $params)) {
                        self::$cache[$cacheKey] = true;
                        return true;
                    }
                }
            }
        }

        self::$cache[$cacheKey] = false;
        return false;
    }

    /**
     * Check if user has a specific role.
     */
    public static function hasRoleForUser(int $userId, string $role): bool
    {
        return in_array($role, self::getRolesByUser($userId));
    }

    /**
     * Check ownership for parameterized permissions.
     */
    protected static function checkOwnership(string $rule, array $params): bool
    {
        // Example: "author_id" means the user must match the author_id param
        if (isset($params[$rule])) {
            return $params[$rule] === ($params['user_id'] ?? null);
        }
        return false;
    }

    // -----------------------------------------------------------------------
    // Database Persistence
    // -----------------------------------------------------------------------

    /**
     * Save to database.
     */
    protected static function saveToDatabase(string $type, array $data): void
    {
        $table = self::$tablePrefix . $type;
        $db = new Database();
        $db->insert($table, $data);
    }

    /**
     * Delete from database.
     */
    protected static function deleteFromDatabase(string $type, ?string $name, array $where = []): void
    {
        $table = self::$tablePrefix . $type;
        $db = new Database();
        $db->delete($table, $where);
    }

    // -----------------------------------------------------------------------
    // Utilities
    // -----------------------------------------------------------------------

    /**
     * Clear cache.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Get all permissions available in the system.
     */
    public static function getAllPermissions(): array
    {
        $permissions = [];
        foreach (self::$roles as $roleName => $role) {
            if ($roleName === '_permissions') continue;
            $permissions = array_merge($permissions, $role['permissions']);
        }
        return array_unique($permissions);
    }

    /**
     * Get role tree (hierarchy visualization).
     */
    public static function getRoleTree(): array
    {
        $tree = [];
        foreach (self::$roles as $name => $role) {
            if ($name === '_permissions') continue;
            $tree[$name] = [
                'label' => $role['description'] ?? $name,
                'children' => $role['children'],
            ];
        }
        return $tree;
    }
}