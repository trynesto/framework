<?php

declare(strict_types=1);

namespace Nesto\Prisma\Schema\Ast;

use Nesto\Prisma\Schema\Span;

/** `key = value` inside a datasource or generator block. */
final class Property extends Node
{
    public function __construct(
        public readonly string $key,
        public readonly Span $keySpan,
        public readonly Expression $value,
        Span $span,
        public readonly ?string $documentation = null,
        public readonly ?string $trailingComment = null,
    ) {
        parent::__construct($span);
    }
}
