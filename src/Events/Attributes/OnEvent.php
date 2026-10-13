<?php

declare(strict_types=1);

namespace Nesto\Events\Attributes;

use Attribute;

/**
 * Subscribe a provider method to an event (Nest's @OnEvent()).
 *
 *   #[OnEvent('order.created')]
 *   public function handle(Order $order): void {}
 *
 *   #[OnEvent(OrderCreated::class, priority: 10)]      // class-based event via $emitter->dispatch(new OrderCreated)
 *   #[OnEvent('order.*')]                              // wildcard (when the module has wildcard: true)
 *
 * `async` is accepted for API parity with Nest but ignored: listeners always run synchronously.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class OnEvent
{
    public function __construct(
        public readonly string $event,
        public readonly bool $async = false,
        public readonly int $priority = 0,
    ) {
    }
}
