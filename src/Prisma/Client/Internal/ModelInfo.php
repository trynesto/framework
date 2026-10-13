<?php

declare(strict_types=1);

namespace Nesto\Prisma\Client\Internal;

use Nesto\Prisma\Dmmf\Field;
use Nesto\Prisma\Dmmf\Model;

/**
 * A model as the client sees it: the fields it can read and write (Unsupported and @ignore fields are left
 * out, like Prisma Client does), its relations, primary key and unique criteria.
 *
 * `uniqueKeys` maps every WhereUniqueInput key to its fields: single @id / @unique fields by name, compound
 * @@id / @@unique by their `name:` or the field names joined with "_" (`email_name`).
 *
 * @internal
 */
final class ModelInfo
{
    /**
     * @param array<string, Field>        $scalars    scalar and enum fields by name (declaration order)
     * @param array<string, RelationInfo> $relations
     * @param list<string>                $primaryKey
     * @param array<string, list<string>> $uniqueKeys
     * @param array<string, list<string>> $compoundKeys compound unique keys only (name => fields)
     */
    public function __construct(
        public readonly Model $model,
        public readonly string $name,
        public readonly string $table,
        public readonly array $scalars,
        public readonly array $relations,
        public readonly array $primaryKey,
        public readonly array $uniqueKeys,
        public readonly array $compoundKeys,
    ) {}

    public function scalar(string $name): ?Field
    {
        return $this->scalars[$name] ?? null;
    }

    public function relation(string $name): ?RelationInfo
    {
        return $this->relations[$name] ?? null;
    }

    public function column(string $field): string
    {
        return ($this->scalars[$field] ?? throw new \LogicException("Unknown field {$this->name}.$field"))->columnName();
    }

    /** Fields identifying a row: the primary key, else the first unique criterion. @return list<string> */
    public function identity(): array
    {
        return $this->primaryKey !== [] ? $this->primaryKey : (array_values($this->uniqueKeys)[0] ?? array_keys($this->scalars));
    }

    /** The delegate property name: User → user, BlogPost → blogPost. */
    public function delegateName(): string
    {
        return lcfirst($this->name);
    }
}
