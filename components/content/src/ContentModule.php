<?php

namespace Canyon\Core\Content;

use Canyon\Core\Database;
use Canyon\Core\Entity;
use Canyon\Core\Validation;

/**
 * Content Module - Core Content Management
 *
 * Provides content entities, types, and CRUD operations.
 * Implements a flexible content architecture similar to Drupal's Node system.
 */
class ContentModule
{
    /**
     * Supported content types.
     */
    public const STANDARD_TYPES = ['article', 'page', 'blog', 'news', 'event'];

    /**
     * Content status values.
     */
    public const STATUS_DRAFT = 0;
    public const STATUS_PUBLISHED = 1;
    public const STATUS_ARCHIVED = 2;

    /**
     * Register a content type.
     */
    public static function registerType(string $type, array $settings = []): bool
    {
        if (in_array($type, self::STANDARD_TYPES) || $type === 'custom') {
            return Database::insert('content_types', [
                'type' => $type,
                'name' => $settings['name'] ?? ucfirst($type),
                'description' => $settings['description'] ?? '',
                'settings' => json_encode($settings),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
        return false;
    }

    /**
     * Get all content types.
     */
    public static function getTypes(): array
    {
        $result = Database::statement('SELECT * FROM content_types ORDER BY created_at DESC');
        return $result;
    }

    /**
     * Create a new content entity.
     */
    public static function create(string $type, array $data, int $authorId): int
    {
        $data['type'] = $type;
        $data['author_id'] = $authorId;
        $data['status'] = $data['status'] ?? self::STATUS_PUBLISHED;
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');

        return Database::insert('content', $data);
    }

    /**
     * Load a content entity by ID.
     */
    public static function load(int $id): ?array
    {
        $result = Database::statement('SELECT * FROM content WHERE id = ?', [$id]);
        return $result[0] ?? null;
    }

    /**
     * Load by slug.
     */
    public static function loadBySlug(string $slug, string $type = null): ?array
    {
        $sql = 'SELECT * FROM content WHERE slug = ?';
        $params = [$slug];
        if ($type) {
            $sql .= ' AND type = ?';
            $params[] = $type;
        }
        $sql .= ' AND status = 1';

        $result = Database::statement($sql, $params);
        return $result[0] ?? null;
    }

    /**
     * Get content list with filtering.
     */
    public static function list(array $options = []): array
    {
        $type = $options['type'] ?? null;
        $status = $options['status'] ?? self::STATUS_PUBLISHED;
        $author = $options['author_id'] ?? null;
        $tag = $options['tag'] ?? null;
        $search = $options['search'] ?? null;
        $limit = $options['limit'] ?? 20;
        $offset = $options['offset'] ?? 0;

        $sql = 'SELECT c.* FROM content c';
        $params = [];

        $where = [];
        $where[] = 'c.status = ?';
        $params[] = $status;

        if ($type) {
            $where[] = 'c.type = ?';
            $params[] = $type;
        }
        if ($author) {
            $where[] = 'c.author_id = ?';
            $params[] = $author;
        }
        if ($search) {
            $where[] = '(c.title LIKE ? OR c.body LIKE ?)';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY c.created_at DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        return Database::statement($sql, $params);
    }

    /**
     * Update content.
     */
    public static function update(int $id, array $data): bool
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        return Database::update('content', $data, ['id' => $id]) > 0;
    }

    /**
     * Delete content.
     */
    public static function delete(int $id): bool
    {
        // Cascade delete comments, tags, etc.
        Database::delete('content_tags', ['content_id' => $id]);
        Database::delete('content_comments', ['content_id' => $id]);
        return Database::delete('content', ['id' => $id]) > 0;
    }

    /**
     * Validate content data.
     */
    public static function validate(array $data, string $type): Validation
    {
        return new Validation($data, [
            'title' => 'required|string|max:255',
            'body' => 'required|string',
            'slug' => 'required|string|unique:content,slug',
            'status' => 'integer|min:0|max:2',
        ]);
    }

    /**
     * Generate SEO-friendly slug.
     */
    public static function slugify(string $title): string
    {
        $slug = strtolower($title);
        $slug = preg_replace('/[^\w\s-]/', '', $slug);
        $slug = preg_replace('/[\s-]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug;
    }
}