<?php

declare(strict_types=1);

namespace Nesto\Schedule;

use DateTimeImmutable;

/**
 * Dependency-free cron parser: 5 fields (min hour dom month dow) or 6 fields (sec min hour dom month dow).
 *
 *   $parser = new CronParser;
 *   $parser->isDue('* /5 * * * *', new DateTimeImmutable('2026-10-12 10:05:00'));   // true
 *   $parser->nextRun('0 9 * * MON-FRI', $from);                                       // next weekday 09:00
 *   $parser->isValid('@daily');                                                       // true
 *
 * Supported: `*`, `?` (same as `*`), lists `1,15`, ranges `1-5`, steps `* /10` and `1-30/5`,
 * names `JAN-DEC` / `SUN-SAT` (SUN = 0 or 7) and macros `@yearly @annually @monthly @weekly
 * @daily @midnight @hourly @minutely`. As in Vixie cron, when both day-of-month and day-of-week
 * are restricted a date matches if EITHER does. `L`, `W` and `#` are not supported.
 */
final class CronParser
{
    private const MACROS = [
        '@yearly' => '0 0 1 1 *',
        '@annually' => '0 0 1 1 *',
        '@monthly' => '0 0 1 * *',
        '@weekly' => '0 0 * * 0',
        '@daily' => '0 0 * * *',
        '@midnight' => '0 0 * * *',
        '@hourly' => '0 * * * *',
        '@minutely' => '* * * * *',
        '@every_minute' => '* * * * *',
        '@every_second' => '* * * * * *',
    ];

    private const MONTHS = ['JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6, 'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12];
    private const DAYS = ['SUN' => 0, 'MON' => 1, 'TUE' => 2, 'WED' => 3, 'THU' => 4, 'FRI' => 5, 'SAT' => 6];

    /** field name => [min, max, names] */
    private const FIELDS = [
        'second' => [0, 59, []],
        'minute' => [0, 59, []],
        'hour' => [0, 23, []],
        'dom' => [1, 31, []],
        'month' => [1, 12, self::MONTHS],
        'dow' => [0, 7, self::DAYS],
    ];

    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    /**
     * @return array{second: ?array<int, true>, minute: array<int, true>, hour: array<int, true>, dom: array<int, true>, month: array<int, true>, dow: array<int, true>, domStar: bool, dowStar: bool, hasSeconds: bool}
     */
    public function parse(string $expression): array
    {
        $expression = trim($expression);
        if (isset($this->cache[$expression])) {
            return $this->cache[$expression];
        }
        $expr = self::MACROS[strtolower($expression)] ?? $expression;
        $parts = preg_split('/\s+/', trim($expr)) ?: [];
        if (count($parts) === 5) {
            array_unshift($parts, null);
        } elseif (count($parts) !== 6) {
            throw new InvalidCronExpressionException("Invalid cron expression \"$expression\": expected 5 or 6 fields.");
        }

        $names = array_keys(self::FIELDS);
        $parsed = ['hasSeconds' => $parts[0] !== null];
        foreach ($names as $i => $field) {
            if ($parts[$i] === null) {
                $parsed[$field] = null;
                continue;
            }
            $parsed[$field] = $this->parseField($parts[$i], $field, $expression);
        }
        $parsed['domStar'] = $parts[3] === '*' || $parts[3] === '?';
        $parsed['dowStar'] = $parts[5] === '*' || $parts[5] === '?';

        return $this->cache[$expression] = $parsed;
    }

    public function isValid(string $expression): bool
    {
        try {
            $this->parse($expression);

            return true;
        } catch (InvalidCronExpressionException) {
            return false;
        }
    }

    /** Does the expression match this exact minute (or second for 6-field expressions)? */
    public function isDue(string $expression, ?DateTimeImmutable $now = null): bool
    {
        $now ??= new DateTimeImmutable;
        $p = $this->parse($expression);

        return $this->matchesMonth($p, $now)
            && $this->matchesDay($p, $now)
            && isset($p['hour'][(int) $now->format('G')])
            && isset($p['minute'][(int) $now->format('i')])
            && ($p['second'] === null || isset($p['second'][(int) $now->format('s')]));
    }

    /** First run strictly after $from. */
    public function nextRun(string $expression, ?DateTimeImmutable $from = null): DateTimeImmutable
    {
        $from ??= new DateTimeImmutable;
        $p = $this->parse($expression);
        $t = $from->setTime((int) $from->format('G'), (int) $from->format('i'), (int) $from->format('s'));
        $t = $p['hasSeconds'] ? $t->modify('+1 second') : $t->setTime((int) $t->format('G'), (int) $t->format('i'), 0)->modify('+1 minute');

        for ($i = 0; $i < 200000; $i++) {
            if (! $this->matchesMonth($p, $t)) {
                $t = $t->modify('first day of next month')->setTime(0, 0, 0);
                continue;
            }
            if (! $this->matchesDay($p, $t)) {
                $t = $t->modify('+1 day')->setTime(0, 0, 0);
                continue;
            }
            $hour = (int) $t->format('G');
            if (! isset($p['hour'][$hour])) {
                $t = $t->setTime($hour, 0, 0)->modify('+1 hour');
                continue;
            }
            $minute = (int) $t->format('i');
            if (! isset($p['minute'][$minute])) {
                $t = $t->setTime($hour, $minute, 0)->modify('+1 minute');
                continue;
            }
            if ($p['second'] !== null && ! isset($p['second'][(int) $t->format('s')])) {
                $t = $t->modify('+1 second');
                continue;
            }

            return $t;
        }

        throw new InvalidCronExpressionException("Cron expression \"$expression\" never matches.");
    }

    /** @return list<DateTimeImmutable> */
    public function nextRuns(string $expression, int $count, ?DateTimeImmutable $from = null): array
    {
        $out = [];
        $t = $from ?? new DateTimeImmutable;
        for ($i = 0; $i < $count; $i++) {
            $t = $this->nextRun($expression, $t);
            $out[] = $t;
        }

        return $out;
    }

    // ---- internals ------------------------------------------------------------------------

    private function matchesMonth(array $p, DateTimeImmutable $t): bool
    {
        return isset($p['month'][(int) $t->format('n')]);
    }

    private function matchesDay(array $p, DateTimeImmutable $t): bool
    {
        $domMatch = isset($p['dom'][(int) $t->format('j')]);
        $dowMatch = isset($p['dow'][(int) $t->format('w')]);
        if (! $p['domStar'] && ! $p['dowStar']) {
            return $domMatch || $dowMatch;
        }

        return $domMatch && $dowMatch;
    }

    /** @return array<int, true> */
    private function parseField(string $value, string $field, string $expression): array
    {
        [$min, $max, $names] = self::FIELDS[$field];
        $set = [];
        foreach (explode(',', $value) as $item) {
            $item = strtoupper(trim($item));
            if ($item === '') {
                throw $this->invalid($expression, $field, $value);
            }
            if (! preg_match('/^(\*|\?|[A-Z]{3}|\d+)(?:-([A-Z]{3}|\d+))?(?:\/(\d+))?$/', $item, $m)) {
                throw $this->invalid($expression, $field, $value);
            }
            $start = $m[1];
            $end = $m[2] ?? '';
            $step = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : 1;
            if ($step < 1) {
                throw $this->invalid($expression, $field, $value);
            }

            if ($start === '*' || $start === '?') {
                $lo = $min;
                $hi = $field === 'dow' ? 6 : $max;
            } else {
                $lo = $this->toNumber($start, $names, $min, $max, $expression, $field, $value);
                if ($end !== '') {
                    $hi = $this->toNumber($end, $names, $min, $max, $expression, $field, $value);
                } else {
                    $hi = $step > 1 ? ($field === 'dow' ? 6 : $max) : $lo;
                }
            }
            if ($field === 'dow') {
                $lo = $lo === 7 ? 0 : $lo;
                $hi = $hi === 7 && $end !== '' ? 6 : ($hi === 7 ? 0 : $hi);
            }
            if ($lo > $hi) {
                throw $this->invalid($expression, $field, $value);
            }
            for ($n = $lo; $n <= $hi; $n += $step) {
                $set[$n] = true;
            }
        }

        return $set;
    }

    /** @param array<string, int> $names */
    private function toNumber(string $token, array $names, int $min, int $max, string $expression, string $field, string $value): int
    {
        if (ctype_digit($token)) {
            $n = (int) $token;
        } elseif (isset($names[$token])) {
            $n = $names[$token];
        } else {
            throw $this->invalid($expression, $field, $value);
        }
        if ($n < $min || $n > $max) {
            throw $this->invalid($expression, $field, $value);
        }

        return $n;
    }

    private function invalid(string $expression, string $field, string $value): InvalidCronExpressionException
    {
        return new InvalidCronExpressionException("Invalid cron expression \"$expression\": bad $field field \"$value\".");
    }
}
