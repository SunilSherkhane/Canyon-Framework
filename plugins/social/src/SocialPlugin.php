<?php

namespace SocialPlugin;

use Canyon\Core\Waf;
use Canyon\Core\UserProfile;
use Canyon\Core\MessageSystem;
use Canyon\Core\ActivityFeed;
use Canyon\Core\GroupSystem;

/**
 * Social Plugin - Main Service Provider
 *
 * This class bootstraps the social networking features.
 * It acts as a facade for the underlying core classes.
 */
class SocialPlugin
{
 /**
  * Boot the plugin - register routes, events, and services.
  */
 public static function boot(): void
 {
  // Register routes
  self::registerRoutes();

  // Register event listeners
  self::registerEvents();

  // Load WAF rules
  self::registerWafRules();
 }

 /**
  * Register plugin routes.
  */
 protected static function registerRoutes(): void
 {
  \Canyon\Core\Router::group('social', function () {
   \Canyon\Core\Router::get('/profile/{username}', [SocialController::class, 'profile'], 'social.profile');
   \Canyon\Core\Router::get('/messages', [SocialController::class, 'messages'], 'social.messages.index');
   \Canyon\Core\Router::post('/messages', [SocialController::class, 'sendMessage'], 'social.messages.send');
   \Canyon\Core\Router::get('/groups', [SocialController::class, 'groups'], 'social.groups.index');
   \Canyon\Core\Router::get('/groups/create', [SocialController::class, 'createGroup'], 'social.groups.create');
  });
 }

 /**
  * Register event listeners.
  */
 protected static function registerEvents(): void
 {
  // Auto-follow on connection
  \Canyon\Core\EventDispatcher::listen('user.registered', fn($data) => self::onUserRegistered($data));

  // Notification on new message
  \Canyon\Core\EventDispatcher::listen('message.sent', fn($data) => self::onMessageSent($data));

  // Feed update on activity
  \Canyon\Core\EventDispatcher::listen('activity.created', fn($data) => self::updateFeed($data));
 }

 /**
  * Register WAF rules for social features.
  */
 public static function registerWafRules(): void
 {
  Waf::addPattern('/<script[^>]*>.*<\/script>/i', 'social.xss_comment');
  Waf::addPattern('/(?:union|select|insert|update|delete).*from/i', 'social.xss_comment');
 }

 /**
  * Handle user registration.
  */
 public static function onUserRegistered(array $data): void
 {
  if (!isset($data['user_id'])) return;

  UserProfile::create($data['user_id'], [
   'bio' => '',
   'join_date' => date('Y-m-d'),
  ]);
 }

 /**
  * Handle new message notification.
  */
 public static function onMessageSent(array $data): void
 {
  \Canyon\Core\Notification::send($data['to_user_id'], 'message.received', [
   'from' => $data['from_name'],
   'content' => str_limit($data['content'], 100),
  ]);
 }

 /**
  * Update activity feed.
  */
 protected static function updateFeed(array $data): void
 {
  // Broadcast to followers
  $feed = new ActivityFeed();
  $feed->addActivityForFollowers($data);
 }

 /**
  * Check if user can access a resource.
  */
 public static function canAccess(int $userId, string $resource, int $resourceId): bool
 {
  return match ($resource) {
   'profile' => UserProfile::isPublic($resourceId) || UserProfile::isFriend($userId, $resourceId),
   'message' => MessageSystem::canReadMessage($userId, $resourceId),
   'group' => GroupSystem::canJoin($userId, $resourceId),
   default => false,
  };
 }
}