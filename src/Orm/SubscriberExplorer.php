<?php

declare(strict_types=1);

namespace Nesto\Orm;

use Nesto\Common\Contracts\OnModuleInit;
use Nesto\Core\Container;
use Nesto\Core\DiscoveryService;
use Nesto\Orm\Attributes\EventSubscriber;
use Nesto\Orm\Events\EntitySubscriberInterface;

/**
 * Registers a DataSource's subscribers once the modules are instantiated (OrmModule creates one per data source):
 * the classes of the `subscribers` option (an existing provider instance of that class is reused, otherwise the
 * class is created with dependency injection from OrmModule's scope), and every provider marked
 * #[EventSubscriber] for this data source.
 */
final class SubscriberExplorer implements OnModuleInit
{
    /** @param list<class-string|EntitySubscriberInterface> $subscribers */
    public function __construct(
        private readonly DataSource $dataSource,
        private readonly ?string $name,
        private readonly array $subscribers,
        private readonly DiscoveryService $discovery,
        private readonly Container $container,
    ) {}

    public function onModuleInit(): void
    {
        $providers = [];
        foreach ($this->discovery->providers(false) as [$instance, $class]) {
            if (! $instance instanceof EntitySubscriberInterface) {
                continue;
            }
            $providers[$class->getName()] ??= $instance;
            $attribute = $class->getAttributes(EventSubscriber::class)[0] ?? null;
            if ($attribute !== null && $this->isMine($attribute->newInstance()->dataSource)) {
                $this->dataSource->addSubscriber($instance);
            }
        }
        foreach ($this->subscribers as $subscriber) {
            if (is_string($subscriber)) {
                $subscriber = $providers[$subscriber] ?? $this->container->instantiate($subscriber, $this->container->module(OrmModule::class));
            }
            if (! $subscriber instanceof EntitySubscriberInterface) {
                throw new OrmException($subscriber::class . ' is listed in `subscribers` but does not implement ' . EntitySubscriberInterface::class . '.');
            }
            $this->dataSource->addSubscriber($subscriber);
        }
    }

    private function isMine(?string $dataSource): bool
    {
        $name = $dataSource === null || $dataSource === '' || $dataSource === OrmModule::DEFAULT_DATA_SOURCE ? null : $dataSource;

        return $name === $this->name;
    }
}
