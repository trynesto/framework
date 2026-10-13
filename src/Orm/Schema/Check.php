<?php

declare(strict_types=1);

namespace Nesto\Orm\Schema;

/** A CHECK constraint. Introspected unnamed checks (SQLite) have a null name and match by expression. */
final class Check
{
    public function __construct(public readonly string $expression, public readonly ?string $name = null) {}

    /** Stable default name: "chk_{table}_{8 hex chars of the normalized expression}" (at most 63 characters). */
    public static function defaultName(string $table, string $expression): string
    {
        $hash = substr(md5(Normalizer::expression($expression)), 0, 8);
        $prefix = 'chk_' . $table;

        return (strlen($prefix) > 54 ? substr($prefix, 0, 54) : $prefix) . '_' . $hash;
    }
}
