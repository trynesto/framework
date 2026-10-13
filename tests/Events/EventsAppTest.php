<?php

declare(strict_types=1);

namespace Nesto\Tests\Events;

use Nesto\Common\Attributes\Controller;
use Nesto\Common\Attributes\Injectable;
use Nesto\Common\Attributes\Module;
use Nesto\Common\Attributes\Post;
use Nesto\Core\NestoFactory;
use Nesto\Events\Attributes\OnEvent;
use Nesto\Events\EventEmitter;
use Nesto\Events\EventEmitterModule;
use Nesto\Events\EventSubscribersLoader;
use Nesto\Http\Request;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

final class OrderCreated
{
    public function __construct(public readonly int $id)
    {
    }
}

#[Injectable]
final class OrderListener
{
    /** @var list<string> */
    public array $received = [];

    #[OnEvent('order.created')]
    public function onCreated(array $order): void
    {
        $this->received[] = 'created:' . $order['id'];
    }

    #[OnEvent('order.*', priority: 10)]
    public function onAny(array $order): void
    {
        $this->received[] = 'any:' . $order['id'];
    }

    #[OnEvent(OrderCreated::class)]
    public function onObject(OrderCreated $event): void
    {
        $this->received[] = 'object:' . $event->id;
    }
}

#[Controller('orders')]
final class OrdersController
{
    public function __construct(private readonly EventEmitter $events, private readonly EventDispatcherInterface $dispatcher)
    {
    }

    #[Post()]
    public function create(): array
    {
        $this->events->emit('order.created', ['id' => 1]);
        $this->dispatcher->dispatch(new OrderCreated(2));

        return ['ok' => $this->events === $this->dispatcher];
    }
}

#[Module(imports: [new EventEmitterModule(wildcard: true)], controllers: [OrdersController::class], providers: [OrderListener::class])]
final class EventsAppModule
{
}

final class EventsAppTest extends TestCase
{
    public function testControllerEmitAndServiceListenerThroughTheApp(): void
    {
        $app = NestoFactory::create(EventsAppModule::class);
        $response = $app->handle(Request::create('POST', '/orders'));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['ok' => true], json_decode((string) $response->getBody(), true));

        $listener = $app->get(OrderListener::class);
        self::assertSame(['any:1', 'created:1', 'object:2'], $listener->received);

        $loader = $app->get(EventSubscribersLoader::class);
        self::assertCount(3, $loader->registered());
    }

    public function testForRoot(): void
    {
        $module = EventEmitterModule::forRoot(['wildcard' => false, 'global' => false]);

        self::assertSame(EventEmitterModule::class, $module->module);
        self::assertFalse($module->global);
    }
}
