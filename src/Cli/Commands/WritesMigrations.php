<?php

declare(strict_types=1);

namespace Nesto\Cli\Commands;

use Nesto\Cli\Generator\Str;

/** File / class naming of new migrations: `{YmdHis}_{snake_name}.php` holding `{PascalName}{YmdHis}`. */
trait WritesMigrations
{
    /** @return array{0: string, 1: string} [file path, class name] */
    protected function migrationTarget(string $snakeName): array
    {
        $dir = $this->migrationsDir();
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $timestamp = date('YmdHis');

        return ["$dir/{$timestamp}_{$snakeName}.php", Str::pascal($snakeName) . $timestamp];
    }

    protected function relative(string $file): string
    {
        $root = rtrim($this->context()->rootDir(), '/') . '/';

        return str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
    }
}
