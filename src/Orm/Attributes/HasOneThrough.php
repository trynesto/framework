<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;

/**
 * Read-only "has one through" (Laravel's hasOneThrough): Mechanic → car → owner.
 *
 *   #[HasOneThrough(Owner::class, through: Car::class)] public ?Owner $carOwner = null;   // on Mechanic
 *
 * Same keys as #[HasManyThrough] (firstKey cars.mechanic_id, secondKey owners.car_id, localKey, secondLocalKey).
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class HasOneThrough
{
    /**
     * @param class-string $target
     * @param class-string $through
     */
    public function __construct(
        public readonly string $target,
        public readonly string $through,
        public readonly ?string $firstKey = null,
        public readonly ?string $secondKey = null,
        public readonly ?string $localKey = null,
        public readonly ?string $secondLocalKey = null,
        public readonly bool $eager = false,
    ) {}
}
