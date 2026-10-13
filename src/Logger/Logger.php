<?php

declare(strict_types=1);

namespace Nesto\Logger;

use Nesto\Common\Provider;
use Nesto\Core\Container;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;

/**
 * Nest-style console logger, PSR-3 compatible.
 *
 *   $logger = new Logger('CatsService');          // like `new Logger(CatsService.name)` in Nest
 *   $logger->info('Cat created');                // Nest's `log()` level → "LOG"
 *   $logger->error('Boom', ['exception' => $e]);
 *   $logger->warning('Careful');  $logger->debug('x');  $logger->verbose('y');  $logger->fatal('z');
 *
 * Output (STDOUT, errors/fatal on STDERR), coloured when attached to a TTY:
 *   [Nesto] 12345  - 10/12/2026, 7:48:22 PM     LOG [CatsService] Cat created
 *
 * Configuration (process-wide, like Nest's static Logger):
 *   Logger::toFile(__DIR__ . '/storage/app.log');          // write everything to a file instead
 *   Logger::setDefaultLogLevels(['log', 'error', 'warn']); // drop debug/verbose everywhere
 *   Logger::overrideLogger($monolog);                       // forward every message to a custom PSR-3 logger
 *   Logger::register($container);                           // expose Psr\Log\LoggerInterface to DI
 *
 * Per-instance: `$logger->setLogLevels(['error'])`, `$logger->setContext('Other')`.
 *
 * PSR-3 `{placeholders}` in messages are interpolated from the context array; a `exception` key
 * is printed with its class, message and trace (like Nest's stack argument).
 */
final class Logger implements LoggerInterface
{
    use LoggerTrait;

    /** Nest level names, in order of severity. */
    public const LEVELS = ['fatal', 'error', 'warn', 'log', 'debug', 'verbose'];

    private const COLORS = [
        'fatal' => "\033[1;31m",
        'error' => "\033[31m",
        'warn' => "\033[33m",
        'log' => "\033[32m",
        'debug' => "\033[35m",
        'verbose' => "\033[36m",
    ];
    private const RESET = "\033[0m";
    private const YELLOW = "\033[33m";
    private const WHITE = "\033[37m";

    private static ?LoggerInterface $override = null;
    /** @var list<string>|null null = everything enabled */
    private static ?array $defaultLevels = null;
    /** @var resource|null */
    private static $stream = null;
    private static ?bool $colors = null;
    private static string $appName = 'Nesto';

    /** @var list<string>|null */
    private ?array $levels = null;

    public function __construct(private ?string $context = null)
    {
    }

    // ---- static configuration -------------------------------------------------------

    /** Forward every message of every Logger instance to a custom PSR-3 logger (null restores the default). */
    public static function overrideLogger(?LoggerInterface $logger): void
    {
        self::$override = $logger instanceof self ? null : $logger;
    }

    /** Append all output to a file (created if missing). */
    public static function toFile(string $path): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $handle = fopen($path, 'ab');
        if ($handle === false) {
            throw new \RuntimeException("Logger: cannot open \"$path\" for writing.");
        }
        self::$stream = $handle;
    }

    /** Write to any stream resource (php://memory in tests), or null to restore STDOUT/STDERR. @param resource|null $stream */
    public static function toStream($stream): void
    {
        self::$stream = $stream;
    }

    /** Force ANSI colours on/off; null = auto-detect (TTY only). */
    public static function useColors(?bool $enabled): void
    {
        self::$colors = $enabled;
    }

    /** @param list<string>|null $levels Nest level names: log, error, warn, debug, verbose, fatal */
    public static function setDefaultLogLevels(?array $levels): void
    {
        self::$defaultLevels = $levels === null ? null : array_values(array_map(self::normalizeLevel(...), $levels));
    }

    public static function setAppName(string $name): void
    {
        self::$appName = $name;
    }

    /** Register Psr\Log\LoggerInterface + Logger as core providers visible from every module. */
    public static function register(Container $container, ?self $logger = null): self
    {
        $logger ??= new self;
        $container->core((new Provider(LoggerInterface::class))->useValue($logger));
        $container->core((new Provider(self::class))->useValue($logger));

        return $logger;
    }

    // ---- instance configuration -----------------------------------------------------

    /** @param list<string> $levels */
    public function setLogLevels(array $levels): self
    {
        $this->levels = array_values(array_map(self::normalizeLevel(...), $levels));

        return $this;
    }

    public function setContext(?string $context): self
    {
        $this->context = $context;

        return $this;
    }

    public function getContext(): ?string
    {
        return $this->context;
    }

    // ---- PSR-3 --------------------------------------------------------------------------

    /** @param mixed $level */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $level = is_string($level) ? strtolower($level) : (string) $level;
        $nestLevel = self::toNestLevel($level);

        if (self::$override !== null) {
            $ctx = $context;
            if ($this->context !== null && ! isset($ctx['context'])) {
                $ctx['context'] = $this->context;
            }
            self::$override->log(self::toPsrLevel($nestLevel), $message, $ctx);

            return;
        }

        if (! $this->isEnabled($nestLevel)) {
            return;
        }

        $line = $this->format($nestLevel, (string) $message, $context);
        $this->write($line, in_array($nestLevel, ['error', 'fatal'], true));
    }

    public function verbose(string|\Stringable $message, array $context = []): void
    {
        $this->log('verbose', $message, $context);
    }

    public function fatal(string|\Stringable $message, array $context = []): void
    {
        $this->log('fatal', $message, $context);
    }

    /** Alias of warning() (Nest naming). */
    public function warn(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function isEnabled(string $level): bool
    {
        $level = self::normalizeLevel($level);
        $enabled = $this->levels ?? self::$defaultLevels;

        return $enabled === null || in_array($level, $enabled, true);
    }

    /** Build the Nest-style line (without trailing newline). */
    public function format(string $level, string $message, array $context = [], ?bool $colors = null): string
    {
        $level = self::normalizeLevel($level);
        $colors ??= $this->colorsEnabled();
        $ctxName = $context['context'] ?? $this->context;
        $message = $this->interpolate($message, $context);
        $color = self::COLORS[$level] ?? '';
        $c = fn (string $text, string $code) => $colors ? $code . $text . self::RESET : $text;

        $line = $c('[' . self::$appName . '] ' . getmypid() . '  - ', $color)
            . $c(date('n/j/Y, g:i:s A'), self::WHITE)
            . ' '
            . $c(str_pad(strtoupper($level), 7, ' ', STR_PAD_LEFT), $color)
            . ' '
            . ($ctxName !== null ? $c('[' . $ctxName . '] ', self::YELLOW) : '')
            . $c($message, $color);

        $e = $context['exception'] ?? null;
        if ($e instanceof \Throwable) {
            $line .= "\n" . $c($e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString(), $color);
        }

        return $line;
    }

    // ---- internals ---------------------------------------------------------------------------

    private function write(string $line, bool $isError): void
    {
        if (self::$stream !== null) {
            fwrite(self::$stream, $line . PHP_EOL);

            return;
        }
        $target = $isError ? 'php://stderr' : 'php://stdout';
        $handle = fopen($target, 'wb');
        if ($handle !== false) {
            fwrite($handle, $line . PHP_EOL);
            fclose($handle);
        }
    }

    private function colorsEnabled(): bool
    {
        if (self::$colors !== null) {
            return self::$colors;
        }
        if (self::$stream !== null) {
            return false;
        }

        return defined('STDOUT') && function_exists('posix_isatty') && @posix_isatty(STDOUT);
    }

    private function interpolate(string $message, array $context): string
    {
        if (! str_contains($message, '{')) {
            return $message;
        }
        $replace = [];
        foreach ($context as $key => $val) {
            if (is_scalar($val) || $val === null || $val instanceof \Stringable) {
                $replace['{' . $key . '}'] = (string) $val;
            }
        }

        return strtr($message, $replace);
    }

    /** PSR-3 level → Nest level. */
    public static function toNestLevel(string $level): string
    {
        return match (strtolower($level)) {
            LogLevel::EMERGENCY, LogLevel::ALERT, LogLevel::CRITICAL, 'fatal' => 'fatal',
            LogLevel::ERROR => 'error',
            LogLevel::WARNING, 'warn' => 'warn',
            LogLevel::NOTICE, LogLevel::INFO, 'log' => 'log',
            LogLevel::DEBUG => 'debug',
            'verbose' => 'verbose',
            default => 'log',
        };
    }

    /** Nest level → PSR-3 level (for overrideLogger forwarding). */
    public static function toPsrLevel(string $level): string
    {
        return match ($level) {
            'fatal' => LogLevel::CRITICAL,
            'error' => LogLevel::ERROR,
            'warn' => LogLevel::WARNING,
            'debug', 'verbose' => LogLevel::DEBUG,
            default => LogLevel::INFO,
        };
    }

    private static function normalizeLevel(string $level): string
    {
        return self::toNestLevel($level);
    }
}
