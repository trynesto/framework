<?php

declare(strict_types=1);

namespace Nesto\Orm\Mapping;

use ReflectionClass;
use ReflectionProperty;

/**
 * Reads and writes the property behind a column, walking #[Embedded] objects for embedded columns
 * ($entity->address->city for the column of "address.city"); embedded objects are created on write.
 */
final class PropertyAccess
{
    /**
     * Whether the column has a value on the entity: the property is initialized (an embedded object that is
     * null counts as a null value for its columns; one that is not initialized as no value).
     */
    public static function isInitialized(object $entity, ColumnMetadata $column): bool
    {
        if ($column->property === null) {
            return false;
        }
        $holder = $entity;
        foreach ($column->embedded as [$property]) {
            if (! self::initialized($holder, $property)) {
                return false;
            }
            $holder = $holder->{$property};
            if ($holder === null) {
                return true;
            }
        }

        return self::initialized($holder, (string) $column->propertyName());
    }

    public static function get(object $entity, ColumnMetadata $column): mixed
    {
        $holder = $entity;
        foreach ($column->embedded as [$property]) {
            $holder = $holder->{$property} ?? null;
            if ($holder === null) {
                return null;
            }
        }

        return $holder->{$column->propertyName()};
    }

    public static function set(object $entity, ColumnMetadata $column, mixed $value): void
    {
        self::holder($entity, $column)->{$column->propertyName()} = $value;
    }

    public static function unset(object $entity, ColumnMetadata $column): void
    {
        $holder = $entity;
        foreach ($column->embedded as [$property]) {
            if (! self::initialized($holder, $property) || $holder->{$property} === null) {
                return;
            }
            $holder = $holder->{$property};
        }
        unset($holder->{$column->propertyName()});
    }

    public static function allowsNull(object $entity, ColumnMetadata $column): bool
    {
        $type = (new ReflectionProperty(self::holder($entity, $column), (string) $column->propertyName()))->getType();

        return $type === null || $type->allowsNull();
    }

    /** The object holding the column's property (embedded objects are created when missing). */
    public static function holder(object $entity, ColumnMetadata $column): object
    {
        $holder = $entity;
        foreach ($column->embedded as [$property, $class]) {
            if (! self::initialized($holder, $property) || $holder->{$property} === null) {
                $holder->{$property} = (new ReflectionClass($class))->newInstanceWithoutConstructor();
            }
            $holder = $holder->{$property};
        }

        return $holder;
    }

    private static function initialized(object $object, string $property): bool
    {
        return property_exists($object, $property) && (new ReflectionProperty($object, $property))->isInitialized($object);
    }
}
