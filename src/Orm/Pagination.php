<?php

declare(strict_types=1);

namespace Nesto\Orm;

/**
 * Result of Repository::paginate(). Serializes to `{ data: [...], meta: { total, page, perPage, lastPage } }`.
 *
 *   #[Get] public function index(#[Query('page')] int $page = 1): Pagination
 *   {
 *       return $this->cats->paginate($page, 20, ['order' => ['name' => 'ASC']]);
 *   }
 */
final class Pagination implements \JsonSerializable, \Countable, \IteratorAggregate
{
    public readonly int $lastPage;

    /** @param list<mixed> $data entities, or whatever map() turned them into */
    public function __construct(
        public readonly array $data,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
    ) {
        $this->lastPage = $perPage > 0 ? max(1, (int) ceil($total / $perPage)) : 1;
    }

    /**
     * The same page with every item passed through $callback (Laravel's `through()`), e.g. to present entities:
     *
     *   return $this->posts->paginate($page)->map($this->present(...));
     *
     * @param callable(mixed, int): mixed $callback receives the item and its index
     */
    public function map(callable $callback): self
    {
        return new self(array_map($callback, $this->data, array_keys($this->data)), $this->total, $this->page, $this->perPage);
    }

    public function hasMorePages(): bool
    {
        return $this->page < $this->lastPage;
    }

    /** @return array{data: list<mixed>, meta: array{total: int, page: int, perPage: int, lastPage: int}} */
    public function toArray(): array
    {
        $data = class_exists(\Nesto\Core\Http\RouteHandler::class)
            ? array_map(\Nesto\Core\Http\RouteHandler::toPlain(...), $this->data)
            : array_map(fn ($e) => is_object($e) ? get_object_vars($e) : $e, $this->data);

        return [
            'data' => $data,
            'meta' => $this->meta(),
        ];
    }

    /** @return array{total: int, page: int, perPage: int, lastPage: int} */
    public function meta(): array
    {
        return ['total' => $this->total, 'page' => $this->page, 'perPage' => $this->perPage, 'lastPage' => $this->lastPage];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function count(): int
    {
        return count($this->data);
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->data);
    }
}
