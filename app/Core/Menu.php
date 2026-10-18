<?php

namespace Canyon\Core;

/**
 * Canyon Framework - Menu System
 *
 * Flexible menu registration and rendering.
 * Inspired by Elgg's menu system.
 */
class Menu
{
    /** @var array Registered menus */
    protected static array $menus = [];

    /** @var array Menu item priorities */
    protected static array $priorities = [];

    /**
     * Add an item to a menu.
     *
     * @param string $menuName Menu name (e.g., 'main', 'footer', 'sidebar')
     * @param string $itemName Unique item identifier
     * @param array $config Item configuration
     */
    public static function add(string $menuName, string $itemName, array $config = []): void
    {
        if (!isset(self::$menus[$menuName])) {
            self::$menus[$menuName] = [];
        }

        self::$menus[$menuName][$itemName] = array_merge([
            'name' => $itemName,
            'text' => $itemName,
            'href' => '#',
            'title' => '',
            'class' => '',
            'id' => '',
            'priority' => 500,
            'children' => [],
            'data' => [],
        ], $config);

        // Track priority for sorting
        self::$priorities[$menuName][$itemName] = self::$menus[$menuName][$itemName]['priority'];
    }

    /**
     * Remove an item from a menu.
     */
    public static function remove(string $menuName, string $itemName): void
    {
        unset(self::$menus[$menuName][$itemName]);
        unset(self::$priorities[$menuName][$itemName]);
    }

    /**
     * Get a menu.
     */
    public static function get(string $menuName): array
    {
        $menu = self::$menus[$menuName] ?? [];
        uasort($menu, fn($a, $b) => $a['priority'] <=> $b['priority']);
        return $menu;
    }

    /**
     * Get all menus.
     */
    public static function all(): array
    {
        return self::$menus;
    }

    /**
     * Check if a menu exists.
     */
    public static function has(string $menuName): bool
    {
        return isset(self::$menus[$menuName]) && !empty(self::$menus[$menuName]);
    }

    /**
     * Get a specific menu item.
     */
    public static function getItem(string $menuName, string $itemName): ?array
    {
        return self::$menus[$menuName][$itemName] ?? null;
    }

    /**
     * Update a menu item.
     */
    public static function update(string $menuName, string $itemName, array $config): void
    {
        if (isset(self::$menus[$menuName][$itemName])) {
            self::$menus[$menuName][$itemName] = array_merge(
                self::$menus[$menuName][$itemName],
                $config
            );
            if (isset($config['priority'])) {
                self::$priorities[$menuName][$itemName] = $config['priority'];
            }
        }
    }

    /**
     * Add a child item to a menu item.
     */
    public static function addChild(string $menuName, string $parentName, string $childName, array $config = []): void
    {
        if (!isset(self::$menus[$menuName][$parentName])) {
            throw new \InvalidArgumentException("Parent menu item not found: {$parentName}");
        }

        self::$menus[$menuName][$parentName]['children'][$childName] = array_merge([
            'name' => $childName,
            'text' => $childName,
            'href' => '#',
            'priority' => 500,
        ], $config);

        // Sort children by priority
        uasort(self::$menus[$menuName][$parentName]['children'], fn($a, $b) => $a['priority'] <=> $b['priority']);
    }

    /**
     * Render a menu as HTML.
     */
    public static function render(string $menuName, string $class = ''): string
    {
        $menu = self::get($menuName);
        if (empty($menu)) {
            return '';
        }

        $classAttr = $class ? " class=\"{$class}\"" : '';
        $html = "<ul{$classAttr}>\n";

        foreach ($menu as $item) {
            $html .= self::renderItem($item);
        }

        $html .= "</ul>\n";
        return $html;
    }

    /**
     * Render a single menu item.
     */
    protected static function renderItem(array $item): string
    {
        $attributes = [];
        if ($item['class']) $attributes[] = "class=\"{$item['class']}\"";
        if ($item['id']) $attributes[] = "id=\"{$item['id']}\"";
        if ($item['title']) $attributes[] = "title=\"{$item['title']}\"";

        // Add data attributes
        foreach ($item['data'] as $key => $value) {
            $attributes[] = "data-{$key}=\"{$value}\"";
        }

        $attrStr = empty($attributes) ? '' : ' ' . implode(' ', $attributes);

        $html = "  <li{$attrStr}><a href=\"{$item['href']}\">{$item['text']}</a>";

        // Render children
        if (!empty($item['children'])) {
            $html .= "\n    <ul>\n";
            foreach ($item['children'] as $child) {
                $html .= "      <li><a href=\"{$child['href']}\">{$child['text']}</a></li>\n";
            }
            $html .= "    </ul>\n  ";
        }

        $html .= "</li>\n";
        return $html;
    }

    /**
     * Clear all menus.
     */
    public static function clear(): void
    {
        self::$menus = [];
        self::$priorities = [];
    }

    /**
     * Get menu item count.
     */
    public static function count(string $menuName): int
    {
        return count(self::$menus[$menuName] ?? []);
    }

    /**
     * Set menu item priority.
     */
    public static function setPriority(string $menuName, string $itemName, int $priority): void
    {
        self::update($menuName, $itemName, ['priority' => $priority]);
    }
}