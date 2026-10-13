<?php

declare(strict_types=1);

namespace Nesto\Orm\Mapping;

/** One table column and, when it is backed by a property, how it maps to it. */
final class ColumnMetadata
{
    /**
     * @param ?string $property      null for implicit foreign-key columns without a property
     * @param ?string $enumClass     BackedEnum class when the property is an enum
     * @param ?string $relation      relation property whose foreign key this column stores
     * @param ?string $morph         "type" / "id" when this column is one half of a MorphTo relation
     * @param list<string|int> $enumValues allowed values of an enum column (from `enum:` or the enum class)
     * @param ?string $enumName      PostgreSQL native enum type name
     * @param list<\Nesto\Orm\ValueTransformer> $transformers applied by TypeConverter (to: in order, from: reversed)
     * @param bool $select           false: left out of entity queries unless selected explicitly (TypeORM select: false)
     * @param bool $insert           false: never written by INSERT
     * @param bool $update           false: never written by UPDATE (TypeORM update: false / readonly)
     * @param bool $array            PostgreSQL array column (TypeORM array: true)
     * @param ?string $generatedType STORED / VIRTUAL generated column computed from $asExpression
     * @param ?string $generationStrategy increment / identity / uuid / rowid (#[PrimaryGeneratedColumn], #[Generated])
     * @param list<array{0: string, 1: class-string}> $embedded path of #[Embedded] properties holding this
     *        column ($property is then the dotted path, e.g. "address.city"); empty for entity properties
     * @param bool $defaultFromProperty the default is the PHP property default (converted like a value)
     * @param ?string $generatedIdentity ALWAYS / BY DEFAULT for identity columns (PostgreSQL)
     */
    public function __construct(
        public readonly ?string $property,
        public readonly string $name,
        public readonly string $type,
        public readonly bool $nullable = false,
        public readonly mixed $default = null,
        public readonly bool $hasDefault = false,
        public readonly ?int $length = null,
        public readonly bool $unique = false,
        public readonly ?int $precision = null,
        public readonly ?int $scale = null,
        public readonly bool $primary = false,
        public readonly bool $generated = false,
        public readonly ?string $enumClass = null,
        public readonly ?string $phpType = null,
        public readonly ?string $relation = null,
        public readonly ?string $special = null,
        public readonly ?string $morph = null,
        public readonly ?string $comment = null,
        public readonly bool $unsigned = false,
        public readonly array $enumValues = [],
        public readonly ?string $enumName = null,
        public readonly ?string $charset = null,
        public readonly ?string $collation = null,
        public readonly array $transformers = [],
        public readonly bool $select = true,
        public readonly bool $insert = true,
        public readonly bool $update = true,
        public readonly bool $array = false,
        public readonly ?string $generatedType = null,
        public readonly ?string $asExpression = null,
        public readonly ?string $generationStrategy = null,
        public readonly array $embedded = [],
        public readonly bool $defaultFromProperty = false,
        public readonly ?string $generatedIdentity = null,
    ) {}

    /** The same column, linked to a relation (foreign-key or MorphTo column backed by an explicit #[Column]). */
    public function linkedTo(string $relation, ?string $morph = null): self
    {
        return new self(
            $this->property, $this->name, $this->type, $this->nullable, $this->default, $this->hasDefault, $this->length,
            $this->unique, $this->precision, $this->scale, false, false, $this->enumClass, $this->phpType,
            relation: $relation, special: $this->special, morph: $morph, comment: $this->comment, unsigned: $this->unsigned,
            enumValues: $this->enumValues, enumName: $this->enumName, charset: $this->charset, collation: $this->collation,
            transformers: $this->transformers, select: $this->select, insert: $this->insert, update: $this->update, array: $this->array,
            generatedType: $this->generatedType, asExpression: $this->asExpression, generationStrategy: $this->generationStrategy,
            embedded: $this->embedded, defaultFromProperty: $this->defaultFromProperty, generatedIdentity: $this->generatedIdentity,
        );
    }

    /** A generated column (asExpression): computed by the database, never written. */
    public function isComputed(): bool
    {
        return $this->generatedType !== null;
    }

    /** Leaf property name (the last segment of an embedded path). */
    public function propertyName(): ?string
    {
        if ($this->property === null || $this->embedded === []) {
            return $this->property;
        }
        $pos = strrpos($this->property, '.');

        return $pos === false ? $this->property : substr($this->property, $pos + 1);
    }

    public function isVirtual(): bool
    {
        return $this->property === null;
    }
}
