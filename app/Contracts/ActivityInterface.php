<?php

namespace Canyon\Contracts;

/**
 * Activity Feed Interface
 *
 * Implement this interface to provide custom activity feed functionality.
 */
interface ActivityInterface
{
 public function post(int $userId, array $data): int;
 public function getFeed(array $options): array;
 public function like(int $postId, int $userId): bool;
 public function unlike(int $postId, int $userId): bool;
 public function comment(int $postId, int $userId, string $content, ?int $parentId = null): int;
 public function share(int $postId, int $userId, string $content): int;
 public function delete(int $postId, int $userId): bool;
 public function getComments(int $postId): array;
 public function getLikes(int $postId): array;
}