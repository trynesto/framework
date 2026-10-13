<?php

declare(strict_types=1);

namespace Nesto\Prisma\Client;

/**
 * A raw SQL fragment with bound values — the PHP form of Prisma's tagged templates (`Prisma.sql`, `Prisma.join`,
 * `Prisma.raw`, `Prisma.empty`). Values never become part of the SQL text: each one is a `?` parameter.
 *
 *   $prisma->queryRaw(Prisma::sql('SELECT * FROM "User" WHERE email = ', $email, ' AND id > ', $id));
 *   Prisma::sql('SELECT * FROM "User" WHERE id IN (', Prisma::join($ids), ')');
 *   Prisma::sql('SELECT * FROM ', Prisma::raw('"User"'), ' WHERE active = ', true);   // raw = unescaped SQL
 *
 * Prisma::sql() alternates text and values like a template literal: sql(text, value, text, value, ...).
 * A value may itself be a Sql fragment, which is inlined with its own parameters.
 */
final class Sql implements \Stringable
{
    /**
     * @param list<string> $strings text parts (count($values) + 1 of them)
     * @param list<mixed>  $values
     */
    public function __construct(public readonly array $strings, public readonly array $values = [])
    {
        if (count($strings) !== count($values) + 1) {
            throw new \InvalidArgumentException('A Sql fragment needs one more text part than values.');
        }
    }

    /** Template form: text, value, text, value, ... (text parts may be omitted at the end). */
    public static function template(mixed ...$parts): self
    {
        $strings = [];
        $values = [];
        foreach (array_values($parts) as $i => $part) {
            if ($i % 2 === 0) {
                if (! is_string($part)) {
                    throw new \InvalidArgumentException('Prisma::sql() alternates SQL text and values: argument ' . ($i + 1) . ' must be SQL text.');
                }
                $strings[] = $part;
            } else {
                $values[] = $part;
            }
        }
        if (count($strings) === count($values)) {
            $strings[] = '';
        }

        return new self($strings, $values);
    }

    /** Unescaped SQL text (Prisma.raw) — never put user input here. */
    public static function raw(string $sql): self
    {
        return new self([$sql]);
    }

    public static function empty(): self
    {
        return new self(['']);
    }

    /** Values separated by $separator (Prisma.join): `IN (` . join($ids) . `)`. @param array<mixed> $values */
    public static function join(array $values, string $separator = ', ', string $prefix = '', string $suffix = ''): self
    {
        $values = array_values($values);
        if ($values === []) {
            throw new \InvalidArgumentException('Prisma::join() expects a non-empty list of values.');
        }
        $strings = [$prefix];
        for ($i = 1; $i < count($values); $i++) {
            $strings[] = $separator;
        }
        $strings[] = $suffix;

        return new self($strings, $values);
    }

    /** The SQL with `?` placeholders (nested fragments inlined). */
    public function sql(): string
    {
        $sql = $this->strings[0];
        foreach ($this->values as $i => $value) {
            $sql .= ($value instanceof self ? $value->sql() : '?') . $this->strings[$i + 1];
        }

        return $sql;
    }

    /** The bound values, in placeholder order. @return list<mixed> */
    public function values(): array
    {
        $out = [];
        foreach ($this->values as $value) {
            if ($value instanceof self) {
                array_push($out, ...$value->values());
            } else {
                $out[] = $value;
            }
        }

        return $out;
    }

    /** Append text or another fragment. */
    public function append(self|string $part): self
    {
        $part = is_string($part) ? self::raw($part) : $part;

        return new self($this->merge($part), [...$this->values, ...$part->values]);
    }

    /** @return list<string> */
    private function merge(self $part): array
    {
        $strings = $this->strings;
        $last = array_pop($strings);
        $other = $part->strings;
        $first = array_shift($other);

        return [...$strings, $last . $first, ...$other];
    }

    public function __toString(): string
    {
        return $this->sql();
    }
}
