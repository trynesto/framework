<?php

declare(strict_types=1);

namespace Nesto\Orm\Mapping;

use Nesto\Orm\OrmException;

/**
 * PostgreSQL array literals ('{1,2,3}', '{"a b","c\"d",NULL}') for array columns (#[Column(array: true)])
 * and the array operators. One dimension (nested PHP lists become nested literals on write).
 */
final class PgArray
{
    /** @param list<mixed> $values */
    public static function format(array $values): string
    {
        $items = [];
        foreach ($values as $value) {
            $items[] = match (true) {
                $value === null => 'NULL',
                is_array($value) => self::format(array_values($value)),
                is_bool($value) => $value ? 't' : 'f',
                is_int($value) || is_float($value) => (string) $value,
                default => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value) . '"',
            };
        }

        return '{' . implode(',', $items) . '}';
    }

    /** @return list<?string> elements as strings (NULL → null) */
    public static function parse(string $literal): array
    {
        $literal = trim($literal);
        if (preg_match('/^\[[^\]]*\]=/', $literal) === 1) {
            $literal = substr($literal, (int) strpos($literal, '=') + 1); // explicit bounds: [0:2]={...}
        }
        if ($literal === '' || $literal[0] !== '{' || ! str_ends_with($literal, '}')) {
            throw new OrmException("Not a PostgreSQL array literal: $literal");
        }
        $body = substr($literal, 1, -1);
        $items = [];
        $length = strlen($body);
        $i = 0;
        while ($i < $length) {
            if ($body[$i] === '"') {
                $value = '';
                for ($i++; $i < $length && $body[$i] !== '"'; $i++) {
                    if ($body[$i] === '\\') {
                        $i++;
                    }
                    $value .= $body[$i] ?? '';
                }
                $items[] = $value;
                $i++; // closing quote
            } else {
                $end = strpos($body, ',', $i);
                $raw = trim($end === false ? substr($body, $i) : substr($body, $i, $end - $i));
                $items[] = strcasecmp($raw, 'NULL') === 0 ? null : $raw;
                $i = $end === false ? $length : $end;
            }
            if ($i < $length && $body[$i] === ',') {
                $i++;
            }
        }

        return $items;
    }
}
