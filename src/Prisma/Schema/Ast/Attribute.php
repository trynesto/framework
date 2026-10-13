<?php

declare(strict_types=1);

namespace Nesto\Prisma\Schema\Ast;

use Nesto\Prisma\Schema\Span;

/**
 * A field attribute (`@id`, `@default(now())`, `@db.VarChar(255)`) or a block attribute (`@@index([a])`).
 * `name` has no `@`; native types keep their prefix ("db.VarChar"). `hasParentheses` tells `@id` from `@id()`.
 */
final class Attribute extends Node
{
    public function __construct(
        public readonly string $name,
        public readonly ArgumentList $arguments,
        public readonly bool $block,
        Span $span,
        public readonly Span $nameSpan,
        public readonly bool $hasParentheses = false,
        public readonly ?string $trailingComment = null,
        public readonly ?string $documentation = null,
    ) {
        parent::__construct($span);
    }

    public function toSource(): string
    {
        return ($this->block ? '@@' : '@') . $this->name . ($this->hasParentheses || ! $this->arguments->isEmpty() ? '(' . $this->arguments->toSource() . ')' : '');
    }
}
