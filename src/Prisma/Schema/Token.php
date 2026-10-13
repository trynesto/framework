<?php

declare(strict_types=1);

namespace Nesto\Prisma\Schema;

/** A lexical token of the Prisma Schema Language. */
final class Token
{
    public const IDENT = 'identifier';
    public const STRING = 'string';
    public const NUMBER = 'number';
    public const PUNCT = 'punctuation';
    public const NEWLINE = 'newline';
    public const COMMENT = 'comment';
    public const DOC_COMMENT = 'doc comment';
    public const EOF = 'end of file';
    public const INVALID = 'invalid';

    /** @param string $value identifier / punctuation text, decoded string value, number literal, comment text */
    public function __construct(
        public readonly string $type,
        public readonly string $value,
        public readonly Span $span,
        public readonly string $raw = '',
    ) {}

    public function is(string $type, ?string $value = null): bool
    {
        return $this->type === $type && ($value === null || $this->value === $value);
    }

    public function isPunct(string $value): bool
    {
        return $this->type === self::PUNCT && $this->value === $value;
    }

    public function describe(): string
    {
        return match ($this->type) {
            self::EOF, self::NEWLINE => $this->type,
            self::STRING => 'string ' . $this->raw,
            default => '"' . $this->value . '"',
        };
    }
}
