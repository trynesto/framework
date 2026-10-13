<?php

declare(strict_types=1);

namespace Nesto\Queue\Attributes;

use Attribute;

/** Hook called with (Job $job, mixed $result) after a job completed. */
#[Attribute(Attribute::TARGET_METHOD)]
final class OnQueueCompleted
{
}
