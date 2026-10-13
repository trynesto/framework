<?php

declare(strict_types=1);

namespace Nesto\Queue;

/**
 * Hands out Queue instances by name over the configured driver (one instance per name).
 *
 *   $manager->get('mail')->add('send', [...]);
 */
final class QueueManager
{
    /** @var array<string, Queue> */
    private array $queues = [];

    public function __construct(private readonly QueueDriver $driver)
    {
    }

    public function get(string $name): Queue
    {
        return $this->queues[$name] ??= new Queue($name, $this->driver);
    }

    public function driver(): QueueDriver
    {
        return $this->driver;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->queues);
    }
}
