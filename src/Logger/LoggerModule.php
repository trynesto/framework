<?php

declare(strict_types=1);

namespace Nesto\Logger;

use Nesto\Common\Contracts\DynamicModuleFactory;
use Nesto\Common\DynamicModule;
use Nesto\Common\Provider;
use Psr\Log\LoggerInterface;

/**
 * Makes `Psr\Log\LoggerInterface` (and `Nesto\Logger\Logger`) injectable everywhere.
 *
 *   #[Module(imports: [new LoggerModule(context: 'App')])]
 *   final class AppModule {}
 *
 *   final class CatsService {
 *       public function __construct(private LoggerInterface $logger) {}
 *   }
 *
 * Or without a module: `Logger::register($app->container())`.
 */
final class LoggerModule implements DynamicModuleFactory
{
    /** @param list<string>|null $levels Nest level names to enable (null = all) */
    public function __construct(
        private readonly ?string $context = null,
        private readonly ?array $levels = null,
        private readonly bool $global = true,
    ) {
    }

    /** @param array{context?: ?string, levels?: ?array, global?: bool} $options */
    public static function forRoot(array $options = []): DynamicModule
    {
        return (new self(
            context: $options['context'] ?? null,
            levels: $options['levels'] ?? null,
            global: $options['global'] ?? true,
        ))->toDynamicModule();
    }

    public function toDynamicModule(): DynamicModule
    {
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
            ],
            exports: [Logger::class, LoggerInterface::class],
            global: $this->global,
        );
    }
}
