<?php

declare(strict_types=1);

namespace Nesto\Prisma\Schema\Ast;

use Nesto\Prisma\Schema\Span;

/** A `//` comment on its own line (kept by the formatter). `text` excludes the slashes. */
final class Comment extends Node
{
    public function __construct(public readonly string $text, Span $span)
    {
        parent::__construct($span);
    }
}
