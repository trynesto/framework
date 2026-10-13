<?php

declare(strict_types=1);

namespace Nesto\Orm;

/**
 * Raw SQL used verbatim where a value is expected — a column DEFAULT (TypeORM's `default: () => 'now()'`).
 *
 *   #[Column(default: new SqlExpression('CURRENT_TIMESTAMP'))] public ?DateTimeImmutable $seenAt = null;
 *   $table->uuid('id')->default(new SqlExpression('gen_random_uuid()'));
 */
final class SqlExpression implements \Stringable
{
    public function __construct(public readonly string $sql) {}

    /** A SqlExpression, a Closure returning the SQL (PHP 8.5 constant-expression closures) or null. */
    public static function from(mixed $value): ?self
    {
        return match (true) {
            $value instanceof self => $value,
            $value instanceof \Closure => new self((string) $value()),
            default => null,
        };
    }

    public function __toString(): string
    {
        return $this->sql;
    }
}
