<?php

namespace Canyon\Contracts;

/**
 * Messaging Interface
 *
 * Implement this interface to provide custom messaging functionality.
 */
interface MessagingInterface
{
 public function sendFriendRequest(int $fromUserId, int $toUserId): bool;
 public function acceptFriendRequest(int $requestId): bool;
 public function rejectFriendRequest(int $requestId): bool;
 public function blockUser(int $userId, int $blockedUserId): bool;
 public function unblockUser(int $userId, int $blockedUserId): bool;
 public function isFriend(int $userId1, int $userId2): bool;
 public function getFriends(int $userId): array;
 public function getBlockedUsers(int $userId): array;
 public function sendMessage(int $fromUserId, int $toUserId, string $content): int;
 public function getConversations(int $userId): array;
 public function getMessages(int $conversationId, array $options = []): array;
 public function markAsRead(int $conversationId, int $userId): bool;
 public function getUnreadCount(int $userId): int;
}