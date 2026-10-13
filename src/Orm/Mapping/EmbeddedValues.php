<?php

declare(strict_types=1);

namespace Nesto\Orm\Mapping;

use Nesto\Orm\EntityMetadata;

/** Reads #[Embedded] values through their columns (shared by the query builder and upsert()). */
final class EmbeddedValues
{
    /**
     * ['address' => ['city' => 'Oslo']] → ['address.city' => 'Oslo'] for #[Embedded] properties (an embedded
     * object is read through its columns).
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public static function flatten(EntityMetadata $meta, array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            $columns = $meta->column((string) $key) === null ? $meta->embeddedColumns((string) $key) : [];
            if ($columns === [] || ! (is_array($value) || is_object($value) || $value === null)) {
                $out[$key] = $value;
                continue;
            }
            if (is_array($value)) {
                foreach (self::flatten($meta, array_combine(array_map(fn ($k) => $key . '.' . $k, array_keys($value)), $value)) as $k => $v) {
                    $out[$k] = $v;
                }
                continue;
            }
            $depth = substr_count((string) $key, '.') + 1;
            foreach ($columns as $column) {
                $holder = $value;
                foreach (array_slice($column->embedded, $depth) as [$property]) {
                    $holder = $holder?->{$property} ?? null;
                }
                $out[$column->property] = $holder?->{$column->propertyName()} ?? null;
            }
        }

        return $out;
    }
}
