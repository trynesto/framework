<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;

/**
 * Marks a class as a database entity.
 *
 *   #[Entity]                      // table "users" (snake_case plural of the short class name)
 *   #[Entity(name: 'app_users')]   // explicit table name
 *   #[Entity(synchronize: false)]  // left out of schema sync and migration:generate (TypeORM's option)
 *   final class User { ... }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Entity
{
    public function __construct(public readonly ?string $name = null, public readonly bool $synchronize = true) {}
}
