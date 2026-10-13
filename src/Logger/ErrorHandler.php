<?php

declare(strict_types=1);

namespace Nesto\Logger;

use Psr\Log\LogLevel;

/**
 * Sends what PHP itself reports to the configured log (LOG_CHANNEL): warnings, notices and deprecations
 * (set_error_handler), uncaught exceptions (set_exception_handler) and fatal errors (register_shutdown_function).
 * Installed by NestoFactory::create() and the console (`php nesto`); LOG_PHP_ERRORS=false opts out.
 *
 * Nothing is swallowed: error_reporting() is respected, every error then goes on to the previous handler (or
 * PHP's own, which displays / logs it as configured), and an uncaught exception still ends the script like PHP
 * would — "PHP Fatal error:  Uncaught ...", HTTP 500 on web SAPIs, exit code 255. During an HTTP request the
 * framework's exception filter (and BootErrorHandler while booting) handle exceptions before they get here.
 *
 *   ErrorHandler::register();      // idempotent; skipped under PHPUnit unless forced
 *   ErrorHandler::unregister();    // restore the previous handlers
 */
final class ErrorHandler
{
    private const FATAL = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;

    private static bool $registered = false;
    private static bool $shutdownRegistered = false;
    private static bool $reporting = false;
    /** @var callable|null */
    private static $previousErrorHandler = null;
    /** @var callable|null */
    private static $previousExceptionHandler = null;
    private static ?\Throwable $uncaught = null;
    /** Freed on shutdown so a memory-exhausted script can still log its fatal error. */
    private static ?string $reserved = null;
    /** @var (\Closure(int): never)|null tests replace exit() */
    private static ?\Closure $terminate = null;

    /** @return bool true when the handlers were installed by this call */
    public static function register(bool $force = false): bool
    {
        if (self::$registered) {
            return false;
        }
        if (! $force && (self::runningTests() || ! LogConfig::phpErrors())) {
            return false;
        }
        self::$registered = true;
        self::$reserved ??= str_repeat('x', 32 * 1024);
        self::$previousErrorHandler = set_error_handler(self::handleError(...));
        self::$previousExceptionHandler = set_exception_handler(self::handleException(...));
        if (! self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function(self::handleShutdown(...));
        }

        return true;
    }

    public static function unregister(): void
    {
        if (! self::$registered) {
            return;
        }
        restore_error_handler();
        restore_exception_handler();
        self::$registered = false;
        self::$previousErrorHandler = null;
        self::$previousExceptionHandler = null;
    }

    public static function isRegistered(): bool
    {
        return self::$registered;
    }

    /** @param (\Closure(int): never)|null $terminate replaces exit() (tests) */
    public static function terminateWith(?\Closure $terminate): void
    {
        self::$terminate = $terminate;
    }

    /** set_error_handler(): log the error at its level, then let the previous / PHP's handler do its job. */
    public static function handleError(int $errno, string $message, string $file = '', int $line = 0): bool
    {
        if ((error_reporting() & $errno) !== 0 && ! self::$reporting) {
            self::$reporting = true;
            try {
                (new Logger('PHP'))->log(self::level($errno), sprintf('PHP %s:  %s in %s:%d', self::label($errno), $message, $file, $line));
            } catch (\Throwable) {
                // never turn a warning into a logging failure
            } finally {
                self::$reporting = false;
            }
        }
        if (self::$previousErrorHandler !== null) {
            return (bool) (self::$previousErrorHandler)($errno, $message, $file, $line);
        }

        return false; // PHP's own handler: display_errors / log_errors as configured
    }

    /** set_exception_handler(): log, then end the script like an uncaught exception does. */
    public static function handleException(\Throwable $e): void
    {
        self::$uncaught = $e;
        try {
            (new Logger('PHP'))->critical('Uncaught ' . $e::class . ': ' . $e->getMessage(), ['exception' => $e]);
        } catch (\Throwable) {
        }
        if (self::$previousExceptionHandler !== null) {
            (self::$previousExceptionHandler)($e);

            return;
        }
        $text = 'Uncaught ' . $e . "\n  thrown in " . $e->getFile() . ' on line ' . $e->getLine();
        if (! in_array(PHP_SAPI, ['cli', 'phpdbg'], true) && ! headers_sent()) {
            http_response_code(500);
        }
        $display = strtolower((string) ini_get('display_errors'));
        if (in_array($display, ['1', 'on', 'true', 'yes', 'stdout', 'stderr'], true)) {
            $out = PHP_SAPI === 'cli' ? "PHP Fatal error:  $text\n" : "<br />\n<b>Fatal error</b>:  " . htmlspecialchars($text) . "<br />\n";
            if ($display === 'stderr' && defined('STDERR')) {
                fwrite(STDERR, $out);
            } else {
                echo $out;
            }
        }
        if (ini_get('log_errors')) {
            error_log("PHP Fatal error:  $text");
        }
        (self::$terminate ?? static fn (int $code) => exit($code))(255);
    }

    /** register_shutdown_function(): a fatal error (out of memory, parse error in an include…) still reaches the log. */
    public static function handleShutdown(): void
    {
        self::$reserved = null;
        if (! self::$registered) {
            return;
        }
        $error = error_get_last();
        if ($error === null || ($error['type'] & self::FATAL) === 0) {
            return;
        }
        if (self::$uncaught !== null && str_starts_with($error['message'], 'Uncaught ')) {
            return; // already logged by handleException()
        }
        try {
            (new Logger('PHP'))->critical(sprintf('PHP Fatal error:  %s in %s:%d', $error['message'], $error['file'], $error['line']));
        } catch (\Throwable) {
        }
    }

    public static function level(int $errno): string
    {
        return match ($errno) {
            E_WARNING, E_USER_WARNING, E_CORE_WARNING, E_COMPILE_WARNING => LogLevel::WARNING,
            E_NOTICE, E_USER_NOTICE => LogLevel::NOTICE,
            E_DEPRECATED, E_USER_DEPRECATED => LogLevel::INFO,
            E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR => LogLevel::CRITICAL,
            default => LogLevel::ERROR, // E_USER_ERROR, E_RECOVERABLE_ERROR
        };
    }

    public static function label(int $errno): string
    {
        return match ($errno) {
            E_WARNING, E_USER_WARNING, E_CORE_WARNING, E_COMPILE_WARNING => 'Warning',
            E_NOTICE, E_USER_NOTICE => 'Notice',
            E_DEPRECATED, E_USER_DEPRECATED => 'Deprecated',
            E_RECOVERABLE_ERROR => 'Recoverable fatal error',
            default => 'Fatal error',
        };
    }

    private static function runningTests(): bool
    {
        return defined('PHPUNIT_COMPOSER_INSTALL') || defined('__PHPUNIT_PHAR__');
    }
}
