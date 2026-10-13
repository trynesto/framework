<?php

declare(strict_types=1);

namespace Nesto\Queue;

/**
 * Which callable handles which (queue, job name), plus the per-queue lifecycle hooks.
 * Filled by ProcessorExplorer from #[Processor] classes, or by hand:
 *
 *   $registry->register('mail', 'send', fn (Job $job) => $mailer->send($job->data));
 *   $registry->register('mail', null, fn (Job $job) => ...);            // default handler
 *   $registry->on('mail', 'failed', fn (Job $job, \Throwable $e) => ...);
 */
final class ProcessorRegistry
{
    public const EVENTS = ['active', 'completed', 'failed', 'progress'];

    /** @var array<string, array<string, callable>> queue => (name|'' => handler) */
    private array $handlers = [];
    /** @var array<string, array<string, list<callable>>> queue => event => listeners */
    private array $hooks = [];

    public function register(string $queue, ?string $jobName, callable $handler): void
    {
        $this->handlers[$queue][$jobName ?? ''] = $handler;
    }

    public function on(string $queue, string $event, callable $listener): void
    {
        if (! in_array($event, self::EVENTS, true)) {
            throw new \InvalidArgumentException("Unknown queue event \"$event\".");
        }
        $this->hooks[$queue][$event][] = $listener;
    }

    /** Named handler first, then the queue's default (#[Process] without a name). */
    public function handlerFor(string $queue, string $jobName): ?callable
    {
        return $this->handlers[$queue][$jobName] ?? $this->handlers[$queue][''] ?? null;
    }

    public function emit(string $queue, string $event, mixed ...$args): void
    {
        foreach ($this->hooks[$queue][$event] ?? [] as $listener) {
            $listener(...$args);
        }
    }

    public function has(string $queue): bool
    {
        return isset($this->handlers[$queue]);
    }

    /** Queues that have at least one handler. @return list<string> */
    public function queues(): array
    {
        return array_keys($this->handlers);
    }
}
