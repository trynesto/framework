<?php

declare(strict_types=1);

namespace Nesto\Common\Attributes;

use Attribute;

/** Nest's @Catch(): which exception classes an ExceptionFilter handles. Empty = everything. */
#[Attribute(Attribute::TARGET_CLASS)]
final class Catches
{
    /** @var list<class-string<\Throwable>> */
    public readonly array $exceptions;

    public function __construct(string ...$exceptions)
    {
        $this->exceptions = array_values($exceptions);
    }
}
