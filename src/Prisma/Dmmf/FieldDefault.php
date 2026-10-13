<?php

declare(strict_types=1);

namespace Nesto\Prisma\Dmmf;

/**
 * A function default of a field — DMMF `{ name, args }`: autoincrement(), now(), uuid(), uuid(7), cuid(),
 * cuid(2), nanoid(), ulid(), dbgenerated("..."), sequence(). Literal defaults are plain values instead.
 */
final class FieldDefault
{
    /** @param list<string|int|float|bool> $args */
    public function __construct(public readonly string $name, public readonly array $args = []) {}

    /** True for defaults the client generates (uuid, cuid, nanoid, ulid): the database has none. */
    public function isClientSide(): bool
    {
        return in_array($this->name, ['uuid', 'cuid', 'nanoid', 'ulid'], true);
    }

    /** @return array{name: string, args: list<string|int|float|bool>} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'args' => $this->args];
    }
}
