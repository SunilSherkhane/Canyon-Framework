<?php

namespace Canyon\Core\Taxonomy;

use Canyon\Core\Database;

/**
 * Taxonomy Module - Content Classification
 *
 * Provides vocabulary and term management for content categorization.
 * Similar to Drupal's Taxonomy module.
 */
class TaxonomyModule
{
    /**
     * Create a new vocabulary (taxonomy term group).
     */
    public static function createVocabulary(array $data): int
    {
        return Database::insert('taxonomies', [
            'name' => $data['name'],
            'vid' => $data['vid'] ?? self::generateVid($data['name']),
            'description' => $data['description'] ?? '',
            'weight' => $data['weight'] ?? 0,
            'multiple' => $data['multiple'] ?? false, // Allow multiple terms
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Generate vocabulary ID from name.
     */
    protected static function generateVid(string $name): string
    {
        $vid = strtolower($name);
        $vid = preg_replace('/[^a-z0-9]+/', '_', $vid);
        return trim($vid, '_');
    }

    /**
     * Get vocabulary by ID.
     */
    public static function getVocabulary(string $vid): ?array
    {
        $result = Database::statement('SELECT * FROM taxonomies WHERE vid = ?', [$vid]);
        return $result[0] ?? null;
    }

    /**
     * Get all vocabularies.
     */
    public static function getVocabularies(): array
    {
        return Database::statement('SELECT * FROM taxonomies ORDER BY weight, name');
    }

    /**
     * Create a new taxonomy term.
     */
    public static function createTerm(string $vid, array $data): int
    {
        return Database::insert('taxonomy_terms', [
            'vid' => $vid,
            'name' => $data['name'],
            'slug' => $data['slug'] ?? self::slugify($data['name']),
            'description' => $data['description'] ?? '',
            'parent' => $data['parent'] ?? 0,
            'weight' => $data['weight'] ?? 0,
            'format' => $data['format'] ?? 'plain_text',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Get terms for a vocabulary.
     */
    public static function getTerms(string $vid, bool $includeParents = true): array
    {
        $sql = 'SELECT * FROM taxonomy_terms WHERE vid = ?';
        if (!$includeParents) {
            $sql .= ' AND parent = 0';
        }
        return Database::statement($sql . ' ORDER BY weight, name', [$vid]);
    }

    /**
     * Get a term by ID.
     */
    public static function getTerm(int $tid): ?array
    {
        $result = Database::statement('SELECT * FROM taxonomy_terms WHERE tid = ?', [$tid]);
        return $result[0] ?? null;
    }

    /**
     * Get term by slug.
     */
    public static function getTermBySlug(string $vid, string $slug): ?array
    {
        $result = Database::statement(
            'SELECT * FROM taxonomy_terms WHERE vid = ? AND slug = ? LIMIT 1',
            [$vid, $slug]
        );
        return $result[0] ?? null;
    }

    /**
     * Update a term.
     */
    public static function updateTerm(int $tid, array $data): bool
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        return Database::update('taxonomy_terms', $data, ['tid' => $tid]) > 0;
    }

    /**
     * Delete a term.
     */
    public static function deleteTerm(int $tid): bool
    {
        return Database::delete('taxonomy_terms', ['tid' => $tid]) > 0;
    }

    /**
     * Get children of a term.
     */
    public static function getChildren(int $tid): array
    {
        return Database::statement(
            'SELECT * FROM taxonomy_terms WHERE parent = ? ORDER BY weight, name',
            [$tid]
        );
    }

    /**
     * Get all descendants of a term (recursive).
     */
    public static function getDescendants(int $tid, string $vid = null): array
    {
        $terms = [];
        $children = self::getChildren($tid);

        foreach ($children as $child) {
            $terms[] = $child;
            $descendants = self::getDescendants($child['tid'], $vid);
            $terms = array_merge($terms, $descendants);
        }

        return $terms;
    }

    /**
     * Get content tagged with a term.
     */
    public static function getContentByTerm(int $tid, int $limit = 20): array
    {
        return Database::statement(
            'SELECT c.* FROM content c JOIN content_terms ct ON c.id = ct.content_id WHERE ct.tid = ? ORDER BY c.created_at DESC LIMIT ?',
            [$tid, $limit]
        );
    }

    /**
     * Get tree structure for a vocabulary.
     */
    public static function getTree(string $vid): array
    {
        $terms = self::getTerms($vid, false); // Only top-level
        $tree = [];

        foreach ($terms as $term) {
            $term['children'] = self::getDescendants($term['tid'], $vid);
            $tree[] = $term;
        }

        return $tree;
    }

    /**
     * Assign term to content.
     */
    public static function assignToContent(int $contentId, int $tid): bool
    {
        $existing = Database::statement(
            'SELECT id FROM content_terms WHERE content_id = ? AND tid = ?',
            [$contentId, $tid]
        );

        if (empty($existing)) {
            return Database::insert('content_terms', [
                'content_id' => $contentId,
                'tid' => $tid,
            ]) > 0;
        }
        return false;
    }

    /**
     * Get terms for content.
     */
    public static function getContentTerms(int $contentId): array
    {
        return Database::statement(
            'SELECT t.* FROM taxonomy_terms t JOIN content_terms ct ON t.tid = ct.tid WHERE ct.content_id = ?',
            [$contentId]
        );
    }

    /**
     * Remove term from content.
     */
    public static function removeFromContent(int $contentId, int $tid): bool
    {
        return Database::delete('content_terms', [
            'content_id' => $contentId,
            'tid' => $tid,
        ]) > 0;
    }

    /**
     * Slugify term name.
     */
    protected static function slugify(string $name): string
    {
        $slug = strtolower($name);
        $slug = preg_replace('/[^\w\s-]/', '', $slug);
        $slug = preg_replace('/[\s-]+/', '-', $slug);
        return trim($slug, '-');
    }

    /**
     * Generate URL for term.
     */
    public static function url(int $tid): string
    {
        $term = self::getTerm($tid);
        if (!$term) return '/taxonomy';

        return "/taxonomy/{$term['vid']}/{$term['slug']}";
    }
}