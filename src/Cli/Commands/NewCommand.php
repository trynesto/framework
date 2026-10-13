<?php

declare(strict_types=1);

namespace Nesto\Cli\Commands;

use Nesto\Cli\Application;
use Nesto\Cli\CliException;
use Nesto\Cli\Command;
use Nesto\Cli\Composer;
use Nesto\Cli\Generator\Str;
use Nesto\Cli\Generator\Stub;

/**
 * `nesto new my-api` scaffolds a project directory with a Hello World module (like `nest new` / `laravel new`).
 *
 * The project requires nesto/framework from Packagist (^MAJOR.MINOR of this framework). For framework development,
 * `--dev` (this checkout) or `--framework-path=../framework` (another one) adds a symlinked path repository instead.
 */
final class NewCommand extends Command
{
    protected string $signature = 'new {name : Project directory name}'
        . ' {--skip-install : Do not run composer install}'
        . ' {--dev : Require the framework checkout this command runs from (path repository, for framework development)}'
        . ' {--framework-path= : Require the framework from a local checkout (path repository, for framework development)}';
    protected string $description = 'Scaffold a new Nesto project';

    public function handle(): int
    {
        $name = trim((string) $this->argument('name'));
        if ($name === '' || preg_match('/[^A-Za-z0-9._\-\/]/', $name)) {
            throw new CliException('Project name may only contain letters, numbers, dots, dashes and underscores.');
        }
        $target = str_starts_with($name, '/') ? $name : getcwd() . '/' . $name;
        if (is_dir($target) && (scandir($target) ?: []) !== ['.', '..']) {
            throw new CliException("Directory \"$target\" already exists and is not empty.");
        }

        $frameworkPath = null;
        if ($this->option('framework-path')) {
            $frameworkPath = realpath((string) $this->option('framework-path')) ?: null;
            if ($frameworkPath === null || ! is_file($frameworkPath . '/composer.json')) {
                throw new CliException('--framework-path must point at a Nesto framework checkout (a directory with composer.json).');
            }
        } elseif ($this->option('dev')) {
            $frameworkPath = (string) realpath(dirname(__DIR__, 3));
        }

        $files = self::files(basename($name), $frameworkPath);

        $this->info("Creating a new Nesto project in $target");
        foreach ($files as $relative => $content) {
            $file = $target . '/' . $relative;
            if (! is_dir(dirname($file))) {
                mkdir(dirname($file), 0777, true);
            }
            file_put_contents($file, $content);
            if ($relative === 'nesto') {
                chmod($file, 0755); // also runnable as ./nesto
            }
            $this->line($this->output->color('CREATE', 'green') . " $relative");
        }
        @mkdir($target . '/database/seeders', 0777, true);

        $installed = false;
        if (! $this->option('skip-install')) {
            $composer = Composer::binary();
            if ($composer === null) {
                $this->warn('composer not found on PATH; run "composer install" in the project.');
            } else {
                $this->newLine();
                $this->info('Installing dependencies...');
                passthru(sprintf('cd %s && %s install --no-interaction 2>&1', escapeshellarg($target), $composer), $code);
                $installed = $code === 0;
                if (! $installed) {
                    $this->warn('composer install failed; fix it and run it again.');
                }
            }
        }

        $this->newLine();
        $this->success("Project \"$name\" created.");
        $this->line('Next steps:');
        $this->line("  cd $name");
        if (! $installed) {
            $this->line('  composer install');
        }
        $this->line('  php nesto start  (or: php -S 127.0.0.1:3000 -t public public/index.php)');
        $this->line('  curl http://127.0.0.1:3000/api');

        return self::SUCCESS;
    }

    /** The Composer constraint new projects use for nesto/framework: ^0.MINOR before 1.0, ^MAJOR.MINOR after. */
    public static function frameworkConstraint(?string $version = null): string
    {
        $version ??= Application::frameworkVersion();
        if (! preg_match('/^v?(\d+)\.(\d+)/', $version, $m)) {
            return '^0.1';
        }

        return '^' . $m[1] . '.' . $m[2];
    }

    /**
     * The scaffold as relative path => content. With $frameworkPath the project requires that local checkout
     * through a symlinked path repository; without it, the released package from Packagist.
     *
     * @return array<string, string>
     */
    public static function files(string $name, ?string $frameworkPath = null): array
    {
        $vars = [
            'name' => $name,
            'kebab' => Str::kebab($name) ?: 'app',
            'vendor' => 'app',
            'frameworkConstraint' => self::frameworkConstraint(),
        ];

        $composer = Stub::render('new-composer', $vars);
        if ($frameworkPath !== null) {
            $json = (array) json_decode($composer, true, flags: JSON_THROW_ON_ERROR);
            $json['require']['nesto/framework'] = '*@dev';
            $json = array_slice($json, 0, 6, true)
                + ['repositories' => [['type' => 'path', 'url' => str_replace('\\', '/', $frameworkPath), 'options' => ['symlink' => true]]]]
                + $json;
            $composer = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        }

        $env = Stub::render('new-env', $vars);

        return [
            'composer.json' => $composer,
            'nesto' => Stub::render('new-nesto', $vars),
            'public/index.php' => Stub::render('new-index', $vars),
            'src/AppModule.php' => Stub::render('new-app-module', $vars),
            'src/App/AppController.php' => Stub::render('new-app-controller', $vars),
            'src/App/AppService.php' => Stub::render('new-app-service', $vars),
            'nesto.config.php' => Stub::render('new-config', $vars),
            'config/filesystems.php' => Stub::render('new-config-filesystems', []),
            '.env.example' => $env,
            '.env' => str_replace('JWT_SECRET=change-me', 'JWT_SECRET=' . bin2hex(random_bytes(32)), $env),
            '.gitignore' => Stub::render('new-gitignore', $vars),
            'phpunit.xml' => Stub::render('new-phpunit', $vars),
            'tests/App/AppControllerTest.php' => Stub::render('new-app-test', $vars),
            'README.md' => Stub::render('new-readme', $vars),
            'database/migrations/.gitkeep' => '',
            'database/README.md' => Stub::render('new-database-readme', $vars),
        ];
    }
}
