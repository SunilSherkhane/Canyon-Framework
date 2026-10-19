<?php

namespace Canyon\Core;

/**
 * Canyon Framework - Permissions
 *
 * Entity-level permissions check system.
 * based on Elgg's permissions system.
 */
class Permissions
{
    /** @var array Registered permissions */
    protected static array $permissions = [];

    /** @var array Permission callbacks */
    protected static array $callbacks = [];

    /**
     * Register a permission.
     */
    public static function register(string $name, callable $callback): void
    {
        self::$permissions[$name] = $callback;
    }

    /**
     * Check if a user has permission.
     */
    public static function can(string $permission, mixed $entity = null, ?string $userId = null): bool
    {
        $userId = $userId ?? ($_SESSION['user_id'] ?? null);

        // Admin always has permission
        if ($userId && AccessControl::isAdmin($userId)) {
            return true;
        }

        // Owner always has permission
        if ($entity && method_exists($entity, 'owner_id') && $entity->owner_id === $userId) {
            return true;
        }

        // Check registered callback
        if (isset(self::$permissions[$permission])) {
            return call_user_func(self::$permissions[$permission], $entity, $userId);
        }

        return false;
    }

    /**
     * Check if user can edit entity.
     */
    public static function canEdit(mixed $entity, ?string $userId = null): bool
    {
        return self::can('edit', $entity, $userId);
    }

    /**
     * Check if user can delete entity.
     */
    public static function canDelete(mixed $entity, ?string $userId = null): bool
    {
        return self::can('delete', $entity, $userId);
    }

    /**
     * Check if user can view entity.
     */
    public static function canView(mixed $entity, ?string $userId = null): bool
    {
        $userId = $userId ?? ($_SESSION['user_id'] ?? null);

        // Public entity
        if (method_exists($entity, 'access_level') && $entity->access_level === 'public') {
            return true;
        }

        // Logged in users can view logged_in entities
        if ($userId && method_exists($entity, 'access_level') && $entity->access_level === 'logged_in') {
            return true;
        }

        // Owner can view own entity
        if ($userId && method_exists($entity, 'owner_id') && $entity->owner_id === $userId) {
            return true;
        }

        return false;
    }

    /**
     * Require permission or throw exception.
     */
    public static function require(string $permission, mixed $entity = null, ?string $userId = null): void
    {
        if (!self::can($permission, $entity, $userId)) {
            throw new \RuntimeException("Permission denied: {$permission}");
        }
    }

    /**
     * Get all registered permissions.
     */
    public static function all(): array
    {
        return self::$permissions;
    }
}