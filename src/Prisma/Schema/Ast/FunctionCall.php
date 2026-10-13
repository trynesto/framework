<?php

declare(strict_types=1);

namespace Nesto\Prisma\Schema\Ast;

use Nesto\Prisma\Schema\Span;

/** autoincrement(), env("DATABASE_URL"), dbgenerated("..."), and field references with options: title(sort: Desc). */
final class FunctionCall extends Expression
{
    public function __construct(public readonly string $name, public readonly ArgumentList $arguments, Span $span)
    {
        parent::__construct($span);
    }

    public function toSource(): string
    {
        return $this->name . '(' . $this->arguments->toSource() . ')';
    }

    public function kind(): string
    {
        return 'function call';
    }
}
