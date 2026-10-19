<?php

namespace Canyon\Core;

/**
 * Canyon Framework - Service Container
 *
 * Simple dependency injection container with binding, singleton, and contextual resolution.
 * Laravel's service container.
 */
class Container
{
  /** @var array<string, callable|object> Registered bindings */
  protected static array $bindings = [];

  /** @var array<string, object> Singleton instances */
  protected static array $singletons = [];

  /** @var array<string, array<string, callable>> Contextual bindings */
  protected static array $contextual = [];

  /**
   * Bind a type to a concrete implementation.
   *
   * @param string $abstract Abstract type name
   * @param callable|string|object|null $concrete Concrete implementation
   */
  public static function bind(string $abstract, callable|string|object|null $concrete = null, bool $shared = false): void
  {
    if ($concrete === null) {
      $concrete = $abstract;
    }

    if (is_string($concrete) && class_exists($concrete)) {
      $concrete = fn() => new $concrete();
    }

    if ($shared) {
      self::singleton($abstract, $concrete);
      return;
    }

    self::$bindings[$abstract] = $concrete;
  }

  /**
   * Register a singleton binding.
   */
  public static function singleton(string $abstract, callable|string|object|null $concrete = null): void
  {
    if ($concrete === null) {
      $concrete = $abstract;
    }

    if (is_string($concrete) && class_exists($concrete)) {
      $concrete = fn() => new $concrete();
    }

    self::$singletons[$abstract] = fn() => is_callable($concrete) ? $concrete() : $concrete;
  }

  /**
   * Bind a contextual implementation for a specific class.
   *
   * @param string $concrete The class requesting the dependency
   * @param string $abstract The abstract being resolved
   * @param callable|string $implementation The concrete implementation
   */
  public static function when(string $concrete, string $abstract, callable|string $implementation): void
  {
    if (!isset(self::$contextual[$concrete])) {
      self::$contextual[$concrete] = [];
    }
    self::$contextual[$concrete][$abstract] = $implementation;
  }

  /**
   * Resolve a type from the container.
   */
  public static function resolve(string $abstract): mixed
  {
    // Return singleton if registered
    if (isset(self::$singletons[$abstract])) {
      $resolver = self::$singletons[$abstract];
      if (!isset(self::$singletons[$abstract . '_instance'])) {
        self::$singletons[$abstract . '_instance'] = $resolver();
      }
      return self::$singletons[$abstract . '_instance'];
    }

    // Check contextual binding
    foreach (self::$contextual as $concrete => $bindings) {
      if (is_a($concrete, $abstract, true) || $concrete === $abstract) {
        if (isset($bindings[$abstract])) {
          $impl = $bindings[$abstract];
          if (is_string($impl) && class_exists($impl)) {
            return new $impl();
          }
          if (is_callable($impl)) {
            return $impl();
          }
          return $impl;
        }
      }
    }

    // Resolve from bindings
    if (isset(self::$bindings[$abstract])) {
      $concrete = self::$bindings[$abstract];
      if (is_callable($concrete)) {
        return $concrete();
      }
      if (is_string($concrete)) {
        return new $concrete();
      }
      return $concrete;
    }

    // Auto-instantiate
    return new $abstract();
  }

  /**
   * Register a singleton instance directly.
   */
  public static function instance(string $abstract, object $instance): void
  {
    self::$singletons[$abstract . '_instance'] = $instance;
  }

  /**
   * Clear all bindings.
   */
  public static function clear(): void
  {
    self::$bindings = [];
    self::$singletons = [];
    self::$contextual = [];
  }
}