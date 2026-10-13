<?php

declare(strict_types=1);

namespace Nesto\Events;

use Nesto\Common\Contracts\DynamicModuleFactory;
use Nesto\Common\DynamicModule;
use Nesto\Common\Provider;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Nesto's @nestjs/event-emitter: provides `EventEmitter` (also as PSR-14 `EventDispatcherInterface`)
 * and wires every `#[OnEvent]` provider method on bootstrap.
 *
 *   #[Module(imports: [new EventEmitterModule(wildcard: true, delimiter: '.')])]
 *   final class AppModule {}
 *
 *   // or at runtime
 *   EventEmitterModule::forRoot(['wildcard' => true]);
 *
 *   final class OrdersService {
 *       public function __construct(private EventEmitter $events) {}
 *       public function create(): void { $this->events->emit('order.created', $order); }
 *   }
 *   final class MailListener {
 *       #[OnEvent('order.created')]
 *       public function onCreated(Order $order): void {}
 *   }
 */
final class EventEmitterModule implements DynamicModuleFactory
{
    public function __construct(
        private readonly bool $wildcard = true,
        private readonly string $delimiter = '.',
        private readonly bool $global = true,
    ) {
    }

    /** @param array{wildcard?: bool, delimiter?: string, global?: bool} $options */
    public static function forRoot(array $options = []): DynamicModule
    {
        return (new self(
            wildcard: $options['wildcard'] ?? true,
            delimiter: $options['delimiter'] ?? '.',
            global: $options['global'] ?? true,
        ))->toDynamicModule();
    }

    public function toDynamicModule(): DynamicModule
    {
        $wildcard = $this->wildcard;
        $delimiter = $this->delimiter;

        return new DynamicModule(
            module: self::class,
            providers: [
                (new Provider(EventEmitter::class))->useFactory(fn () => new EventEmitter($wildcard, $delimiter)),
                (new Provider(EventDispatcherInterface::class))->useExisting(EventEmitter::class),
                EventSubscribersLoader::class,
            ],
            exports: [EventEmitter::class, EventDispatcherInterface::class, EventSubscribersLoader::class],
            global: $this->global,
        );
    }
}
