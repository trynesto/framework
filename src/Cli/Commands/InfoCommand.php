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
        'Database' => \Nesto\Database\DatabaseModule::class,
        'Logger' => \Nesto\Logger\LoggerModule::class,
        'Prisma' => \Nesto\Prisma\PrismaModule::class,
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
            ['Nesto', Application::frameworkVersion()],
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

        $this->newLine();
        $this->line($this->output->bold('Drivers') . ' (.env + config/*.php)');
        $this->table(['Key', 'Value'], self::drivers());

        return self::SUCCESS;
    }

    /**
     * The effective driver choices of the project in the current directory: ORM_DRIVER + connection, cache store,
     * log channel. Paths::base() must point at the project (the console does that).
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function drivers(): array
    {
        \Nesto\Config\ConfigFile::loadEnv();
        $describe = static function (callable $fn): string {
            try {
                return (string) $fn();
            } catch (\Throwable $e) {
                return 'invalid: ' . $e->getMessage();
            }
        };

        return [
            ['Environment', (string) (env('APP_ENV') ?? 'local') . (env('APP_DEBUG') ? ' (debug)' : '')],
            ['Database', $describe(fn () => \Nesto\Database\DatabaseConfig::describe(\Nesto\Database\DatabaseConfig::load()))],
            ['Cache', $describe(fn () => \Nesto\Cache\CacheConfig::describe(\Nesto\Cache\CacheConfig::load()))],
            ['Log', $describe(fn () => \Nesto\Logger\LogConfig::describe(\Nesto\Logger\LogConfig::load()))],
            ['Logged HTTP errors', $describe(function () {
                $config = \Nesto\Logger\LogConfig::load();
                $value = strtolower((string) ($config['http_errors'] ?? '5xx'));

                return $value . (\Nesto\Config\ConfigFile::bool($config['php_errors'] ?? true, true) ? ', PHP errors logged' : ', PHP errors not logged');
            })],
        ];
    }

    private function packageDirPresent(string $package): bool
    {
        $dir = dirname(__DIR__, 2) . '/' . $package;

        return is_dir($dir) && (glob($dir . '/*.php') ?: []) !== [];
    }
}
