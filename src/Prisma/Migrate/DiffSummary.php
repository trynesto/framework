<?php

declare(strict_types=1);

namespace Nesto\Prisma\Migrate;

/** A human-readable description of a Plan, like `prisma migrate diff` without --script. */
final class DiffSummary
{
    public static function describe(Plan $plan): string
    {
        if ($plan->isEmpty()) {
            return 'No difference detected.';
        }
        $out = [];
        $createdEnums = array_diff_key($plan->to->enums, $plan->from->enums);
        $droppedEnums = array_diff_key($plan->from->enums, $plan->to->enums);
        $changedEnums = [];
        foreach ($plan->to->enums as $name => $values) {
            if (isset($plan->from->enums[$name]) && (array_diff($values, $plan->from->enums[$name]) !== [] || array_diff($plan->from->enums[$name], $values) !== [])) {
                $changedEnums[$name] = $values;
            }
        }
        if ($createdEnums !== []) {
            $out[] = "[+] Added enums\n" . self::items(array_keys($createdEnums));
        }
        if ($plan->diff->created !== []) {
            $out[] = "[+] Added tables\n" . self::items(array_map(fn ($t) => $t->name, $plan->diff->created));
        }
        if ($droppedEnums !== []) {
            $out[] = "[-] Removed enums\n" . self::items(array_keys($droppedEnums));
        }
        if ($plan->diff->dropped !== []) {
            $out[] = "[-] Removed tables\n" . self::items(array_map(fn ($t) => $t->name, $plan->diff->dropped));
        }
        foreach ($changedEnums as $name => $values) {
            $lines = [];
            foreach (array_diff($values, $plan->from->enums[$name]) as $value) {
                $lines[] = "  [+] Added variant `$value`";
            }
            foreach (array_diff($plan->from->enums[$name], $values) as $value) {
                $lines[] = "  [-] Removed variant `$value`";
            }
            $out[] = "[*] Changed the `$name` enum\n" . implode("\n", $lines);
        }
        $created = array_map(fn ($t) => strtolower($t->name), $plan->diff->created);
        foreach ($plan->diff->altered as $tableDiff) {
            if (in_array(strtolower($tableDiff->table), $created, true)) {
                continue; // the foreign keys of a new table
            }
            $lines = [];
            foreach ($tableDiff->addedColumns as $column) {
                $lines[] = "  [+] Added column `{$column->name}`";
            }
            foreach ($tableDiff->droppedColumns as $column) {
                $lines[] = "  [-] Removed column `{$column->name}`";
            }
            foreach ($tableDiff->changedColumns as [$current]) {
                $lines[] = "  [*] Altered column `{$current->name}`";
            }
            if ($tableDiff->primaryKey !== null) {
                $lines[] = '  [*] Changed the primary key';
            }
            foreach ($tableDiff->droppedIndexes as $index) {
                $lines[] = '  [-] Removed ' . ($index->unique ? 'unique ' : '') . 'index on columns (' . implode(', ', $index->columns) . ')';
            }
            foreach ($tableDiff->addedIndexes as $index) {
                $lines[] = '  [+] Added ' . ($index->unique ? 'unique ' : '') . 'index on columns (' . implode(', ', $index->columns) . ')';
            }
            foreach ($tableDiff->droppedForeignKeys as $fk) {
                $lines[] = '  [-] Removed foreign key on columns (' . implode(', ', $fk->columns) . ')';
            }
            foreach ($tableDiff->addedForeignKeys as $fk) {
                $lines[] = '  [+] Added foreign key on columns (' . implode(', ', $fk->columns) . ')';
            }
            foreach ($tableDiff->droppedChecks as $check) {
                $lines[] = '  [-] Removed check constraint ' . ($check->name ?? $check->expression);
            }
            $out[] = "[*] Changed the `{$tableDiff->table}` table\n" . implode("\n", $lines);
        }

        return implode("\n\n", $out);
    }

    /** @param list<string> $names */
    private static function items(array $names): string
    {
        return implode("\n", array_map(fn (string $n) => "  - $n", $names));
    }
}
