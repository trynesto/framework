<?php

declare(strict_types=1);

namespace Nesto\Prisma\Migrate;

/**
 * migration.sql text, written the way Prisma writes it: a warnings header, then each statement under a
 * `-- CreateTable` / `-- CreateIndex` / `-- AddForeignKey` / ... comment, CREATE TABLE with one column per
 * line, SQLite table rebuilds as `-- RedefineTables` wrapped in PRAGMA foreign_keys=OFF / ON. split() turns a
 * script back into statements (quotes, comments and PostgreSQL dollar-quoting aware).
 */
final class SqlScript
{
    private const REBUILD_PREFIX = '__nesto_new_';

    /**
     * @param list<string>           $statements
     * @param list<MigrationWarning> $warnings
     */
    public static function render(array $statements, array $warnings = [], string $provider = 'postgresql'): string
    {
        $out = '';
        if ($warnings !== []) {
            $out .= "/*\n  Warnings:\n\n";
            foreach ($warnings as $warning) {
                $out .= '  - ' . $warning->message . "\n";
            }
            $out .= "\n*/\n";
        }
        $blocks = [];
        $count = count($statements);
        for ($i = 0; $i < $count; $i++) {
            $sql = trim($statements[$i]);
            if ($provider === 'sqlite' && preg_match('/^CREATE TABLE "' . self::REBUILD_PREFIX . '([^"]+)"/', $sql, $m) === 1) {
                // a table rebuild: CREATE new, copy, DROP old, RENAME, recreate its indexes
                $table = $m[1];
                $group = [];
                for (; $i < $count; $i++) {
                    $step = trim($statements[$i]);
                    $isIndex = preg_match('/^CREATE (UNIQUE )?INDEX .* ON "' . preg_quote($table, '/') . '"/', $step) === 1;
                    if ($group !== [] && ! $isIndex && ! str_contains($step, self::REBUILD_PREFIX . $table) && ! str_starts_with($step, 'DROP TABLE "' . $table . '"')) {
                        break;
                    }
                    $group[] = self::pretty(str_replace(self::REBUILD_PREFIX, 'new_', $step)) . ';';
                }
                $i--;
                $blocks[] = "-- RedefineTables\nPRAGMA defer_foreign_keys=ON;\nPRAGMA foreign_keys=OFF;\n" . implode("\n", $group) . "\nPRAGMA foreign_keys=ON;\nPRAGMA defer_foreign_keys=OFF;";
                continue;
            }
            $blocks[] = '-- ' . self::label($sql) . "\n" . self::pretty($sql) . ';';
        }
        if ($blocks === []) {
            return $out . "-- This is an empty migration.\n";
        }

        return $out . ($out !== '' ? "\n" : '') . implode("\n\n", $blocks) . "\n";
    }

    /** The comment Prisma puts above a statement. */
    public static function label(string $sql): string
    {
        return match (true) {
            preg_match('/^CREATE EXTENSION\b/i', $sql) === 1 => 'CreateExtension',
            preg_match('/^ALTER EXTENSION\b/i', $sql) === 1 => 'AlterExtension',
            preg_match('/^CREATE TYPE\b/i', $sql) === 1 => 'CreateEnum',
            preg_match('/^ALTER TYPE\b/i', $sql) === 1 => 'AlterEnum',
            preg_match('/^DROP TYPE\b/i', $sql) === 1 => 'DropEnum',
            preg_match('/^CREATE TABLE\b/i', $sql) === 1 => 'CreateTable',
            preg_match('/^CREATE (UNIQUE )?INDEX\b/i', $sql) === 1 => 'CreateIndex',
            preg_match('/^DROP INDEX\b/i', $sql) === 1, preg_match('/^ALTER TABLE \S+ DROP INDEX\b/i', $sql) === 1 => 'DropIndex',
            preg_match('/^DROP TABLE\b/i', $sql) === 1 => 'DropTable',
            preg_match('/^ALTER TABLE \S+ ADD (CONSTRAINT \S+ )?FOREIGN KEY\b/i', $sql) === 1 => 'AddForeignKey',
            preg_match('/^ALTER TABLE \S+ DROP FOREIGN KEY\b/i', $sql) === 1,
            preg_match('/^ALTER TABLE \S+ DROP CONSTRAINT "[^"]*_fkey"/i', $sql) === 1 => 'DropForeignKey',
            preg_match('/^ALTER TABLE \S+ RENAME\b/i', $sql) === 1 => 'RenameTable',
            default => 'AlterTable',
        };
    }

    /** CREATE TABLE with one definition per line (constraints after a blank line), indexes without a space before "(". */
    public static function pretty(string $sql): string
    {
        if (preg_match('/^(CREATE TABLE (?:IF NOT EXISTS )?(?:"(?:[^"]|"")+"|`[^`]+`|\S+)) \((.*)\)(\s*[^)]*)$/s', $sql, $m) === 1) {
            $columns = [];
            $constraints = [];
            foreach (self::splitTopLevel($m[2]) as $definition) {
                $definition = trim($definition);
                if (preg_match('/^(CONSTRAINT|PRIMARY KEY|FOREIGN KEY|UNIQUE|CHECK)\b/i', $definition) === 1) {
                    $constraints[] = $definition;
                } else {
                    $columns[] = $definition;
                }
            }
            $body = '    ' . implode(",\n    ", $columns);
            if ($constraints !== []) {
                $body .= ($columns !== [] ? ",\n\n    " : '    ') . implode(",\n    ", $constraints);
            }

            return $m[1] . " (\n" . $body . "\n)" . rtrim($m[3]);
        }
        if (preg_match('/^(CREATE (?:UNIQUE )?INDEX .+ ON (?:"(?:[^"]|"")+"|`[^`]+`|\S+)(?: USING \w+)?) \(/s', $sql, $m) === 1) {
            return $m[1] . '(' . substr($sql, strlen($m[0]));
        }

        return $sql;
    }

    /**
     * The statements of a script (comments dropped). PRAGMA lines and DO $$ ... $$ blocks stay whole.
     * `$backslashEscapes`: `\'` inside string literals does not end them (MySQL / MariaDB).
     *
     * @return list<string>
     */
    public static function split(string $script, bool $backslashEscapes = false): array
    {
        $statements = [];
        $current = '';
        $length = strlen($script);
        for ($i = 0; $i < $length; $i++) {
            $char = $script[$i];
            $next = $script[$i + 1] ?? '';
            if ($char === '-' && $next === '-') {
                $end = strpos($script, "\n", $i);
                $i = $end === false ? $length : $end;
                $current .= "\n";
                continue;
            }
            if ($char === '/' && $next === '*') {
                $end = strpos($script, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;
                $current .= ' ';
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $end = $i + 1;
                while ($end < $length) {
                    if ($script[$end] === '\\' && $char === "'" && $backslashEscapes) {
                        $end += 2;
                        continue;
                    }
                    if ($script[$end] === $char) {
                        if (($script[$end + 1] ?? '') === $char) {
                            $end += 2;
                            continue;
                        }
                        break;
                    }
                    $end++;
                }
                $current .= substr($script, $i, $end - $i + 1);
                $i = $end;
                continue;
            }
            if ($char === '$' && preg_match('/\G\$([A-Za-z_][A-Za-z0-9_]*)?\$/', $script, $tag, 0, $i) === 1) {
                $close = strpos($script, $tag[0], $i + strlen($tag[0]));
                $end = $close === false ? $length : $close + strlen($tag[0]);
                $current .= substr($script, $i, $end - $i);
                $i = $end - 1;
                continue;
            }
            if ($char === ';') {
                if (trim($current) !== '') {
                    $statements[] = trim($current);
                }
                $current = '';
                continue;
            }
            $current .= $char;
        }
        if (trim($current) !== '') {
            $statements[] = trim($current);
        }

        return $statements;
    }

    /** @return list<string> */
    private static function splitTopLevel(string $sql): array
    {
        $parts = [];
        $depth = 0;
        $quote = null;
        $current = '';
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            if ($quote !== null) {
                $current .= $char;
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        if (trim($current) !== '') {
            $parts[] = $current;
        }

        return $parts;
    }
}
