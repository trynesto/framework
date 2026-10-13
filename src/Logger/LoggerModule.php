<?php

declare(strict_types=1);

namespace Nesto\Logger;

use Nesto\Common\Contracts\DynamicModuleFactory;
use Nesto\Common\DynamicModule;
use Nesto\Common\Provider;
use Psr\Log\LoggerInterface;

/**
 * Makes `Psr\Log\LoggerInterface` (and `Nesto\Logger\Logger`) injectable everywhere, and points every Logger of
 * the process — the framework's own included (500s, boot errors, queue and schedule failures, PHP warnings) —
 * at the channel of config/logging.php / .env (LOG_CHANNEL, LOG_PATH, LOG_LEVEL, LOG_DAILY, see LogConfig):
 *
 *   #[Module(imports: [new ConfigModule(isGlobal: true), new LoggerModule()])]   // config/logging.php + .env
 *   #[Module(imports: [new LoggerModule(context: 'App', channel: 'stderr', level: 'info')])]   // explicit wins
 *
 *   final class CatsService {
 *       public function __construct(private LoggerInterface $logger) {}
 *   }
 *
 * The configuration is applied while the module graph is scanned, so put the module right after ConfigModule.
 * Without a config file nor LOG_* variables the output stays on the console (errors on STDERR).
 * Or without a module: `LogConfig::apply(LogConfig::load())` and `Logger::register($app->container())`.
 */
final class LoggerModule implements DynamicModuleFactory
{
    /** DI token of the effective logging configuration (array). */
    public const CONFIG = 'LOGGER_MODULE_CONFIG';

    /**
     * @param list<string>|null $levels   Nest level names to enable for the injected logger (null = all)
     * @param ?string           $channel  overrides LOG_CHANNEL (file, stderr, stdout, stack, console, null)
     * @param ?string           $path     overrides LOG_PATH
     * @param ?string           $level    overrides LOG_LEVEL (PSR-3 threshold for every Logger)
     * @param ?bool             $daily    overrides LOG_DAILY
     * @param ?int              $maxFiles overrides LOG_MAX_FILES
     * @param bool              $configure false: only provide the logger, leave the process-wide output alone
     */
    public function __construct(
        private readonly ?string $context = null,
        private readonly ?array $levels = null,
        private readonly bool $global = true,
        private readonly ?string $channel = null,
        private readonly ?string $path = null,
        private readonly ?string $level = null,
        private readonly ?bool $daily = null,
        private readonly ?int $maxFiles = null,
        private readonly bool $configure = true,
    ) {
    }

    /** @param array{context?: ?string, levels?: ?array, global?: bool, channel?: ?string, path?: ?string, level?: ?string, daily?: ?bool, maxFiles?: ?int, configure?: bool} $options */
    public static function forRoot(array $options = []): DynamicModule
    {
        return (new self(
            context: $options['context'] ?? null,
            levels: $options['levels'] ?? null,
            global: $options['global'] ?? true,
            channel: $options['channel'] ?? null,
            path: $options['path'] ?? null,
            level: $options['level'] ?? null,
            daily: $options['daily'] ?? null,
            maxFiles: $options['maxFiles'] ?? null,
            configure: $options['configure'] ?? true,
        ))->toDynamicModule();
    }

    /** The effective configuration: config/logging.php + .env, then this module's explicit arguments. @return array<string, mixed> */
    public function config(): array
    {
        $overrides = array_filter(['default' => $this->channel, 'level' => $this->level], fn ($v) => $v !== null);
        $file = array_filter(['path' => $this->path, 'daily' => $this->daily, 'max_files' => $this->maxFiles], fn ($v) => $v !== null);
        if ($file !== []) {
            $overrides['channels']['file'] = $file;
        }

        return LogConfig::load($overrides);
    }

    public function toDynamicModule(): DynamicModule
    {
        $config = $this->config();
        if ($this->configure) {
            LogConfig::apply($config);
        }
        $context = $this->context;
        $levels = $this->levels;

        return new DynamicModule(
            module: self::class,
            providers: [
                (new Provider(Logger::class))->useFactory(function () use ($context, $levels): Logger {
                    $logger = new Logger($context);
                    if ($levels !== null) {
                        $logger->setLogLevels($levels);
                    }

                    return $logger;
                }),
                (new Provider(LoggerInterface::class))->useExisting(Logger::class),
                (new Provider(self::CONFIG))->useValue($config),
            ],
            exports: [Logger::class, LoggerInterface::class, self::CONFIG],
            global: $this->global,
        );
    }
}
