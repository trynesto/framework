<?php

declare(strict_types=1);

namespace Nesto\Cli\Commands;

use Nesto\Core\Packages\PackageManifest;

/** Shared by `add`, `config:publish` and `package:discover`. */
trait InteractsWithManifest
{
    /** Rebuild the manifest from vendor/composer/installed.json and refresh its cache. */
    protected function discover(): PackageManifest
    {
        $vendor = $this->context()->path('vendor');
        PackageManifest::flush();
        $manifest = PackageManifest::build($vendor);
        $manifest->write($vendor . '/nesto-packages.php');

        return $manifest;
    }

    /** Copy a package's default config to config/<key>.php; returns CREATE, OVERWRITE or SKIP. */
    protected function publishConfig(string $key, string $source, bool $force): string
    {
        $target = $this->context()->path("config/$key.php");
        $exists = is_file($target);
        if ($exists && ! $force) {
            $this->line($this->output->color('SKIP  ', 'yellow') . " config/$key.php (already published, use --force to overwrite)");

            return 'SKIP';
        }
        if (! is_file($source)) {
            $this->warn("The package declares config \"$key\" but $source does not exist.");

            return 'SKIP';
        }
        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0777, true);
        }
        copy($source, $target);
        $this->line($this->output->color($exists ? 'UPDATE' : 'CREATE', $exists ? 'cyan' : 'green') . " config/$key.php");

        return $exists ? 'OVERWRITE' : 'CREATE';
    }

    /** @return list<string> the env('KEY') names a config file reads */
    protected function envKeys(string $file): array
    {
        preg_match_all('/\benv\(\s*[\'"]([A-Z0-9_]+)[\'"]/', (string) @file_get_contents($file), $m);

        return array_values(array_unique($m[1]));
    }
}
