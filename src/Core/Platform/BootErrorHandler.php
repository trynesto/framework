<?php

declare(strict_types=1);

namespace Nesto\Core\Platform;

use Nesto\Http\Response;
use Nesto\Http\ResponseEmitter;
use Nesto\Logger\Logger;
use Psr\Http\Message\ResponseInterface;

/**
 * Errors thrown while the app boots (NestoFactory::create(), init(), Swagger setup, listen()), e.g. an
 * UnknownDependencyException: logged with the stack trace on STDERR, like Nest's "[ExceptionHandler]", and answered
 * with a JSON 500 instead of PHP's HTML fatal-error page:
 *
 *   { "statusCode": 500, "message": "Internal server error", "error": "Internal Server Error" }
 *
 * The real message is in "message" only in debug mode. Under a FrankenPHP worker the worker stays up and answers
 * every request with that 500 (instead of exiting and being restarted in a loop) until it is restarted.
 */
final class BootErrorHandler
{
    private static bool $registered = false;
    private static ?bool $debug = null;

    /** Called by NestoFactory::create(): catches uncaught boot errors on web SAPIs (php -S, FPM, FrankenPHP). */
    public static function register(?bool $debug = null): void
    {
        self::$debug = $debug;
        if (self::$registered || in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
            return;
        }
        self::$registered = true;
        set_exception_handler(static fn (\Throwable $e) => self::handle($e));
    }

    /** Log the error, then answer the current request (or, in a FrankenPHP worker, every request) with a JSON 500. */
    public static function handle(\Throwable $e, ?bool $debug = null): void
    {
        $debug ??= self::$debug;
        self::report($e);
        if (FrankenPhpAdapter::isWorker()) {
            FrankenPhpAdapter::serveBootError($e, $debug);

            return;
        }
        (new ResponseEmitter)->emit(self::response($e, $debug));
    }

    public static function report(\Throwable $e): void
    {
        (new Logger('ExceptionHandler'))->error($e->getMessage(), ['exception' => $e]);
    }

    /** @param bool|null $debug null = the APP_DEBUG environment variable */
    public static function response(\Throwable $e, ?bool $debug = null): ResponseInterface
    {
        $debug ??= (bool) env('APP_DEBUG', false);

        return Response::json([
            'statusCode' => 500,
            'message' => $debug ? $e->getMessage() : 'Internal server error',
            'error' => 'Internal Server Error',
        ], 500);
    }
}
