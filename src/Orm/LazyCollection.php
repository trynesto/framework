<?php

declare(strict_types=1);

namespace Nesto\Orm;

use ArrayAccess;
use ArrayIterator;
use Closure;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * Value of a lazy to-many relation (`#[OneToMany(..., lazy: true)] public iterable $posts;`) until it is
 * used: iterating, counting, indexing or toArray() runs ONE query and keeps the result (TypeORM's
 * `await user.posts`). save() ignores the relation while it is not loaded, and json_encode() writes null
 * instead of loading it (TypeORM leaves unloaded lazy relations out of JSON; it also stops lazy cycles).
 *
 *   foreach ($user->posts as $post) { ... }     // loads
 *   count($user->posts); $user->posts[0];        // loads (once)
 *   $user->posts->isLoaded();
 *
 * The loader only references the DataSource (which reconnects) and the owning entity weakly, so a
 * collection never keeps a request's entities or a closed connection alive (worker mode).
 *
 * @implements IteratorAggregate<int, object>
 * @implements ArrayAccess<int, object>
 */
final class LazyCollection implements IteratorAggregate, Countable, ArrayAccess, JsonSerializable
{
    /** @var list<object>|null */
    private ?array $items = null;

    /** @param Closure(): list<object> $loader */
    public function __construct(private ?Closure $loader) {}

    /** @param list<object> $items */
    public static function loaded(array $items): self
    {
        $collection = new self(null);
        $collection->items = array_values($items);

        return $collection;
    }

    public function isLoaded(): bool
    {
        return $this->items !== null;
    }

    /** @return list<object> */
    public function toArray(): array
    {
        if ($this->items === null) {
            $loader = $this->loader ?? throw new OrmException('This lazy relation can no longer be loaded (its entity is gone).');
            $this->items = array_values($loader());
            $this->loader = null;
        }

        return $this->items;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->toArray());
    }

    public function count(): int
    {
        return count($this->toArray());
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->toArray()[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->toArray()[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $items = $this->toArray();
        if ($offset === null) {
            $items[] = $value;
        } else {
            $items[$offset] = $value;
        }
        $this->items = array_values($items);
    }

    public function offsetUnset(mixed $offset): void
    {
        $items = $this->toArray();
        unset($items[$offset]);
        $this->items = array_values($items);
    }

    /** @return list<object>|null the items once loaded; null while not loaded (never triggers a query) */
    public function jsonSerialize(): ?array
    {
        return $this->items;
    }
}
