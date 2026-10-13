<?php

declare(strict_types=1);

namespace Nesto\Tests\Events;

use Nesto\Events\EventEmitter;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\StoppableEventInterface;

final class StoppableEvent implements StoppableEventInterface
{
    public bool $stopped = false;

    public function isPropagationStopped(): bool
    {
        return $this->stopped;
    }
}

final class EventEmitterTest extends TestCase
{
    public function testEmitPassesPayloadInPriorityOrder(): void
    {
        $emitter = new EventEmitter;
        $calls = [];
        $emitter->on('order.created', function (int $id, string $extra) use (&$calls) { $calls[] = "low:$id:$extra"; });
        $emitter->on('order.created', function (int $id) use (&$calls) { $calls[] = "high:$id"; }, priority: 10);

        self::assertTrue($emitter->emit('order.created', 7, 'x'));
        self::assertSame(['high:7', 'low:7:x'], $calls);
        self::assertFalse($emitter->emit('nobody.listens'));
    }

    public function testWildcards(): void
    {
        $emitter = new EventEmitter(wildcard: true, delimiter: '.');
        $hits = [];
        $emitter->on('order.*', function () use (&$hits) { $hits[] = 'one'; });
        $emitter->on('**', function () use (&$hits) { $hits[] = 'all'; });
        $emitter->on('order.**', function () use (&$hits) { $hits[] = 'deep'; });

        $emitter->emit('order.created');
        self::assertEqualsCanonicalizing(['one', 'all', 'deep'], $hits);

        $hits = [];
        $emitter->emit('order.item.added');
        self::assertEqualsCanonicalizing(['all', 'deep'], $hits);

        $hits = [];
        $emitter->emit('user.created');
        self::assertSame(['all'], $hits);

        $literal = new EventEmitter(wildcard: false);
        $literal->on('order.*', function () use (&$hits) { $hits[] = 'literal'; });
        $literal->emit('order.created');
        self::assertSame(['all'], $hits);
        $literal->emit('order.*');
        self::assertSame(['all', 'literal'], $hits);
    }

    public function testOnceAndOff(): void
    {
        $emitter = new EventEmitter;
        $n = 0;
        $emitter->once('ping', function () use (&$n) { $n++; });
        $emitter->emit('ping');
        $emitter->emit('ping');
        self::assertSame(1, $n);

        $listener = function () use (&$n) { $n += 10; };
        $emitter->on('pong', $listener);
        self::assertCount(1, $emitter->listeners('pong'));
        $emitter->off('pong', $listener);
        self::assertSame([], $emitter->listeners('pong'));
        $emitter->emit('pong');
        self::assertSame(1, $n);
    }

    public function testPsr14Dispatch(): void
    {
        $emitter = new EventEmitter;
        self::assertInstanceOf(EventDispatcherInterface::class, $emitter);
        $seen = [];
        $emitter->on(StoppableEvent::class, function (StoppableEvent $e) use (&$seen) { $seen[] = 'first'; $e->stopped = true; }, 5);
        $emitter->on(StoppableEvent::class, function () use (&$seen) { $seen[] = 'second'; });

        $event = new StoppableEvent;
        self::assertSame($event, $emitter->dispatch($event));
        self::assertSame(['first'], $seen);
    }

    public function testCurrentEvent(): void
    {
        $emitter = new EventEmitter;
        $current = null;
        $emitter->on('a.b', function () use ($emitter, &$current) { $current = $emitter->currentEvent(); });
        $emitter->emit('a.b');
        self::assertSame('a.b', $current);
        self::assertNull($emitter->currentEvent());
    }
}
