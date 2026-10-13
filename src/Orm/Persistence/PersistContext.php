<?php

declare(strict_types=1);

namespace Nesto\Orm\Persistence;

use Closure;
use SplObjectStorage;

/**
 * State of ONE save() / remove() / softRemove() / recover() call: the entities already visited (cycle guard
 * for cascades in both directions), work deferred until every entity of the graph has an id, and the
 * join-table rows already written (both sides of a ManyToMany may be saved in the same call).
 * Created per call and dropped afterwards, so nothing outlives the operation (worker-safe).
 */
final class PersistContext
{
    /** @var SplObjectStorage<object, true> */
    private SplObjectStorage $visited;
    /** @var list<Closure(): void> */
    private array $deferred = [];
    /** @var array<string, true> */
    private array $junctionRows = [];
    /** @var list<Closure(): void> after-events (afterInsert …), fired once every write of the call is done */
    private array $afterEvents = [];

    /**
     * @param bool $listeners fire entity listeners / subscribers (save()'s `listeners` option)
     * @param bool $reload    read back database-generated values after writes (save()'s `reload` option)
     */
    public function __construct(public readonly bool $listeners = true, public readonly bool $reload = true)
    {
        $this->visited = new SplObjectStorage;
    }

    /** @param Closure(): void $event */
    public function afterEvent(Closure $event): void
    {
        $this->afterEvents[] = $event;
    }

    /** Fires (and clears) the queued after-events in write order, like TypeORM after executing all operations. */
    public function runAfterEvents(): void
    {
        while ($this->afterEvents !== []) {
            $event = array_shift($this->afterEvents);
            $event();
        }
    }

    public function visited(object $entity): bool
    {
        return $this->visited->offsetExists($entity);
    }

    public function visit(object $entity): void
    {
        $this->visited->offsetSet($entity, true);
    }

    /** @param Closure(): void $work */
    public function defer(Closure $work): void
    {
        $this->deferred[] = $work;
    }

    /** Runs (and clears) the deferred work, including work deferred while running it. */
    public function runDeferred(): void
    {
        while ($this->deferred !== []) {
            $work = array_shift($this->deferred);
            $work();
        }
    }

    /** False when this join-table row was already inserted/deleted in this call (from the other side). */
    public function claimJunctionRow(string $key): bool
    {
        if (isset($this->junctionRows[$key])) {
            return false;
        }
        $this->junctionRows[$key] = true;

        return true;
    }
}
