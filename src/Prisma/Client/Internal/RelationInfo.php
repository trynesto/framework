<?php

declare(strict_types=1);

namespace Nesto\Prisma\Client\Internal;

use Nesto\Prisma\Dmmf\Field;

/**
 * A relation field seen from its model:
 *
 *   owner  to-one, this model holds the foreign key (`author User @relation(fields: [authorId], ...)`)
 *   back   to-one, the other model holds the foreign key (`profile Profile?`)
 *   many   to-many side of a one-to-many (`posts Post[]`), the other model holds the foreign key
 *   m2m    implicit many-to-many through the `_AToB` join table
 *
 * `pairs` joins the two models: list of [field on this model, field on the target model] that are equal.
 * For m2m: `joinTable`, `thisColumn` (A or B, references this model's id) and `otherColumn`.
 *
 * @internal
 */
final class RelationInfo
{
    public const OWNER = 'owner';
    public const BACK = 'back';
    public const MANY = 'many';
    public const M2M = 'm2m';

    /**
     * @param list<array{0: string, 1: string}> $pairs
     */
    public function __construct(
        public readonly string $model,
        public readonly Field $field,
        public readonly string $kind,
        public readonly string $target,
        public readonly array $pairs,
        public readonly ?string $opposite,
        public readonly ?string $joinTable = null,
        public readonly ?string $thisColumn = null,
        public readonly ?string $otherColumn = null,
        public readonly ?string $onDelete = null,
        public readonly bool $fkRequired = false,
    ) {}

    public function name(): string
    {
        return $this->field->name;
    }

    public function isList(): bool
    {
        return $this->kind === self::MANY || $this->kind === self::M2M;
    }

    /** @return list<string> fields of this model in the join */
    public function thisFields(): array
    {
        return array_map(fn (array $p) => $p[0], $this->pairs);
    }

    /** @return list<string> fields of the target model in the join */
    public function otherFields(): array
    {
        return array_map(fn (array $p) => $p[1], $this->pairs);
    }
}
