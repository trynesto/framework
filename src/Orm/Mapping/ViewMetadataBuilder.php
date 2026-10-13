<?php

declare(strict_types=1);

namespace Nesto\Orm\Mapping;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\ViewColumn;
use Nesto\Orm\Attributes\ViewEntity;
use Nesto\Orm\EntityMetadata;
use Nesto\Orm\MetadataRegistry;
use Nesto\Orm\OrmException;
use ReflectionClass;
use ReflectionProperty;

/**
 * EntityMetadata of a #[ViewEntity] class: its #[ViewColumn] properties, no relations. The "primary" column
 * (used for default ordering) is `id` when there is one, else the first column; rows are told apart by all
 * their columns, like TypeORM.
 */
final class ViewMetadataBuilder
{
    /** @param ReflectionClass<object> $ref */
    public static function build(ReflectionClass $ref, ViewEntity $view): EntityMetadata
    {
        $class = $ref->getName();
        $columns = [];
        foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic()) {
                continue;
            }
            $attr = ($prop->getAttributes(ViewColumn::class)[0] ?? null)?->newInstance();
            if ($attr === null) {
                continue;
            }
            $name = $prop->getName();
            $column = ColumnFactory::column($class, $prop, new Column(
                type: $attr->type,
                name: $attr->name,
                nullable: $prop->getType()?->allowsNull() ?? true,
                transformer: $attr->transformer,
                insert: false,
                update: false,
            ), $name, $attr->name ?? Naming::snake($name));
            $columns[$column->name] = $column;
        }
        if ($columns === []) {
            throw new OrmException("View entity $class has no #[ViewColumn] properties.");
        }
        $primary = null;
        foreach ($columns as $column) {
            if ($column->property === 'id' || $column->name === 'id') {
                $primary = $column;
                break;
            }
        }
        $dependsOn = array_map(
            static fn (string $dependency): string => class_exists($dependency) && MetadataRegistry::has($dependency) ? MetadataRegistry::get($dependency)->table : $dependency,
            $view->dependsOn,
        );

        return new EntityMetadata(
            $class,
            $view->name ?? Naming::tableFor($class),
            $columns,
            $primary ?? reset($columns),
            [],
            synchronize: $view->synchronize,
            view: new ViewMetadata($view->expression, $view->materialized, array_values($dependsOn)),
        );
    }
}
