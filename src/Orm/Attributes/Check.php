<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;

/**
 * CHECK constraint (TypeORM's @Check). The expression is raw SQL over column names.
 *
 *   #[Check('age >= 18')]
 *   #[Check('chk_price_positive', 'price > 0')]
 *
 * Unnamed checks get a stable name derived from the table and the expression ("chk_{table}_{hash}"), so
 * editing the expression replaces the constraint. MySQL enforces CHECK from 8.0.16, MariaDB from 10.2.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Check
{
    public readonly string $expression;
    public readonly ?string $name;

    /** One argument: the expression. Two: the name, then the expression. */
    public function __construct(?string $name = null, ?string $expression = null)
    {
        $this->expression = $expression ?? (string) $name;
        $this->name = $expression === null ? null : $name;
    }
}
