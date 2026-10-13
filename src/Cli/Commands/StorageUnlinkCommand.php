<?php

declare(strict_types=1);

namespace Nesto\Cli\Commands;

use Nesto\Cli\Command;
use Nesto\Filesystem\FilesystemConfig;

/** `php nesto storage:unlink` removes the symlinks created by storage:link (never the files behind them). */
final class StorageUnlinkCommand extends Command
{
    protected string $signature = 'storage:unlink';
    protected string $description = 'Delete the symlinks created by storage:link';

    public function handle(): int
    {
        foreach (array_keys(FilesystemConfig::load()['links']) as $link) {
            if (! is_link($link)) {
                continue;
            }
            unlink($link);
            $this->line($this->output->color('UNLINK', 'cyan') . ' ' . $this->context()->relative($link));
        }

        return self::SUCCESS;
    }
}
