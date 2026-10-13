<?php

declare(strict_types=1);

namespace Nesto\Queue\Attributes;

use Attribute;

/** Hook called with (Job $job, \Throwable $error) after every failed attempt (check $job->attemptsMade / $job->isFinished()). */
#[Attribute(Attribute::TARGET_METHOD)]
final class OnQueueFailed
{
}
