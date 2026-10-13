<?php

declare(strict_types=1);

namespace Nesto\Prisma\Schema\Ast;

/** The arguments of an attribute or function call, in source order. */
final class ArgumentList
{
    /** @param list<Argument> $arguments */
    public function __construct(public readonly array $arguments = []) {}

    /** The named argument $name, or (when $position is given) the unnamed argument at that position. */
    public function get(string $name, ?int $position = null): ?Argument
    {
        foreach ($this->arguments as $argument) {
            if ($argument->name === $name) {
                return $argument;
            }
        }
        if ($position !== null) {
            return $this->unnamed()[$position] ?? null;
        }

        return null;
    }

    /** @return list<Argument> */
    public function unnamed(): array
    {
        return array_values(array_filter($this->arguments, fn (Argument $a) => $a->name === null));
    }

    public function isEmpty(): bool
    {
        return $this->arguments === [];
    }

    public function toSource(): string
    {
        return implode(', ', array_map(fn (Argument $a) => $a->toSource(), $this->arguments));
    }
}
