<?php

namespace Canyon\Core\Block;

use Canyon\Core\Database;
use Canyon\Core\Cache;
use Canyon\Core\Plugin;

/**
 * Block Module - Content Regions & Blocks
 *
 * Similar to Drupal's Block module.
 * Provides regions, blocks, placement, and visibility control.
 */
class BlockModule
{
    // -----------------------------------------------------------------------
    // Region Management
    // -----------------------------------------------------------------------

    /**
     * Create a region.
     */
    public static function createRegion(string $name, string $label, string $theme = 'default'): bool
    {
        return Database::insert('block_regions', [
            'name' => $name,
            'label' => $label,
            'theme' => $theme,
            'created_at' => date('Y-m-d H:i:s'),
        ]) > 0;
    }

    /**
     * Get regions for a theme.
     */
    public static function getRegions(string $theme = 'default'): array
    {
        return Database::statement(
            'SELECT * FROM block_regions WHERE theme = ? ORDER BY weight, label',
            [$theme]
        );
    }

    // -----------------------------------------------------------------------
    // Block Definition
    // -----------------------------------------------------------------------

    /**
     * Create a block definition.
     */
    public static function createBlock(array $data): int
    {
        return Database::insert('blocks', [
            'name' => $data['name'],
            'label' => $data['label'] ?? $data['name'],
            'type' => $data['type'] ?? 'custom',
            'plugin_id' => $data['plugin_id'] ?? null,
            'theme' => $data['theme'] ?? 'default',
            'status' => $data['status'] ?? 1,
            'weight' => $data['weight'] ?? 0,
            'settings' => json_encode($data['settings'] ?? []),
            'visibility' => json_encode($data['visibility'] ?? []),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Get all blocks.
     */
    public static function getBlocks(string $theme = 'default'): array
    {
        return Database::statement(
            'SELECT * FROM blocks WHERE theme = ? ORDER BY weight, label',
            [$theme]
        );
    }

    // -----------------------------------------------------------------------
    // Block Placement
    // -----------------------------------------------------------------------

    /**
     * Place a block in a region.
     */
    public static function place(int $blockId, string $region, array $options = []): bool
    {
        $existing = Database::statement(
            'SELECT id FROM block_placement WHERE block_id = ? AND region = ? AND theme = ?',
            [$blockId, $region, $options['theme'] ?? 'default']
        );

        $data = [
            'block_id' => $blockId,
            'region' => $region,
            'theme' => $options['theme'] ?? 'default',
            'weight' => $options['weight'] ?? 0,
            'status' => $options['status'] ?? 1,
            'visibility' => json_encode($options['visibility'] ?? []),
        ];

        if (!empty($existing)) {
            return Database::update('block_placement', $data, ['id' => $existing[0]['id']]) > 0;
        }

        return Database::insert('block_placement', $data) > 0;
    }

    /**
     * Get blocks for a region.
     */
    public static function getRegionBlocks(string $region, string $theme = 'default'): array
    {
        return Database::statement(
            'SELECT b.*, bp.weight, bp.visibility FROM blocks b
             JOIN block_placement bp ON b.id = bp.block_id
             WHERE bp.region = ? AND bp.theme = ? AND bp.status = 1 AND b.status = 1
             ORDER BY bp.weight ASC, b.label ASC',
            [$region, $theme]
        );
    }

    // -----------------------------------------------------------------------
    // Visibility / Context
    // -----------------------------------------------------------------------

    /**
     * Check whether a block is visible on a route.
     */
    public static function isVisible(array $block, array $request): bool
    {
        $visibility = $block['visibility'] ?? [];
        if (!is_array($visibility)) {
            return true;
        }

        $route = $request['route'] ?? '';

        foreach ($visibility['pages'] ?? [] as $pattern) {
            if ($pattern === '<front>') {
                if ($route === '') return true;
            } elseif (str_starts_with($pattern, '/')) {
                if ($route === $pattern) return true;
            } else {
                if (fnmatch($pattern, $route)) return true;
            }
        }

        return false;
    }

    // -----------------------------------------------------------------------
    // Rendering
    // -----------------------------------------------------------------------

    /**
     * Render blocks for a region.
     */
    public static function renderRegion(string $region, array $request = [], string $theme = 'default'): string
    {
        $blocks = self::getRegionBlocks($region, $theme);
        if (empty($blocks)) return '';

        $html = '';
        foreach ($blocks as $block) {
            if (!self::isVisible($block, $request)) {
                continue;
            }
            $html .= self::renderBlock($block);
        }

        return $html;
    }

    /**
     * Render a single block.
     */
    public static function renderBlock(array $block): string
    {
        $type = $block['type'];
        $settings = json_decode($block['settings'] ?? '[]', true);
        $label = e($block['label']);

        if ($type === 'custom') {
            $content = $settings['content'] ?? '';
            return "<div class=\"block block-custom\"><h3 class=\"block-title\">{$label}</h3><div class=\"block-content\">{$content}</div></div>";
        }

        $plugin = self::resolvePlugin($type);
        if ($plugin && method_exists($plugin, 'build')) {
            $content = $plugin->build($settings);
            return "<div class=\"block block-{$type}\"><h3 class=\"block-title\">{$label}</h3><div class=\"block-content\">{$content}</div></div>";
        }

        return "<div class=\"block block-{$type}\"><h3 class=\"block-title\">{$label}</h3><div class=\"block-content\"></div></div>";
    }

    /**
     * Resolve a block plugin.
     */
    protected static function resolvePlugin(string $type): ?object
    {
        $class = 'Canyon\\Core\\Block\\Block' . ucfirst($type);
        if (class_exists($class)) {
            return new $class();
        }

        $plugins = Plugin::getPlugins();
        foreach ($plugins as $plugin) {
            if (($plugin['block_type'] ?? '') === $type) {
                $class = $plugin['block_class'] ?? null;
                if ($class && class_exists($class)) {
                    return new $class();
                }
            }
        }

        return null;
    }
}