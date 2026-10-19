<?php

namespace Canyon\Core\Search;

use Canyon\Core\Database;
use Canyon\Core\Cache;

/**
 * Search Module - Full-Text Search
 *
 * Similar to Drupal's Search module.
 * Provides full-text search with filters, facets, and indexing.
 */
class SearchModule
{
    /**
     * Index a content item for search.
     */
    public static function index(string $entityType, int $entityId, array $data): bool
    {
        $title = $data['title'] ?? '';
        $body = $data['body'] ?? '';
        $tags = $data['tags'] ?? [];

        // Remove old index
        Database::delete('search_index', [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ]);

        // Insert new index
        return Database::insert('search_index', [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'title' => $title,
            'body' => $body,
            'tags' => json_encode($tags),
            'status' => $data['status'] ?? 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ]) > 0;
    }

    /**
     * Remove an item from the search index.
     */
    public static function remove(string $entityType, int $entityId): bool
    {
        return Database::delete('search_index', [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ]) > 0;
    }

    /**
     * Search the index.
     */
    public static function search(string $query, array $options = []): array
    {
        $type = $options['type'] ?? null;
        $limit = $options['limit'] ?? 20;
        $offset = $options['offset'] ?? 0;

        $sql = 'SELECT * FROM search_index WHERE status = 1';
        $params = [];

        if ($query) {
            $sql .= ' AND (title LIKE ? OR body LIKE ?)';
            $params[] = "%{$query}%";
            $params[] = "%{$query}%";
        }

        if ($type) {
            $sql .= ' AND entity_type = ?';
            $params[] = $type;
        }

        $sql .= ' ORDER BY updated_at DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        return Database::statement($sql, $params);
    }

    /**
     * Get search results with highlighted snippets.
     */
    public static function searchWithSnippets(string $query, array $options = []): array
    {
        $results = self::search($query, $options);
        $snippets = [];

        foreach ($results as $result) {
            $snippet = self::createSnippet($result['body'], $query);
            $snippets[] = array_merge($result, ['snippet' => $snippet]);
        }

        return $snippets;
    }

    /**
     * Create a search snippet with highlighted terms.
     */
    protected static function createSnippet(string $text, string $query, int $length = 200): string
    {
        $text = strip_tags($text);
        $pos = stripos($text, $query);

        if ($pos === false) {
            return substr($text, 0, $length) . (strlen($text) > $length ? '...' : '');
        }

        $start = max(0, $pos - 50);
        $snippet = substr($text, $start, $length);

        if ($start > 0) $snippet = '...' . $snippet;
        if ($start + $length < strlen($text)) $snippet .= '...';

        return $snippet;
    }

    /**
     * Get search facets (counts by type).
     */
    public static function getFacets(string $query): array
    {
        $results = Database::statement(
            'SELECT entity_type, COUNT(*) as count FROM search_index WHERE status = 1 AND (title LIKE ? OR body LIKE ?) GROUP BY entity_type',
            ["%{$query}%", "%{$query}%"]
        );

        $facets = [];
        foreach ($results as $row) {
            $facets[$row['entity_type']] = (int)$row['count'];
        }
        return $facets;
    }

    /**
     * Rebuild the entire search index.
     */
    public static function rebuildIndex(): int
    {
        Database::statement('DELETE FROM search_index');

        $content = Database::statement('SELECT * FROM content WHERE status = 1');
        $count = 0;

        foreach ($content as $item) {
            self::index('content', $item['id'], [
                'title' => $item['title'],
                'body' => $item['body'],
                'status' => $item['status'],
            ]);
            $count++;
        }

        return $count;
    }
}