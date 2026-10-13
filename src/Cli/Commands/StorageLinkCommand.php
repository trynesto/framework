<?php

declare(strict_types=1);

namespace Nesto\Cli\Commands;

use Nesto\Cli\Command;
use Nesto\Filesystem\FilesystemConfig;

/**
 * `php nesto storage:link` creates the symlinks of config/filesystems.php "links"
 * (by default public/storage → storage/app/public), so files on the public disk are served at /storage/...
 */
final class StorageLinkCommand extends Command
{
    protected string $signature = 'storage:link {--relative : Create relative symlinks (survive moving the project)} {--force : Recreate links that already exist}';
    protected string $description = 'Create the symlinks configured for the application (public/storage → storage/app/public)';

    public function handle(): int
    {
        $failed = false;
        foreach (FilesystemConfig::load()['links'] as $link => $target) {
            $label = $this->context()->relative($link) . ' → ' . $this->context()->relative($target);
            if (is_link($link)) {
                if (! $this->option('force')) {
                    $this->line($this->output->color('SKIP  ', 'yellow') . " $label (already linked, use --force to recreate)");
                    continue;
                }
                unlink($link);
            } elseif (file_exists($link)) {
                $this->error("$link already exists and is not a symlink; move it away first.");
                $failed = true;
                continue;
            }

            if (! is_dir($target)) {
                mkdir($target, 0775, true);
            }
            if (! is_dir(dirname($link))) {
                mkdir(dirname($link), 0775, true);
            }
            $source = $this->option('relative') ? self::relativePath(dirname($link), $target) : $target;
            if (! @symlink($source, $link)) {
                $this->error("Unable to create $link (" . (error_get_last()['message'] ?? 'unknown error') . ').');
                $failed = true;
                continue;
            }
            $this->line($this->output->color('LINK  ', 'green') . " $label");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** Path of $to relative to the directory $from: /app/public + /app/storage/app/public → ../storage/app/public */
    public static function relativePath(string $from, string $to): string
    {
        $from = explode('/', trim((string) (realpath($from) ?: $from), '/'));
        $to = explode('/', trim((string) (realpath($to) ?: $to), '/'));
        while ($from !== [] && $to !== [] && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }

        return str_repeat('../', count($from)) . implode('/', $to);
    }
}
