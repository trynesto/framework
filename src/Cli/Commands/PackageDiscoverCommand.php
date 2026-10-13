<?php

declare(strict_types=1);

namespace Nesto\Cli\Commands;

use Nesto\Cli\Command;

/**
 * `php nesto package:discover` rebuilds the package manifest from vendor/composer/installed.json.
 * Normally not needed: the manifest refreshes itself whenever Composer changes the installed packages.
 */
final class PackageDiscoverCommand extends Command
{
    use InteractsWithManifest;

    protected string $signature = 'package:discover';
    protected string $description = 'Rebuild the list of installed Nesto packages';

    public function handle(): int
    {
        $packages = $this->discover()->packages;
        if ($packages === []) {
            $this->comment('No Nesto packages installed (packages opt in with "extra": {"nesto": {...}} in composer.json).');

            return self::SUCCESS;
        }

        $short = static fn (array $classes) => implode(', ', array_map(static fn (string $c) => substr((string) strrchr('\\' . $c, '\\'), 1), $classes)) ?: '-';
        $this->table(['PACKAGE', 'MODULES', 'COMMANDS', 'CONFIG'], array_map(static fn (array $p) => [
            $p['name'],
            $short($p['modules']),
            $short($p['commands']),
            implode(', ', array_keys($p['config'])) ?: '-',
        ], array_values($packages)));

        return self::SUCCESS;
    }
}
