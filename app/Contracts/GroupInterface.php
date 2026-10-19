<?php

namespace Canyon\Contracts;

/**
 * Group System Interface
 *
 * Implement this interface to provide custom group functionality.
 */
interface GroupInterface
{
 public function create(array $data): int;
 public function join(int $groupId, int $userId): bool;
 public function leave(int $groupId, int $userId): bool;
 public function post(int $groupId, int $userId, string $content, string $type = 'text'): int;
 public function getMembers(int $groupId): array;
 public function getUserGroups(int $userId): array;
 public function isMember(int $groupId, int $userId): bool;
 public function getGroup(int $groupId): ?array;
 public function getFeed(int $groupId, int $limit = 20, int $offset = 0): array;
 public function deletePost(int $postId, int $userId): bool;
 public function isGroupAdmin(int $groupId, int $userId): bool;
}