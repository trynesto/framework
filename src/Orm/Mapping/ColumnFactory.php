<?php

declare(strict_types=1);

namespace Nesto\Orm\Mapping;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Embedded;
use Nesto\Orm\Attributes\Generated;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;
use Nesto\Orm\OrmException;
use Nesto\Orm\SqlExpression;
use Nesto\Orm\ValueTransformer;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Builds ColumnMetadata from #[Column] (+ #[Generated]), #[PrimaryGeneratedColumn] and #[Embedded] properties
 * (used by MetadataRegistry).
 */
final class ColumnFactory
{
    private const GENERATED_TYPES = ['STORED', 'VIRTUAL'];

    /** @param list<array{0: string, 1: class-string}> $embedded */
    public static function column(
        string $class,
        ReflectionProperty $prop,
        Column $attr,
        string $propertyPath,
        string $columnName,
        array $embedded = [],
        bool $nullableParent = false,
    ): ColumnMetadata {
        $where = "$class::\$$propertyPath";
        $phpType = self::phpType($prop);
        $nullableType = $prop->getType()?->allowsNull() ?? true;
        [$inferred, $enum] = TypeConverter::inferType($attr->array ? null : $phpType);
        $generated = ($prop->getAttributes(Generated::class)[0] ?? null)?->newInstance();
        $strategy = $generated !== null ? self::strategy($where, $generated->strategy) : null;
        if ($strategy === 'uuid' && $attr->type === null) {
            $inferred = 'uuid';
        }
        $propertyDefault = $prop->hasDefaultValue() ? $prop->getDefaultValue() : null;
        $hasDefault = $attr->hasDefault || $attr->default !== null || $propertyDefault !== null;
        $enumSource = is_string($attr->enum) ? $attr->enum : $enum;
        $enumValues = is_array($attr->enum)
            ? array_values($attr->enum)
            : ($enumSource !== null && is_subclass_of($enumSource, \BackedEnum::class) ? array_map(fn (\BackedEnum $c) => $c->value, $enumSource::cases()) : []);
        $generatedType = null;
        if ($attr->generatedType !== null || $attr->asExpression !== null) {
            $generatedType = strtoupper((string) ($attr->generatedType ?? 'STORED'));
            if (! in_array($generatedType, self::GENERATED_TYPES, true) || trim((string) $attr->asExpression) === '') {
                throw new OrmException("$where: a generated column needs generatedType 'STORED' or 'VIRTUAL' and an asExpression.");
            }
        }
        $computed = $generatedType !== null;

        return new ColumnMetadata(
            $propertyPath,
            $columnName,
            $attr->type ?? ($attr->enum !== null ? 'enum' : $inferred),
            nullable: ! $attr->primary && ($attr->nullable || $nullableType || $computed || $nullableParent),
            default: $attr->default ?? ($computed || $strategy !== null ? null : $propertyDefault),
            hasDefault: ! $computed && $strategy === null && $hasDefault,
            length: $attr->length,
            unique: $attr->unique,
            precision: $attr->precision,
            scale: $attr->scale,
            primary: $attr->primary,
            enumClass: $attr->array ? null : $enum,
            phpType: $phpType,
            comment: $attr->comment,
            unsigned: $attr->unsigned,
            enumValues: $enumValues,
            enumName: $attr->enumName,
            charset: $attr->charset,
            collation: $attr->collation,
            transformers: self::transformers($where, $attr->transformer),
            select: $attr->select,
            insert: $attr->insert && ! $computed,
            update: $attr->update && ! $attr->readonly && ! $computed,
            array: $attr->array,
            generatedType: $generatedType,
            asExpression: $computed ? trim((string) $attr->asExpression) : null,
            generationStrategy: $strategy,
            embedded: $embedded,
            defaultFromProperty: $attr->default === null && $propertyDefault !== null,
        );
    }

    public static function primaryGenerated(string $class, ReflectionProperty $prop, PrimaryGeneratedColumn $attr): ColumnMetadata
    {
        $name = $prop->getName();
        $strategy = self::strategy("$class::\$$name", $attr->strategy());
        if ($attr->generatedIdentity !== null && ! in_array(strtoupper($attr->generatedIdentity), ['ALWAYS', 'BY DEFAULT'], true)) {
            throw new OrmException("$class::\$$name: generatedIdentity must be 'ALWAYS' or 'BY DEFAULT'.");
        }

        return new ColumnMetadata(
            $name,
            $attr->name ?? Naming::snake($name),
            $attr->columnType(),
            primary: true,
            generated: true,
            phpType: self::phpType($prop),
            comment: $attr->comment,
            generationStrategy: $strategy,
            generatedIdentity: $strategy === 'identity' ? strtoupper($attr->generatedIdentity ?? 'BY DEFAULT') : null,
        );
    }

    /**
     * Columns of an #[Embedded] property (recursively), with their dotted property path and prefixed name.
     *
     * @param list<array{0: string, 1: class-string}> $parentPath
     * @return list<ColumnMetadata>
     */
    public static function embedded(string $class, ReflectionProperty $prop, Embedded $attr, array $parentPath = [], string $parentPrefix = '', bool $nullableParent = false): array
    {
        // columns of an embedded object that may be null (`?Address $billing`) are nullable
        $nullableParent = $nullableParent || ($prop->getType()?->allowsNull() ?? true);
        $name = $prop->getName();
        $pathNames = array_map(fn (array $p) => $p[0], $parentPath);
        $where = $class . '::$' . implode('.', [...$pathNames, $name]);
        if (! class_exists($attr->class)) {
            throw new OrmException("#[Embedded] on $where: class \"{$attr->class}\" does not exist.");
        }
        $prefix = match (true) {
            $attr->prefix === false => '',
            $attr->prefix === true => Naming::snake($name) . '_',
            default => rtrim($attr->prefix, '_') === '' ? '' : rtrim($attr->prefix, '_') . '_',
        };
        $prefix = $parentPrefix . $prefix;
        $path = [...$parentPath, [$name, $attr->class]];
        $propertyPrefix = implode('.', array_map(fn (array $p) => $p[0], $path)) . '.';
        $columns = [];
        foreach ((new ReflectionClass($attr->class))->getProperties(ReflectionProperty::IS_PUBLIC) as $inner) {
            if ($inner->isStatic()) {
                continue;
            }
            $nested = ($inner->getAttributes(Embedded::class)[0] ?? null)?->newInstance();
            if ($nested !== null) {
                array_push($columns, ...self::embedded($class, $inner, $nested, $path, $prefix, $nullableParent));
                continue;
            }
            $column = ($inner->getAttributes(Column::class)[0] ?? null)?->newInstance();
            if ($column === null) {
                continue;
            }
            if ($column->primary) {
                throw new OrmException("$where: embedded columns cannot be primary keys.");
            }
            $columnName = $prefix . ($column->name ?? Naming::snake($inner->getName()));
            $columns[] = self::column($class, $inner, $column, $propertyPrefix . $inner->getName(), $columnName, $path, $nullableParent);
        }
        if ($columns === []) {
            throw new OrmException("#[Embedded] on $where: {$attr->class} has no #[Column] properties.");
        }

        return $columns;
    }

    /** Converts the default of a column to the value written in its DDL (property defaults are PHP values). */
    public static function databaseDefault(ColumnMetadata $column): mixed
    {
        $default = $column->default;
        if ($default === null || SqlExpression::from($default) !== null) {
            return $default;
        }
        if (is_string($default) && strcasecmp($default, 'CURRENT_TIMESTAMP') === 0) {
            return $default;
        }

        return TypeConverter::toDatabase($column, $default, transform: $column->defaultFromProperty);
    }

    /** @return list<ValueTransformer> */
    private static function transformers(string $where, string|object|array|null $transformer): array
    {
        if ($transformer === null) {
            return [];
        }
        $list = is_array($transformer) ? array_values($transformer) : [$transformer];
        $out = [];
        foreach ($list as $item) {
            if (is_string($item)) {
                if (! is_subclass_of($item, ValueTransformer::class)) {
                    throw new OrmException("$where: transformer $item must implement " . ValueTransformer::class . '.');
                }
                $item = new $item;
            }
            if (! $item instanceof ValueTransformer) {
                throw new OrmException("$where: a transformer must implement " . ValueTransformer::class . ', got ' . get_debug_type($item) . '.');
            }
            $out[] = $item;
        }

        return $out;
    }

    private static function strategy(string $where, string $strategy): string
    {
        $strategy = strtolower($strategy);
        if (! in_array($strategy, PrimaryGeneratedColumn::STRATEGIES, true)) {
            throw new OrmException("$where: unknown generation strategy \"$strategy\" (expected " . implode(', ', PrimaryGeneratedColumn::STRATEGIES) . ').');
        }

        return $strategy === 'rowid' ? 'increment' : $strategy;
    }

    public static function phpType(ReflectionProperty $prop): ?string
    {
        $type = $prop->getType();
        if ($type instanceof ReflectionNamedType) {
            return $type->getName();
        }
        if ($type instanceof \ReflectionUnionType) {
            foreach ($type->getTypes() as $t) {
                if ($t instanceof ReflectionNamedType && $t->getName() !== 'null') {
                    return $t->getName();
                }
            }
        }

        return null;
    }
}
