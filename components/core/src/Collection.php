<?php

namespace Canyon\Core;

/**
 * Canyon Framework - Collection
 *
 * A helper collection class for working with arrays of entities/models.
 * Laravel's Collection.
 */
class Collection implements \Countable, \IteratorAggregate
{
  /** @var array Items in the collection */
  protected array $items = [];

  /**
   * Create a new collection.
   *
   * @param mixed $items Initial items
   */
  public function __construct($items = [])
  {
    $this->items = $this->getArrayableItems($items);
  }

  /**
   * Get arrayable items.
   */
  protected function getArrayableItems($items): array
  {
    if (is_array($items)) {
      return $items;
    }
    if ($items instanceof \Traversable) {
      return iterator_to_array($items);
    }
    return (array) $items;
  }

  /**
   * Count the items.
   */
  public function count(): int
  {
    return count($this->items);
  }

  /**
   * Get an iterator for the items.
   */
  public function getIterator(): \Traversable
  {
    return new \ArrayIterator($this->items);
  }

  // -----------------------------------------------------------------------
  // Query & Filter
  // -----------------------------------------------------------------------

  /**
   * Run a filter over each item.
   */
  public function filter(callable $callback = null): static
  {
    if ($callback) {
      return new static(array_filter($this->items, $callback));
    }
    return new static(array_filter($this->items));
  }

  /**
   * Run a map over each item.
   */
  public function map(callable $callback): static
  {
    return new static(array_map($callback, $this->items));
  }

  /**
   * Reduce the collection to a single value.
   */
  public function reduce(callable $callback, mixed $initial = null): mixed
  {
    return array_reduce($this->items, $callback, $initial);
  }

  /**
   * Get items matching a key-value pair.
   */
  public function where(string $key, mixed $value, string $operator = '='): static
  {
    return $this->filter(fn($item) => $this->meetsCondition($item[$key] ?? null, $value, $operator));
  }

  /**
   * Check if an item meets a condition.
   */
  protected function meetsCondition(mixed $item, mixed $value, string $operator): bool
  {
    return match ($operator) {
      '=' => $item == $value,
      '==' => $item === $value,
      '!=' => $item != $value,
      '!==' => $item !== $value,
      '<' => $item < $value,
      '<=' => $item <= $value,
      '>' => $item > $value,
      '>=' => $item >= $value,
      default => $item == $value,
    };
  }

  // -----------------------------------------------------------------------
  // Retrieval
  // -----------------------------------------------------------------------

  /**
   * Get the first item from the collection.
   */
  public function first(?callable $callback = null, mixed $default = null): mixed
  {
    if ($callback === null) {
      return $this->items[0] ?? $default;
    }
    foreach ($this->items as $item) {
      if ($callback($item)) {
        return $item;
      }
    }
    return $default;
  }

  /**
   * Get the last item from the collection.
   */
  public function last(?callable $callback = null, mixed $default = null): mixed
  {
    if ($callback === null) {
      return $this->items[count($this->items) - 1] ?? $default;
    }
    return $this->first($callback, $default);
  }

  /**
   * Get a random item from the collection.
   */
  public function random(int $count = 1): mixed
  {
    $items = $this->items;
    shuffle($items);
    $random = array_slice($items, 0, $count);
    return $count === 1 ? $random[0] : new static($random);
  }

  // -----------------------------------------------------------------------
  // Chunk & Skip
  // -----------------------------------------------------------------------

  /**
   * Split the collection into chunks.
   */
  public function chunk(int $size): array
  {
    return array_chunk($this->items, $size, true);
  }

  /**
   * Skip a given number of items.
   */
  public function skip(int $count): static
  {
    return new static(array_slice($this->items, $count));
  }

  /**
   * Take a given number of items.
   */
  public function take(int $count): static
  {
    if ($count < 0) {
      return $this->skip(abs($count));
    }
    return new static(array_slice($this->items, 0, $count));
  }

  // -----------------------------------------------------------------------
  // Other
  // -----------------------------------------------------------------------

  /**
   * Convert the collection to an array.
   */
  public function toArray(): array
  {
    return array_map(fn($value) => $value instanceof static ? $value->toArray() : $value, $this->items);
  }

  /**
   * Convert the collection to JSON.
   */
  public function toJson(): string
  {
    return json_encode($this->toArray());
  }

  /**
   * Implode collection items.
   */
  public function implode(string $glue, ?string $property = null): string
  {
    if ($property === null) {
      return implode($glue, $this->items);
    }
    return implode($glue, array_map(fn($item) => $item[$property], $this->items));
  }

  /**
   * Get the items.
   */
  public function items(): array
  {
    return $this->items;
  }

  /**
   * Push an item onto the collection.
   */
  public function push(mixed $value): static
  {
    $new = new static($this->items);
    $new->items[] = $value;
    return $new;
  }

  /**
   * Prepend an item to the collection.
   */
  public function prepend(mixed $value): static
  {
    return new static(array_merge([$value], $this->items));
  }
}