<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;

/**
 * Integer column set to 1 on insert and incremented by every save() / update() / softRemove() / recover()
 * that changes the row (TypeORM's @VersionColumn). Used by optimistic locks:
 * `findOne(['where' => ['id' => 1], 'lock' => ['mode' => 'optimistic', 'version' => 3]])`.
 *
 *   #[VersionColumn] public int $version;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class VersionColumn
{
    public function __construct(public readonly ?string $name = null) {}
}
