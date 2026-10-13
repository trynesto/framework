<?php

declare(strict_types=1);

namespace Nesto\Logger;

use Nesto\Config\ConfigFile;
use Nesto\Config\ConfigModule;
use Nesto\Core\Paths;
use Nesto\Logger\Channels\FileChannel;
use Nesto\Logger\Channels\LogChannel;
use Nesto\Logger\Channels\StackChannel;
use Nesto\Logger\Channels\StreamChannel;

/**
 * The logging configuration: config/logging.php on top of these env-driven defaults.
 *
 *   LOG_CHANNEL      file | stderr | stdout | stack | console | null   (no config at all: console, i.e. errors on
 *                    STDERR and the rest on STDOUT)
 *   LOG_STACK        channels of the stack, default "file,stderr"
 *   LOG_PATH         default storage/logs/nesto.log (relative paths are from the project root)
 *   LOG_DAILY        true: one file per day, nesto-YYYY-MM-DD.log
 *   LOG_MAX_FILES    daily files kept (default 14, 0 = all)
 *   LOG_LEVEL        PSR-3 threshold: debug when APP_ENV is local (or unset), info otherwise
 *   LOG_HTTP_ERRORS  which HTTP errors the exception filter logs: 5xx (default) | 4xx (client errors too) | all | none
 *   LOG_PHP_ERRORS   false: do not install the PHP error / exception / shutdown handlers (ErrorHandler)
 *
 * LoggerModule applies it while the module graph is scanned (so boot errors are logged there too); the CLI
 * applies it before running a command.
 */
final class LogConfig
{
    /** @var array<string, mixed>|null what apply() installed last */
    private static ?array $current = null;

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'default' => env('LOG_CHANNEL', 'console'),
            'level' => env('LOG_LEVEL', ConfigFile::isLocal() ? 'debug' : 'info'),
            'http_errors' => env('LOG_HTTP_ERRORS', '5xx'),
            'php_errors' => env('LOG_PHP_ERRORS', true),
            'channels' => [
                'stack' => ['driver' => 'stack', 'channels' => env('LOG_STACK', 'file,stderr')],
                'file' => [
                    'driver' => 'file',
                    'path' => env('LOG_PATH', 'storage/logs/nesto.log'),
                    'daily' => env('LOG_DAILY', false),
                    'max_files' => env('LOG_MAX_FILES', 14),
                ],
                'stderr' => ['driver' => 'stderr'],
                'stdout' => ['driver' => 'stdout'],
                'console' => ['driver' => 'console'],
                'null' => ['driver' => 'null'],
            ],
        ];
    }

    /**
     * Defaults < config/logging.php < $overrides.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function load(array $overrides = []): array
    {
        return ConfigModule::merge(ConfigFile::load('logging', self::defaults()), $overrides);
    }

    /** Install the configuration on the process-wide Logger. @param array<string, mixed> $config */
    public static function apply(array $config): void
    {
        if (self::$current === $config && Logger::channel() !== null) {
            return; // same configuration (a worker booting again): keep the open file handle
        }
        Logger::useChannel(self::channel($config));
        $level = $config['level'] ?? null;
        Logger::setMinLevel(is_string($level) && $level !== '' ? $level : null);
        self::$current = $config;
    }

    /** Forget what apply() installed (tests). */
    public static function reset(): void
    {
        self::$current = null;
    }

    /** @return array<string, mixed>|null */
    public static function current(): ?array
    {
        return self::$current;
    }

    /** LOG_HTTP_ERRORS of the applied configuration (else the environment): 5xx, 4xx, all or none. */
    public static function httpErrors(): string
    {
        $value = self::$current['http_errors'] ?? env('LOG_HTTP_ERRORS', '5xx');
        $value = strtolower(trim((string) ($value === false ? 'none' : ($value === true ? 'all' : $value))));

        return in_array($value, ['5xx', '4xx', 'all', 'none'], true) ? $value : '5xx';
    }

    /** Should the exception filter log an HTTP error with this status? */
    public static function shouldLogHttpStatus(int $status): bool
    {
        return match (self::httpErrors()) {
            'none' => false,
            'all' => true,
            '4xx' => $status >= 400,
            default => $status >= 500,
        };
    }

    /** LOG_PHP_ERRORS: install ErrorHandler (PHP warnings, uncaught exceptions, fatals → the log)? */
    public static function phpErrors(): bool
    {
        return ConfigFile::bool(self::$current['php_errors'] ?? env('LOG_PHP_ERRORS', true), true);
    }

    /** Build the channel named by `default` (or $name). @param array<string, mixed> $config */
    public static function channel(array $config, ?string $name = null, int $depth = 0): LogChannel
    {
        $name ??= (string) ($config['default'] ?? 'console');
        $channels = (array) ($config['channels'] ?? []);
        $spec = $channels[$name] ?? (in_array($name, ['file', 'stderr', 'stdout', 'console', 'null', 'stack'], true) ? ['driver' => $name] : null);
        if (! is_array($spec)) {
            throw new \InvalidArgumentException("Logging: unknown channel \"$name\" (configure it in config/logging.php or use file, stderr, stdout, stack, console or null).");
        }
        $driver = (string) ($spec['driver'] ?? $name);

        return match ($driver) {
            'file', 'single', 'daily' => new FileChannel(
                Paths::resolve((string) ($spec['path'] ?? 'storage/logs/nesto.log')),
                $driver === 'daily' || ConfigFile::bool($spec['daily'] ?? false),
                (int) ($spec['max_files'] ?? $spec['days'] ?? 14),
                isset($spec['permission']) ? (int) $spec['permission'] : null,
                ConfigFile::bool($spec['locking'] ?? true, true),
            ),
            'stderr' => StreamChannel::stderr(),
            'stdout' => StreamChannel::stdout(),
            'console' => StreamChannel::console(),
            'null' => new StackChannel([]),
            'stack' => $depth > 3
                ? throw new \InvalidArgumentException('Logging: stack channels nest too deeply.')
                : new StackChannel(array_map(
                    fn (string $child) => self::channel($config, $child, $depth + 1),
                    array_values(array_filter(ConfigFile::list($spec['channels'] ?? 'file'), fn (string $c) => $c !== $name)),
                )),
            default => throw new \InvalidArgumentException("Logging: unknown driver \"$driver\" for channel \"$name\" (file, stderr, stdout, stack, console, null)."),
        };
    }

    /** One line for `php nesto info`: "stack (file storage/logs/nesto.log, stderr), level debug". @param array<string, mixed> $config */
    public static function describe(array $config): string
    {
        $describe = function (LogChannel $channel) use (&$describe): string {
            return match (true) {
                $channel instanceof FileChannel => 'file ' . self::relative($channel->currentPath()) . ($channel->isDaily() ? ' (daily, ' . $channel->maxFiles() . ' kept)' : ''),
                $channel instanceof StreamChannel => is_string($channel->target()) ? $channel->target() : 'stream',
                $channel instanceof StackChannel => $channel->channels() === [] ? 'null' : implode(', ', array_map($describe, $channel->channels())),
                default => $channel::class,
            };
        };
        $channel = self::channel($config);
        $name = (string) ($config['default'] ?? 'console');
        $text = $channel instanceof StackChannel && $channel->channels() !== [] ? "$name (" . $describe($channel) . ')' : $describe($channel);

        return $text . ', level ' . ($config['level'] ?? 'debug');
    }

    private static function relative(string $path): string
    {
        $base = rtrim(Paths::base(), '/') . '/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
