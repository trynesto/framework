<?php

declare(strict_types=1);

namespace Nesto\Queue\Attributes;

use Attribute;

/** Hook called with (Job $job) right before a job of this processor's queue runs. */
#[Attribute(Attribute::TARGET_METHOD)]
final class OnQueueActive
{
}
