<?php

declare(strict_types=1);

namespace Nesto\Prisma\Schema\Ast;

use Nesto\Prisma\Schema\Span;

/** Base of every AST node: where it is in the source. */
abstract class Node
{
    public function __construct(public readonly Span $span) {}
}
