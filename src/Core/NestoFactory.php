<?php

declare(strict_types=1);

namespace Nesto\Core;

use Nesto\Common\DynamicModule;
use Nesto\Core\Platform\BootErrorHandler;
use Nesto\Logger\ErrorHandler;

/**
 *   $app = NestoFactory::create(AppModule::class, ['debug' => true]);
 *   $app->setGlobalPrefix('api')->enableCors();
 *   $app->listen();
 */
final class NestoFactory
{
    /**
     * @param array{debug?: bool, poweredBy?: string|false} $options
     *        debug: expose exception details in 500 responses (default: the APP_DEBUG env var);
     *        poweredBy: the X-Powered-By header ('Nesto' by default, false to send none)
     */
    public static function create(string|DynamicModule $rootModule, array $options = []): NestoApplication
    {
        // web SAPIs: an error thrown while booting becomes a logged JSON 500 instead of PHP's HTML error page
        BootErrorHandler::register(isset($options['debug']) ? (bool) $options['debug'] : null);
        Paths::detectFromModule($rootModule); // before scanning: ConfigModule reads .env relative to the project
        $container = new Container;
        $root = (new ModuleScanner($container))->scan($rootModule);
        // PHP warnings, uncaught exceptions and fatal errors go to the configured log too (LOG_PHP_ERRORS; after the
        // scan, so .env and LoggerModule's channel are in place)
        ErrorHandler::register();

        return new NestoApplication($container, $root, new Reflector, $options);
    }

    /** A DI context without HTTP (CLI scripts, workers) — Nest's createApplicationContext(). */
    public static function createApplicationContext(string|DynamicModule $rootModule, array $options = []): NestoApplication
    {
        return self::create($rootModule, $options);
    }
}
