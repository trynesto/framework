<?php

declare(strict_types=1);

namespace Nesto\Cli\Commands;

use Nesto\Cli\Application;
use Nesto\Cli\Command;

/** `php nesto info` prints environment details and which Nesto packages are available. */
final class InfoCommand extends Command
{
    protected string $signature = 'info';
    protected string $description = 'Show PHP, Nesto and project information';

    /** package name => one class that proves it is present */
    public const PACKAGES = [
        'Core' => \Nesto\Core\NestoFactory::class,
        'Cli' => \Nesto\Cli\Application::class,
        'Testing' => \Nesto\Testing\Test::class,
        'Validation' => \Nesto\Validation\ValidationPipe::class,
        'Transformer' => \Nesto\Transformer\ClassTransformer::class,
        'Config' => \Nesto\Config\ConfigModule::class,
        'Orm' => \Nesto\Orm\DataSource::class,
        'Jwt' => \Nesto\Jwt\JwtService::class,
        'Auth' => \Nesto\Auth\AuthModule::class,
        'Cache' => \Nesto\Cache\CacheModule::class,
        'Events' => \Nesto\Events\EventEmitter::class,
        'Queue' => \Nesto\Queue\Worker::class,
        'Schedule' => \Nesto\Schedule\Scheduler::class,
        'Throttler' => \Nesto\Throttler\ThrottlerModule::class,
        'Swagger' => \Nesto\Swagger\SwaggerModule::class,
    ];

    public function handle(): int
    {
        $this->line($this->output->bold('System'));
        $this->table(['Key', 'Value'], [
            ['PHP', PHP_VERSION . ' (' . PHP_SAPI . ')'],
            ['Nesto', Application::VERSION],
            ['OS', php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('m')],
            ['Framework path', realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3)],
        ]);

        $this->newLine();
        $this->line($this->output->bold('Packages'));
        $rows = [];
        foreach (self::PACKAGES as $package => $class) {
            $present = class_exists($class) || $this->packageDirPresent($package);
            $rows[] = [$package, $present ? $this->output->color('present', 'green') : $this->output->color('missing', 'yellow')];
        }
        $this->table(['Package', 'Status'], $rows);

        $this->newLine();
        $this->line($this->output->bold('Project'));
        if ($this->context === null) {
            $this->warn('Not inside a project (no composer.json found upwards from the current directory).');

            return self::SUCCESS;
        }
        $ctx = $this->context;
        $this->table(['Key', 'Value'], [
            ['Root', $ctx->rootDir()],
            ['nesto.config.php', $ctx->hasConfigFile() ? 'yes' : 'no (defaults in use)'],
            ['Root module', $ctx->rootModule() . (class_exists($ctx->rootModule()) ? '' : ' (not found)')],
            ['Source root', (string) $ctx->config('sourceRoot')],
            ['Namespace', $ctx->appNamespace()],
            ['Migrations', (string) $ctx->config('migrations')],
            ['User commands', (string) count((array) $ctx->config('commands'))],
        ]);

        return self::SUCCESS;
    }

    private function packageDirPresent(string $package): bool
    {
        $dir = dirname(__DIR__, 2) . '/' . $package;

        return is_dir($dir) && (glob($dir . '/*.php') ?: []) !== [];
    }
}
