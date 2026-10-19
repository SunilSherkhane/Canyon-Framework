<?php

namespace Canyon\Contracts;

/**
 * Social Profile Interface
 *
 * Implement this interface to provide custom profile functionality
 * without modifying the core framework.
 */
interface ProfileInterface
{
 public function create(int $userId, array $data): bool;
 public function get(int $userId): ?array;
 public function update(int $userId, array $data): bool;
 public function delete(int $userId): bool;
 public function uploadAvatar(int $userId, array $file): ?string;
 public function uploadCover(int $userId, array $file): ?string;
 public function isPublic(int $userId): bool;
}