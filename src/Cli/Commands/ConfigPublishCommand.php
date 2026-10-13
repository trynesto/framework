<?php

declare(strict_types=1);

namespace Nesto\Cli\Commands;

use Nesto\Cli\CliException;
use Nesto\Cli\Command;

/**
 * `php nesto config:publish mail` copies a package's default config to config/mail.php so you can edit it
 * (Laravel's vendor:publish). Without arguments it lists what can be published.
 */
final class ConfigPublishCommand extends Command
{
    use InteractsWithManifest;

    protected string $signature = 'config:publish {key? : Config key (mail) or package name (acme/mail)} {--all : Publish every package config} {--force : Overwrite files that were already published}';
    protected string $description = 'Copy package config files into config/ so you can edit them';

    public function handle(): int
    {
        $available = [];
        foreach ($this->discover()->packages as $package) {
            foreach ($package['config'] as $key => $source) {
                $available[] = ['key' => $key, 'package' => $package['name'], 'source' => $source];
            }
        }
        if ($available === []) {
            $this->comment('No installed package declares a config file.');

            return self::SUCCESS;
        }

        $wanted = $this->argument('key');
        if ($wanted === null && ! $this->option('all')) {
            $this->table(['KEY', 'PACKAGE', 'STATUS'], array_map(fn (array $c) => [
                $c['key'],
                $c['package'],
                is_file($this->context()->path("config/{$c['key']}.php")) ? 'published' : 'default (not published)',
            ], $available));
            $this->comment("Publish one with: {$this->bin()} config:publish <key>");

            return self::SUCCESS;
        }

        $selected = $this->option('all')
            ? $available
            : array_filter($available, fn (array $c) => $c['key'] === $wanted || $c['package'] === strtolower((string) $wanted));
        if ($selected === []) {
            throw new CliException("No package config named \"$wanted\". Run \"{$this->bin()} config:publish\" to list them.");
        }
        foreach ($selected as $config) {
            $this->publishConfig($config['key'], $config['source'], (bool) $this->option('force'));
        }

        return self::SUCCESS;
    }
}
