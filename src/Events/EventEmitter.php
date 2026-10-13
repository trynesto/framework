<?php

declare(strict_types=1);

namespace Nesto\Events;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Synchronous event emitter, the Nesto twin of `EventEmitter2` from @nestjs/event-emitter.
 * Also a PSR-14 dispatcher: `dispatch($event)` emits under the event's class name.
 *
 *   $emitter->on('order.created', fn (Order $o) => ..., priority: 10);
 *   $emitter->once('order.*', fn (Order $o) => ...);            // wildcard: one segment
 *   $emitter->on('**', fn (...$payload) => ...);                 // every event
 *   $emitter->emit('order.created', $order, $extra);             // listeners get the payload as arguments
 *   $emitter->dispatch(new OrderCreated($order));                // listeners registered on OrderCreated::class
 *
 * Listeners run in priority order (higher first), then registration order. There is no async
 * mode: PHP has no event loop, so push slow work to a queue (`Nesto\Queue`) from inside a listener.
 */
final class EventEmitter implements EventDispatcherInterface
{
    /** @var array<string, list<array{listener: callable, priority: int, once: bool, id: int}>> pattern => listeners */
    private array $listeners = [];
    /** @var array<string, string> pattern => regex cache */
    private array $regexes = [];
    private int $sequence = 0;
    /** @var list<string> */
    private array $stack = [];

    public function __construct(
        private readonly bool $wildcard = true,
        private readonly string $delimiter = '.',
    ) {
    }

    // ---- registration ------------------------------------------------------------

    public function on(string $event, callable $listener, int $priority = 0): self
    {
        return $this->add($event, $listener, $priority, false);
    }

    public function once(string $event, callable $listener, int $priority = 0): self
    {
        return $this->add($event, $listener, $priority, true);
    }

    /** Alias of on() (PSR-ish naming). */
    public function addListener(string $event, callable $listener, int $priority = 0): self
    {
        return $this->on($event, $listener, $priority);
    }

    /** Remove one listener of an event pattern, or all of them when $listener is null. */
    public function off(string $event, ?callable $listener = null): self
    {
        if (! isset($this->listeners[$event])) {
            return $this;
        }
        if ($listener === null) {
            unset($this->listeners[$event]);

            return $this;
        }
        $this->listeners[$event] = array_values(array_filter(
            $this->listeners[$event],
            fn (array $l) => ! self::sameCallable($l['listener'], $listener),
        ));
        if ($this->listeners[$event] === []) {
            unset($this->listeners[$event]);
        }

        return $this;
    }

    public function removeAllListeners(?string $event = null): self
    {
        if ($event === null) {
            $this->listeners = [];
        } else {
            unset($this->listeners[$event]);
        }

        return $this;
    }

    /**
     * Listeners that would run for $event (exact + wildcard matches), in execution order.
     * @return list<callable>
     */
    public function listeners(string $event): array
    {
        return array_map(fn (array $l) => $l['listener'], $this->matching($event));
    }

    public function listenerCount(string $event): int
    {
        return count($this->matching($event));
    }

    public function hasListeners(string $event): bool
    {
        return $this->matching($event) !== [];
    }

    /** All registered event names / patterns. @return list<string> */
    public function eventNames(): array
    {
        return array_keys($this->listeners);
    }

    // ---- emitting -----------------------------------------------------------------------

    /** Call every matching listener synchronously with the payload as arguments. Returns true when at least one ran. */
    public function emit(string $event, mixed ...$payload): bool
    {
        $matches = $this->matching($event);
        if ($matches === []) {
            return false;
        }
        $this->stack[] = $event;
        try {
            foreach ($matches as $entry) {
                if ($entry['once']) {
                    $this->removeById($entry['id']);
                }
                ($entry['listener'])(...$payload);
                if (($payload[0] ?? null) instanceof StoppableEventInterface && $payload[0]->isPropagationStopped()) {
                    break;
                }
            }
        } finally {
            array_pop($this->stack);
        }

        return true;
    }

    /** PSR-14: emit the object under its class name and return it. */
    public function dispatch(object $event): object
    {
        $this->emit($event::class, $event);

        return $event;
    }

    /** Name of the event currently being emitted (null outside a listener). */
    public function currentEvent(): ?string
    {
        return $this->stack[array_key_last($this->stack) ?? -1] ?? null;
    }

    // ---- internals -----------------------------------------------------------------------

    private function add(string $event, callable $listener, int $priority, bool $once): self
    {
        $this->listeners[$event][] = [
            'listener' => $listener,
            'priority' => $priority,
            'once' => $once,
            'id' => ++$this->sequence,
        ];

        return $this;
    }

    /** @return list<array{listener: callable, priority: int, once: bool, id: int}> */
    private function matching(string $event): array
    {
        $out = [];
        foreach ($this->listeners as $pattern => $entries) {
            if ($this->matches((string) $pattern, $event)) {
                array_push($out, ...$entries);
            }
        }
        usort($out, fn (array $a, array $b) => [$b['priority'], $a['id']] <=> [$a['priority'], $b['id']]);

        return $out;
    }

    private function matches(string $pattern, string $event): bool
    {
        if ($pattern === $event) {
            return true;
        }
        if (! $this->wildcard || ! str_contains($pattern, '*')) {
            return false;
        }

        return (bool) preg_match($this->regexes[$pattern] ??= $this->compile($pattern), $event);
    }

    private function compile(string $pattern): string
    {
        $d = preg_quote($this->delimiter, '#');
        $parts = explode($this->delimiter, $pattern);
        $regex = [];
        foreach ($parts as $part) {
            $regex[] = match ($part) {
                '**' => '.+',
                '*' => '[^' . $d . ']+',
                default => str_replace('\*', '[^' . $d . ']*', preg_quote($part, '#')),
            };
        }

        return '#^' . implode($d, $regex) . '$#u';
    }

    private function removeById(int $id): void
    {
        foreach ($this->listeners as $pattern => $entries) {
            foreach ($entries as $i => $entry) {
                if ($entry['id'] === $id) {
                    unset($this->listeners[$pattern][$i]);
                    $this->listeners[$pattern] = array_values($this->listeners[$pattern]);
                    if ($this->listeners[$pattern] === []) {
                        unset($this->listeners[$pattern]);
                    }

                    return;
                }
            }
        }
    }

    private static function sameCallable(callable $a, callable $b): bool
    {
        if ($a === $b) {
            return true;
        }
        if (is_array($a) && is_array($b)) {
            return $a[0] === $b[0] && $a[1] === $b[1];
        }

        return false;
    }
}
