<?php

declare(strict_types=1);

namespace Nesto\Orm;

/**
 * TypeORM's ValueTransformer: converts a column value between the entity property and the database.
 * `to()` runs before a value is written (and on `where` values of that column), `from()` after it is read —
 * both also receive null. Several transformers run in order for `to()` and in reverse order for `from()`.
 *
 *   final class LowercaseTransformer implements ValueTransformer {
 *       public function to(mixed $value): mixed { return $value === null ? null : strtolower($value); }
 *       public function from(mixed $value): mixed { return $value; }
 *   }
 *   #[Column(transformer: LowercaseTransformer::class)] public string $email;
 *   #[Column(transformer: [new Trim, LowercaseTransformer::class])] public string $handle;
 *
 * Transformers are instantiated once per entity class (metadata is cached): keep them stateless.
 */
interface ValueTransformer
{
    /** Entity value → database value. */
    public function to(mixed $value): mixed;

    /** Database value → entity value. */
    public function from(mixed $value): mixed;
}
