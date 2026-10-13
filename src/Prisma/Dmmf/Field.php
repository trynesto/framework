<?php

declare(strict_types=1);

namespace Nesto\Prisma\Dmmf;

/**
 * A model field, shaped like Prisma's DMMF field (`kind` scalar / object / enum, `isList`, `isRequired`,
 * `isUnique`, `isId`, `isReadOnly`, `hasDefaultValue`, `default`, `type`, `nativeType`, `dbName`,
 * `relationName`, `relationFromFields`, `relationToFields`, `relationOnDelete`, `relationOnUpdate`,
 * `isGenerated`, `isUpdatedAt`, `documentation`).
 *
 * Nesto additions (not in Prisma's DMMF, needed by migrate): kind "unsupported" for
 * `Unsupported("...")` fields, `isIgnored` (@ignore), `relationFkName` (the `map:` of @relation) and
 * `defaultMap`. The client generator skips unsupported and ignored fields, like Prisma's DMMF does.
 *
 * `default` is a literal (string / int / float / bool / list for scalar lists; enum defaults are the value
 * name) or a FieldDefault for functions.
 */
final class Field
{
    public const SCALAR = 'scalar';
    public const OBJECT = 'object';
    public const ENUM = 'enum';
    public const UNSUPPORTED = 'unsupported';

    /**
     * @param array{0: string, 1: list<string>}|null $nativeType ["VarChar", ["255"]]
     * @param list<string>                           $relationFromFields
     * @param list<string>                           $relationToFields
     */
    public function __construct(
        public readonly string $name,
        public readonly string $kind,
        public readonly string $type,
        public readonly bool $isList = false,
        public readonly bool $isRequired = true,
        public readonly bool $isUnique = false,
        public readonly bool $isId = false,
        public readonly bool $isReadOnly = false,
        public readonly bool $hasDefaultValue = false,
        public readonly mixed $default = null,
        public readonly ?string $dbName = null,
        public readonly ?array $nativeType = null,
        public readonly ?string $relationName = null,
        public readonly array $relationFromFields = [],
        public readonly array $relationToFields = [],
        public readonly ?string $relationOnDelete = null,
        public readonly ?string $relationOnUpdate = null,
        public readonly ?string $relationFkName = null,
        public readonly bool $isGenerated = false,
        public readonly bool $isUpdatedAt = false,
        public readonly ?string $documentation = null,
        public readonly bool $isIgnored = false,
        public readonly ?string $defaultMap = null,
    ) {}

    /** The column name (`@map` or the field name). Relation fields have none. */
    public function columnName(): string
    {
        return $this->dbName ?? $this->name;
    }

    public function isRelation(): bool
    {
        return $this->kind === self::OBJECT;
    }

    /** A scalar, enum or Unsupported field: one database column. */
    public function isColumn(): bool
    {
        return $this->kind !== self::OBJECT;
    }

    public function defaultFunction(): ?FieldDefault
    {
        return $this->default instanceof FieldDefault ? $this->default : null;
    }

    public function isAutoIncrement(): bool
    {
        return $this->default instanceof FieldDefault && $this->default->name === 'autoincrement';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
            'dbName' => $this->dbName,
            'kind' => $this->kind,
            'isList' => $this->isList,
            'isRequired' => $this->isRequired,
            'isUnique' => $this->isUnique,
            'isId' => $this->isId,
            'isReadOnly' => $this->isReadOnly,
            'hasDefaultValue' => $this->hasDefaultValue,
            'type' => $this->type,
            'nativeType' => $this->nativeType,
        ];
        if ($this->hasDefaultValue) {
            $data['default'] = $this->default instanceof FieldDefault ? $this->default->toArray() : $this->default;
        }
        if ($this->kind === self::OBJECT) {
            $data['relationName'] = $this->relationName;
            $data['relationFromFields'] = $this->relationFromFields;
            $data['relationToFields'] = $this->relationToFields;
            if ($this->relationOnDelete !== null) {
                $data['relationOnDelete'] = $this->relationOnDelete;
            }
            if ($this->relationOnUpdate !== null) {
                $data['relationOnUpdate'] = $this->relationOnUpdate;
            }
            if ($this->relationFkName !== null) {
                $data['relationFkName'] = $this->relationFkName;
            }
        }
        $data['isGenerated'] = $this->isGenerated;
        $data['isUpdatedAt'] = $this->isUpdatedAt;
        if ($this->isIgnored) {
            $data['isIgnored'] = true;
        }
        if ($this->defaultMap !== null) {
            $data['defaultMap'] = $this->defaultMap;
        }
        if ($this->documentation !== null) {
            $data['documentation'] = $this->documentation;
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $default = $data['default'] ?? null;
        if (is_array($default) && isset($default['name'], $default['args']) && is_string($default['name']) && array_is_list($default['args'])) {
            $default = new FieldDefault($default['name'], $default['args']);
        }

        return new self(
            name: $data['name'],
            kind: $data['kind'],
            type: $data['type'],
            isList: $data['isList'] ?? false,
            isRequired: $data['isRequired'] ?? true,
            isUnique: $data['isUnique'] ?? false,
            isId: $data['isId'] ?? false,
            isReadOnly: $data['isReadOnly'] ?? false,
            hasDefaultValue: $data['hasDefaultValue'] ?? false,
            default: $default,
            dbName: $data['dbName'] ?? null,
            nativeType: $data['nativeType'] ?? null,
            relationName: $data['relationName'] ?? null,
            relationFromFields: $data['relationFromFields'] ?? [],
            relationToFields: $data['relationToFields'] ?? [],
            relationOnDelete: $data['relationOnDelete'] ?? null,
            relationOnUpdate: $data['relationOnUpdate'] ?? null,
            relationFkName: $data['relationFkName'] ?? null,
            isGenerated: $data['isGenerated'] ?? false,
            isUpdatedAt: $data['isUpdatedAt'] ?? false,
            documentation: $data['documentation'] ?? null,
            isIgnored: $data['isIgnored'] ?? false,
            defaultMap: $data['defaultMap'] ?? null,
        );
    }
}
