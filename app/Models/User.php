<?php

namespace Canyon\Models;

use Canyon\Core\Database;
use Canyon\Core\Security;

/**
 * Canyon Framework - User Model
 *
 * Simple database-backed user with password hashing, session storage,
 * and role support.
 */
class User
{
  /** @var array<int, array{id:int, name:string, email:string, password:string, roles:array}> */
  protected static array $cache = [];

  /** @var string Table name */
  protected static string $table = 'users';

  /**
   * Find a user by ID.
   */
  public static function findById(int $id): ?array
  {
    $row = Database::query(
      'SELECT id, name, email, password, roles, created_at FROM `' . self::$table . '` WHERE id = ?',
      [$id]
    )->fetch();
    if ($row) {
      $row['roles'] = json_decode($row['roles'] ?? '[]', true) ?: ['user'];
    }
    return $row ?: null;
  }

  /**
   * Find a user by email.
   */
  public static function findByEmail(string $email): ?array
  {
    $row = Database::query(
      'SELECT id, name, email, password, roles, created_at FROM `' . self::$table . '` WHERE email = ?',
      [$email]
    )->fetch();
    if ($row) {
      $row['roles'] = json_decode($row['roles'] ?? '[]', true) ?: ['user'];
    }
    return $row ?: null;
  }

  /**
   * Create a new user with a hashed password.
   */
  public static function create(string $name, string $email, string $password, array $roles = ['user']): int
  {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      throw new \InvalidArgumentException('Invalid email address');
    }
    if (strlen($password) < 8) {
      throw new \InvalidArgumentException('Password must be at least 8 characters');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $rolesJson = json_encode(array_values(array_unique($roles)));

    return Database::insert(self::$table, [
      'name'     => $name,
      'email'    => $email,
      'password' => $hash,
      'roles'    => $rolesJson,
    ]);
  }

  /**
   * Verify a plain password against a stored hash.
   */
  public static function verifyPassword(string $password, string $hash): bool
  {
    return password_verify($password, $hash);
  }

  /**
   * Rehash the password if the algorithm has changed.
   */
  public static function needsRehash(string $hash): bool
  {
    return password_needs_rehash($hash, PASSWORD_DEFAULT);
  }

  /**
   * Update the user's password.
   */
  public static function updatePassword(int $userId, string $newPassword): bool
  {
    if (strlen($newPassword) < 8) {
      throw new \InvalidArgumentException('Password must be at least 8 characters');
    }
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    return Database::update(self::$table, ['password' => $hash], ['id' => $userId]) > 0;
  }

  /**
   * Get a safe public representation (no password hash).
   */
  public static function toPublic(array $user): array
  {
    return [
      'id'    => (int) $user['id'],
      'name'  => $user['name'],
      'email' => $user['email'],
      'roles' => $user['roles'] ?? ['user'],
    ];
  }
}
