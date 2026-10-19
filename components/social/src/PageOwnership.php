<?php

namespace Canyon\Core;

/**
 * Canyon Framework - Page Ownership
 *
 * Tracks page owners and context for access control.
 * Inspired by Elgg's page ownership system.
 */
class PageOwnership
{
    /** @var array Page owners */
    protected static array $owners = [];

    /** @var array Page contexts */
    protected static array $contexts = [];

    /** @var string Current page owner */
    protected static string $currentOwner = '';

    /** @var string Current context */
    protected static string $currentContext = '';

    /**
     * Set the page owner.
     */
    public static function setOwner(string $ownerId, string $ownerType = 'user'): void
    {
        self::$currentOwner = $ownerId;
        self::$owners[$ownerId] = [
            'id' => $ownerId,
            'type' => $ownerType,
            'set_at' => time(),
        ];
    }

    /**
     * Get the current page owner.
     */
    public static function getOwner(): string
    {
        return self::$currentOwner;
    }

    /**
     * Get the current page owner type.
     */
    public static function getOwnerType(): string
    {
        return self::$owners[self::$currentOwner]['type'] ?? 'user';
    }

    /**
     * Check if current user is the page owner.
     */
    public static function isOwner(?string $userId = null): bool
    {
        if ($userId === null) {
            $userId = $_SESSION['user_id'] ?? null;
        }
        return $userId !== null && $userId === self::$currentOwner;
    }

    /**
     * Set the page context.
     */
    public static function setContext(string $context): void
    {
        self::$currentContext = $context;
        self::$contexts[] = $context;
    }

    /**
     * Get the current context.
     */
    public static function getContext(): string
    {
        return self::$currentContext;
    }

    /**
     * Get all contexts.
     */
    public static function getContexts(): array
    {
        return self::$contexts;
    }

    /**
     * Check if current context matches.
     */
    public static function inContext(string $context): bool
    {
        return self::$currentContext === $context;
    }

    /**
     * Push a new context onto the stack.
     */
    public static function pushContext(string $context): void
    {
        self::$contexts[] = $context;
        self::$currentContext = $context;
    }

    /**
     * Pop the current context from the stack.
     */
    public static function popContext(): string
    {
        $previous = array_pop(self::$contexts);
        self::$currentContext = end(self::$contexts) ?: '';
        return $previous ?: '';
    }

    /**
     * Get the full context stack.
     */
    public static function getContextStack(): array
    {
        return self::$contexts;
    }

    /**
     * Get page metadata.
     */
    public static function getMetadata(): array
    {
        return [
            'owner' => self::$currentOwner,
            'owner_type' => self::getOwnerType(),
            'context' => self::$currentContext,
            'context_stack' => self::$contexts,
            'time' => time(),
        ];
    }

    /**
     * Get owner info.
     */
    public static function getOwnerInfo(): ?array
    {
        return self::$owners[self::$currentOwner] ?? null;
    }

    /**
     * Get all registered owners.
     */
    public static function getAllOwners(): array
    {
        return self::$owners;
    }

    /**
     * Clear all page ownership data.
     */
    public static function clear(): void
    {
        self::$owners = [];
        self::$contexts = [];
        self::$currentOwner = '';
        self::$currentContext = '';
    }
}