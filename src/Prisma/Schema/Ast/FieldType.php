<?php

declare(strict_types=1);

namespace Nesto\Prisma\Schema\Ast;

use Nesto\Prisma\Schema\Span;

/** `Int`, `String?`, `Post[]`, `Unsupported("circle")?` */
final class FieldType extends Node
{
    public function __construct(
        public readonly string $name,
        public readonly bool $optional,
        public readonly bool $list,
        Span $span,
        public readonly ?string $unsupported = null,
    ) {
        parent::__construct($span);
    }

    public function toSource(): string
    {
        $base = $this->unsupported !== null ? 'Unsupported(' . StringValue::quote($this->unsupported) . ')' : $this->name;

        return $base . ($this->list ? '[]' : '') . ($this->optional ? '?' : '');
    }
}
