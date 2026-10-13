<?php

declare(strict_types=1);

namespace Nesto\Cli\Commands;

use Nesto\Cli\CliException;
use Nesto\Cli\Command;
use Nesto\Cli\Composer;
use Nesto\Cli\Generator\ModuleEditor;
use Nesto\Config\ConfigModule;

/**
 * `php nesto add acme/mail` (Nest's `nest add`): composer require, then wire the package up from its
 * composer.json `extra.nesto`: publish its config to config/, import its module into the root module,
 * and list the .env keys it reads. Its console commands are registered automatically.
 */
final class AddCommand extends Command
{
    use InteractsWithManifest;

    protected string $signature = 'add {package : Composer package, e.g. acme/mail or acme/mail:^1.0} {--skip-install : The package is already required, only wire it up} {--no-publish : Do not copy its config into config/} {--no-import : Do not import its module into the root module} {--force : Overwrite config files that were already published}';
    protected string $description = 'Install a package and wire it up: config, module import, commands (like nest add)';

    public function handle(): int
    {
        $context = $this->context();
        $spec = trim((string) $this->argument('package'));
        $name = strtolower((string) preg_replace('/[:@=\s].*$/', '', $spec));

        if (! $this->option('skip-install')) {
            $this->info("composer require $spec");
            if (Composer::run($context->path(), 'require', $spec) !== 0) {
                throw new CliException("composer require $spec failed.");
            }
            $this->newLine();
        }

        $manifest = $this->discover();
        $package = $manifest->find($name);
        if ($package === null) {
            if (! is_dir($context->path("vendor/$name"))) {
                throw new CliException("$name is not installed (run without --skip-install).");
            }
            $this->warn("$name is installed, but it is not a Nesto package (its composer.json has no extra.nesto).");
            $this->comment('Use its classes directly, or wrap them in a module of your own.');

            return self::SUCCESS;
        }

        $envKeys = [];
        foreach ($package['config'] as $key => $source) {
            $envKeys = [...$envKeys, ...$this->envKeys($source)];
            if (! $this->option('no-publish')) {
                $this->publishConfig($key, $source, (bool) $this->option('force'));
            }
        }

        if (! $this->option('no-import')) {
            $this->importModules($package['modules']);
            if ($package['config'] !== []) {
                $this->importConfigModule();
            }
        }

        $commands = $package['commands'];
        if (array_filter($commands, fn (string $class) => ! class_exists($class)) !== []) {
            Composer::refreshAutoloader($context->path('vendor')); // composer require ran in another process
        }
        foreach ($commands as $class) {
            $label = class_exists($class) ? $this->bin() . ' ' . (new $class())->name() : $class;
            $this->line($this->output->color('CMD   ', 'green') . " $label");
        }

        $missing = array_values(array_diff(array_unique($envKeys), $this->envFileKeys()));
        if ($missing !== []) {
            $this->newLine();
            $this->comment('Optional .env keys it reads (defaults apply when unset): ' . implode(', ', $missing));
        }

        $this->newLine();
        $this->success("$name is ready.");

        return self::SUCCESS;
    }

    /** @param list<string> $modules */
    private function importModules(array $modules): void
    {
        if ($modules === []) {
            return;
        }
        $context = $this->context();
        $file = $context->rootModuleFile();
        if ($file === null) {
            $this->warn('Root module not found (check "rootModule" in nesto.config.php). Import manually: ' . implode(', ', $modules));

            return;
        }
        foreach ($modules as $module) {
            $changed = ModuleEditor::register($file, 'imports', $module);
            $this->line($changed
                ? $this->output->color('UPDATE', 'cyan') . ' ' . $context->relative($file) . " (imports $module)"
                : $this->output->color('SKIP  ', 'yellow') . " $module is already imported");
        }
    }

    /**
     * A package with config reads it through ConfigService: import `new ConfigModule(isGlobal: true)` into the
     * root module unless the project already sets ConfigModule up somewhere.
     */
    private function importConfigModule(): void
    {
        $context = $this->context();
        $file = $context->rootModuleFile();
        if ($file === null || $this->usesConfigModule([$file, $context->sourceRoot()])) {
            return;
        }
        if (ModuleEditor::registerExpression($file, 'imports', ConfigModule::class, 'new %s(isGlobal: true)')) {
            $this->line($this->output->color('UPDATE', 'cyan') . ' ' . $context->relative($file) . ' (imports ' . ConfigModule::class . ', isGlobal: true)');
        }
    }

    /** @param list<string> $paths files or directories */
    private function usesConfigModule(array $paths): bool
    {
        foreach ($paths as $path) {
            $files = is_dir($path)
                ? new \RegexIterator(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)), '/\.php$/')
                : [$path];
            foreach ($files as $file) {
                if (str_contains((string) @file_get_contents((string) $file), 'Nesto\\Config\\ConfigModule')) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<string> */
    private function envFileKeys(): array
    {
        $keys = [];
        foreach (['.env', '.env.example'] as $file) {
            foreach (@file($this->context()->path($file)) ?: [] as $line) {
                if (preg_match('/^\s*(?:export\s+)?([A-Z0-9_]+)\s*=/', $line, $m)) {
                    $keys[] = $m[1];
                }
            }
        }

        return $keys;
    }
}
