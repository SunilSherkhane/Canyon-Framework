<?php

namespace Canyon\Core\ContentModeration;

use Canyon\Core\Database;

/**
 * Content Moderation Module - Editorial Workflows
 *
 * Provides content states, editorial workflows, and moderation actions.
 * Similar to Drupal's Content Moderation module.
 */
class ContentModerationModule
{
    /**
     * Workflow states.
     */
    public const STATE_DRAFT = 'draft';
    public const STATE_REVIEW = 'review';
    public const STATE_PUBLISHED = 'published';
    public const STATE_ARCHIVED = 'archived';

    /**
     * Create a new workflow.
     */
    public static function createWorkflow(string $name, array $states = [], array $transitions = []): int
    {
        return Database::insert('content_workflows', [
            'name' => $name,
            'states' => json_encode($states ?: [
                ['id' => self::STATE_DRAFT, 'label' => 'Draft', 'weight' => 0],
                ['id' => self::STATE_REVIEW, 'label' => 'Review', 'weight' => 10],
                ['id' => self::STATE_PUBLISHED, 'label' => 'Published', 'weight' => 20],
            ]),
            'transitions' => json_encode($transitions ?: [
                ['id' => 'to_review', 'label' => 'Send to Review', 'from' => self::STATE_DRAFT, 'to' => self::STATE_REVIEW],
                ['id' => 'publish', 'label' => 'Publish', 'from' => self::STATE_REVIEW, 'to' => self::STATE_PUBLISHED],
                ['id' => 'delete', 'label' => 'Archive', 'from' => self::STATE_PUBLISHED, 'to' => self::STATE_ARCHIVED],
                ['id' => 'revert', 'label' => 'Re-reivew', 'from' => self::STATE_PUBLISHED, 'to' => self::STATE_REVIEW],
            ]),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Assign workflow to content type.
     */
    public static function assignWorkflow(string $contentType, int $workflowId): bool
    {
        return Database::insert('content_type_workflows', [
            'content_type' => $contentType,
            'workflow_id' => $workflowId,
        ]);
    }

    /**
     * Get workflow for content type.
     */
    public static function getWorkflowForType(string $contentType): ?array
    {
        $result = Database::statement(
            'SELECT * FROM content_workflows w
             JOIN content_type_workflows ctw ON w.id = ctw.workflow_id
             WHERE ctw.content_type = ?',
            [$contentType]
        );
        return $result[0] ?? null;
    }

    /**
     * Get current state of content.
     */
    public static function getState(int $contentId): ?string
    {
        $result = Database::statement(
            'SELECT state FROM content_moderation WHERE content_id = ? LIMIT 1',
            [$contentId]
        );
        return $result[0]['state'] ?? null;
    }

    /**
     * Set state for content.
     */
    public static function setState(int $contentId, string $state, int $userId): bool
    {
        $existing = Database::statement(
            'SELECT id FROM content_moderation WHERE content_id = ?',
            [$contentId]
        );

        $data = [
            'content_id' => $contentId,
            'state' => $state,
            'current_by' => $userId,
            'changed_at' => date('Y-m-d H:i:s'),
        ];

        if (!empty($existing)) {
            return Database::update('content_moderation', $data, ['id' => $existing[0]['id']]) > 0;
        }

        $data['created_at'] = date('Y-m-d H:i:s');
        return Database::insert('content_moderation', $data) > 0;
    }

    /**
     * Get state transitions for a content item.
     */
    public static function getTransitions(int $contentId): array
    {
        $state = self::getState($contentId);
        if (!$state) return [];

        $content = Database::statement('SELECT type FROM content WHERE id = ?', [$contentId]);
        if (empty($content)) return [];

        $contentType = $content[0]['type'];
        $workflow = self::getWorkflowForType($contentType);
        if (!$workflow) return [];

        $transitions = json_decode($workflow['transitions'], true);
        $validTransitions = [];

        foreach ($transitions as $transition) {
            if ($transition['from'] === $state) {
                $validTransitions[] = $transition;
            }
        }

        return $validTransitions;
    }

    /**
     * Execute a state transition.
     */
    public static function transition(int $contentId, string $transitionId, int $userId): bool
    {
        $transitions = self::getTransitions($contentId);
        foreach ($transitions as $t) {
            if ($t['id'] === $transitionId) {
                if (!self::setState($contentId, $t['to'], $userId)) {
                    return false;
                }

                // Log transition
                Database::insert('content_moderation_log', [
                    'content_id' => $contentId,
                    'transition' => $transitionId,
                    'from_state' => self::getState($contentId),
                    'to_state' => $t['to'],
                    'by_user' => $userId,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);

                return true;
            }
        }
        return false;
    }

    /**
     * Get moderation history for content.
     */
    public static function getHistory(int $contentId): array
    {
        return Database::statement(
            'SELECT * FROM content_moderation_log WHERE content_id = ? ORDER BY created_at DESC',
            [$contentId]
        );
    }

    /**
     * Get content in a specific state.
     */
    public static function getByState(string $state, array $options = []): array
    {
        $sql = 'SELECT c.*, cm.state, cm.current_by FROM content c JOIN content_moderation cm ON c.id = cm.content_id';
        $params = [];

        $type = $options['type'] ?? null;
        if ($type) {
            $sql .= ' WHERE c.type = ? AND cm.state = ?';
            $params = [$type, $state];
        } else {
            $sql .= ' WHERE cm.state = ?';
            $params = [$state];
        }

        $sql .= ' ORDER BY cm.changed_at DESC LIMIT ?';
        $params[] = $options['limit'] ?? 50;

        return Database::statement($sql, $params);
    }
}