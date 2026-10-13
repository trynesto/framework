<?php

declare(strict_types=1);

namespace Nesto\Schedule;

/** Thrown by CronParser for malformed or out-of-range expressions. */
final class InvalidCronExpressionException extends \InvalidArgumentException
{
}
