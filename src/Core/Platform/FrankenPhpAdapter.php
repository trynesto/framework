<?php

declare(strict_types=1);

namespace Nesto\Core\Platform;

use Nesto\Core\NestoApplication;
use Nesto\Http\Request;
use Nesto\Http\ResponseEmitter;

/**
 * FrankenPHP worker mode: the app boots once per worker and stays in memory (container singletons, config,
 * database connection), like a Node process running Nest. Code changes apply only after a restart.
 *
 * Nothing to change in public/index.php: `$app->listen()` detects the worker and enters the request loop.
 * Start it with `php nesto start:prod` (or `frankenphp php-server --root public --worker public/index.php`).
 *
 * MAX_REQUESTS (env) recycles a worker after that many requests; FrankenPHP starts a fresh one.
 */
final class FrankenPhpAdapter
{
    /** True only in worker mode; frankenphp_handle_request() also exists in classic mode, so it can't be the test. */
    public static function isWorker(): bool
    {
        return function_exists('frankenphp_handle_request') && ! empty($_SERVER['FRANKENPHP_WORKER']);
    }

    public static function run(NestoApplication $app, ?int $maxRequests = null): void
    {
        if (! self::isWorker()) {
            $app->listen();

            return;
        }

        self::serve($app, $maxRequests);
    }

    /** The worker loop; called by NestoApplication::listen() when running under a FrankenPHP worker. */
    public static function serve(NestoApplication $app, ?int $maxRequests = null): void
    {
        ignore_user_abort(true); // a client hanging up must not kill the worker mid-request
        $maxRequests ??= (int) ($_SERVER['MAX_REQUESTS'] ?? getenv('MAX_REQUESTS') ?: 0);

        try {
            $app->init();
        } catch (\Throwable $e) {
            BootErrorHandler::report($e);
            self::serveBootError($e, isset($app->options()['debug']) ? (bool) $app->options()['debug'] : null, $maxRequests);

            return;
        }
        $emitter = new ResponseEmitter;
        $handler = static function () use ($app, $emitter): void {
            $emitter->emit($app->handle(Request::fromGlobals()));
        };

        for ($handled = 0; $maxRequests <= 0 || $handled < $maxRequests; $handled++) {
            $keepRunning = \frankenphp_handle_request($handler);
            gc_collect_cycles();
            if (! $keepRunning) {
                break;
            }
        }

        $app->close('worker-exit');
    }

    /**
     * The app failed to boot: keep the worker alive and answer every request with the JSON 500 of
     * BootErrorHandler, instead of exiting and having FrankenPHP restart the worker in a loop.
     */
    public static function serveBootError(\Throwable $error, ?bool $debug = null, ?int $maxRequests = null): void
    {
        ignore_user_abort(true);
        $maxRequests ??= (int) ($_SERVER['MAX_REQUESTS'] ?? getenv('MAX_REQUESTS') ?: 0);
        $handler = static function () use ($error, $debug): void {
            (new ResponseEmitter)->emit(BootErrorHandler::response($error, $debug));
        };
        for ($handled = 0; $maxRequests <= 0 || $handled < $maxRequests; $handled++) {
            if (! \frankenphp_handle_request($handler)) {
                break;
            }
        }
    }
}
