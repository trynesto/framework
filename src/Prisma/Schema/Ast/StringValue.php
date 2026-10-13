<?php

declare(strict_types=1);

namespace Nesto\Prisma\Schema\Ast;

use Nesto\Prisma\Schema\Span;

/** "text" (`value` decoded, `raw` with quotes and escapes as written). */
final class StringValue extends Expression
{
    public function __construct(public readonly string $value, public readonly string $raw, Span $span)
    {
        parent::__construct($span);
    }

    public function toSource(): string
    {
        return $this->raw !== '' ? $this->raw : self::quote($this->value);
    }

    public function kind(): string
    {
        return 'string';
    }

    /** A PSL string literal for $value. */
    public static function quote(string $value): string
    {
        return '"' . strtr($value, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t']) . '"';
    }
}
