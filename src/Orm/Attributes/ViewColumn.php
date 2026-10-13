<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;

/**
 * A column of a #[ViewEntity] (TypeORM's @ViewColumn): `name` is the view's column (default: the property name
 * in snake_case), `transformer` works like #[Column]'s; `type` (optional) drives the PHP conversion when the
 * property type alone is not enough (e.g. 'json').
 *
 *   #[ViewColumn] public int $id;
 *   #[ViewColumn(name: 'post_count')] public int $posts;
 *
 * @param class-string<\Nesto\Orm\ValueTransformer>|\Nesto\Orm\ValueTransformer|list<class-string<\Nesto\Orm\ValueTransformer>|\Nesto\Orm\ValueTransformer>|null $transformer
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class ViewColumn
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly string|object|array|null $transformer = null,
        public readonly ?string $type = null,
    ) {}
}
