<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;

/**
 * A value generated on insert for a non-primary #[Column] (TypeORM's @Generated):
 *
 *   #[Column(type: 'uuid')] #[Generated('uuid')] public string $publicId;   // UUID v4 (PostgreSQL: gen_random_uuid())
 *   #[Column] #[Generated('increment')] public int $ticket;                 // PostgreSQL only (a SERIAL / BIGSERIAL column)
 *
 * The value is on the entity after save(); a uuid you set yourself is kept. 'rowid' is accepted as 'increment'.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Generated
{
    public function __construct(public readonly string $strategy = 'increment') {}
}
