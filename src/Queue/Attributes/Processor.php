<?php

declare(strict_types=1);

namespace Nesto\Queue\Attributes;

use Attribute;

/**
 * Marks a provider as the consumer of a queue (Nest's @Processor()).
 *
 *   #[Processor('mail')]
 *   final class MailProcessor {
 *       #[Process('send')]
 *       public function send(Job $job): void {}
 *       #[Process]                                   // default handler for every other job name
 *       public function fallback(Job $job): void {}
 *       #[OnQueueFailed]
 *       public function failed(Job $job, \Throwable $e): void {}
 *   }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Processor
{
    public function __construct(public readonly string $queue)
    {
    }
}
