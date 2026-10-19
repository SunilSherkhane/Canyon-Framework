<?php

namespace Canyon\Core\Comment;

use Canyon\Core\Database;
use Canyon\Core\Notification;

/**
 * Comment Module - Threaded Comments
 *
 * Similar to Drupal's Comment module.
 * Provides threaded comments on any entity.
 */
class CommentModule
{
    /**
     * Comment status values.
     */
    public const STATUS_PUBLISHED = 1;
    public const STATUS_PENDING = 0;
    public const STATUS_SPAM = -1;

    /**
     * Add a comment to an entity.
     */
    public static function add(array $data): int
    {
        $commentId = Database::insert('comments', [
            'entity_type' => $data['entity_type'],
            'entity_id' => $data['entity_id'],
            'subject' => $data['subject'] ?? '',
            'body' => $data['body'],
            'author_id' => $data['author_id'] ?? 0,
            'author_name' => $data['author_name'] ?? 'Anonymous',
            'author_email' => $data['author_email'] ?? '',
            'parent_id' => $data['parent_id'] ?? 0,
            'status' => $data['status'] ?? self::STATUS_PUBLISHED,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // Notify entity author
        if ($commentId > 0) {
            $entity = Database::statement(
                "SELECT author_id FROM {$data['entity_type']} WHERE id = ?",
                [$data['entity_id']]
            );
            if (!empty($entity) && $entity[0]['author_id'] != ($data['author_id'] ?? 0)) {
                Notification::send(
                    $entity[0]['author_id'],
                    'comment.added',
                    [
                        'comment_id' => $commentId,
                        'entity_type' => $data['entity_type'],
                        'entity_id' => $data['entity_id'],
                    ]
                );
            }
        }

        return $commentId;
    }

    /**
     * Get comments for an entity.
     */
    public static function getComments(string $entityType, int $entityId, int $status = self::STATUS_PUBLISHED): array
    {
        return Database::statement(
            'SELECT * FROM comments WHERE entity_type = ? AND entity_id = ? AND status = ? ORDER BY created_at ASC',
            [$entityType, $entityId, $status]
        );
    }

    /**
     * Get threaded comments.
     */
    public static function getThreaded(string $entityType, int $entityId): array
    {
        $comments = self::getComments($entityType, $entityId);
        return self::buildThread($comments);
    }

    /**
     * Build threaded comment tree.
     */
    protected static function buildThread(array $comments, int $parentId = 0): array
    {
        $thread = [];
        foreach ($comments as $comment) {
            if ($comment['parent_id'] == $parentId) {
                $comment['children'] = self::buildThread($comments, $comment['id']);
                $thread[] = $comment;
            }
        }
        return $thread;
    }

    /**
     * Get a single comment.
     */
    public static function get(int $id): ?array
    {
        $result = Database::statement('SELECT * FROM comments WHERE id = ?', [$id]);
        return $result[0] ?? null;
    }

    /**
     * Update a comment.
     */
    public static function update(int $id, array $data): bool
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        return Database::update('comments', $data, ['id' => $id]) > 0;
    }

    /**
     * Delete a comment (and its children).
     */
    public static function delete(int $id): bool
    {
        // Delete children first
        $children = Database::statement('SELECT id FROM comments WHERE parent_id = ?', [$id]);
        foreach ($children as $child) {
            self::delete($child['id']);
        }
        return Database::delete('comments', ['id' => $id]) > 0;
    }

    /**
     * Count comments for an entity.
     */
    public static function count(string $entityType, int $entityId, int $status = self::STATUS_PUBLISHED): int
    {
        $result = Database::statement(
            'SELECT COUNT(*) as cnt FROM comments WHERE entity_type = ? AND entity_id = ? AND status = ?',
            [$entityType, $entityId, $status]
        );
        return (int)($result[0]['cnt'] ?? 0);
    }

    /**
     * Render comments as HTML.
     */
    public static function render(array $comments, int $depth = 0): string
    {
        if (empty($comments)) return '';

        $html = '<ul class="comments-list depth-' . $depth . '">';
        foreach ($comments as $comment) {
            $html .= '<li class="comment" id="comment-' . $comment['id'] . '">';
            $html .= '<div class="comment-header">';
            $html .= '<strong>' . e($comment['author_name']) . '</strong>';
            $html .= ' <span class="comment-date">' . date('M j, Y g:i a', strtotime($comment['created_at'])) . '</span>';
            $html .= '</div>';
            $html .= '<div class="comment-body">' . nl2br(e($comment['body'])) . '</div>';
            if (!empty($comment['children'])) {
                $html .= self::render($comment['children'], $depth + 1);
            }
            $html .= '</li>';
        }
        $html .= '</ul>';
        return $html;
    }
}