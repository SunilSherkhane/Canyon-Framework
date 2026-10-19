<?php

namespace Canyon\Core;

/**
 * Canyon Framework - Event Dispatcher
 *
 * Simple event system for dispatching and listening to events.
 * Laravel's Events and Symfony's EventDispatcher.
 */
class EventDispatcher
{
  /** @var array<string, array<int, callable>> Event listeners */
  protected static array $listeners = [];

  /** @var array<string, array<int, callable>> Event subscribers */
  protected static array $subscribers = [];

  /**
   * Register an event listener.
   *
   * @param string $event Event name (e.g., 'user.created')
   * @param callable $listener Listener function
   * @param int $priority Lower number = higher priority (executes first)
   */
  public static function listen(string $event, callable $listener, int $priority = 100): void
  {
    self::$listeners[$event][$priority][] = $listener;
  }

  /**
   * Dispatch an event to all registered listeners.
   *
   * @param string $event Event name
   * @param mixed $payload Event data
   * @return mixed Modified payload (if listeners modify it)
   */
  public static function dispatch(string $event, mixed $payload = null): mixed
  {
    $listeners = self::$listeners[$event] ?? [];

    // Sort by priority (ascending)
    ksort($listeners);

    foreach ($listeners as $priority => $callbacks) {
      foreach ($callbacks as $callback) {
        $result = $callback($payload);
        if ($result !== null) {
          $payload = $result;
        }
      }
    }

    return $payload;
  }

  /**
   * Register an event subscriber.
   *
   * @param callable $subscriber Function that receives the dispatcher
   */
  public static function subscribe(callable $subscriber): void
  {
    $subscriber(new class {
      public function listen(string $event, callable $listener, int $priority = 100): void
      {
        EventDispatcher::listen($event, $listener, $priority);
      }
    });
  }

  /**
   * Remove all listeners for an event.
   */
  public static function forget(string $event): void
  {
    unset(self::$listeners[$event]);
  }

  /**
   * Get all registered events and their listeners.
   */
  public static function getEvents(): array
  {
    return array_keys(self::$listeners);
  }

  /**
   * Check if an event has listeners.
   */
  public static function hasListeners(string $event): bool
  {
    return !empty(self::$listeners[$event]);
  }
}