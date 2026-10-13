<?php

declare(strict_types=1);

namespace Nesto\Queue\Attributes;

use Attribute;

/**
 * Job handler inside a #[Processor] class (Nest's @Process()). Without a name it handles every
 * job that has no named handler. The method receives the Job; its return value becomes
 * `$job->returnvalue` and is passed to #[OnQueueCompleted].
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Process
{
    public function __construct(public readonly ?string $name = null)
    {
    }
}
