<?php

declare(strict_types=1);

namespace Nesto\Orm\Schema;

/**
 * One view of the neutral schema model (TypeORM's View): its SELECT, whether it is materialized (PostgreSQL)
 * and the views it reads from. Introspected views carry the expression recorded when they were created (the
 * metadata table), or null when the view was not created by the ORM.
 */
final class View
{
    /** @param list<string> $dependsOn names of the views this one selects from */
    public function __construct(
        public readonly string $name,
        public readonly ?string $expression,
        public readonly bool $materialized = false,
        public readonly array $dependsOn = [],
    ) {}

    /** Same definition (whitespace at the ends ignored, like TypeORM)? */
    public function sameAs(self $other): bool
    {
        return $this->expression !== null && $other->expression !== null
            && trim($this->expression) === trim($other->expression)
            && $this->materialized === $other->materialized;
    }
}
