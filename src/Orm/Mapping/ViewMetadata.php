<?php

declare(strict_types=1);

namespace Nesto\Orm\Mapping;

/**
 * What #[ViewEntity] adds to an entity's metadata (EntityMetadata::$view): the view's SELECT (SQL, or a
 * callable [Class::class, 'method'] / Closure receiving the DataSource and returning SQL or a QueryBuilder),
 * whether it is materialized (PostgreSQL) and the views it depends on (entity classes or view names).
 */
final class ViewMetadata
{
    /**
     * @param string|array{0: class-string, 1: string}|\Closure|null $expression
     * @param list<string> $dependsOn
     */
    public function __construct(
        public readonly string|array|\Closure|null $expression,
        public readonly bool $materialized = false,
        public readonly array $dependsOn = [],
    ) {}
}
