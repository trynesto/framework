<?php

declare(strict_types=1);

namespace Nesto\Logger;

use Nesto\Common\Provider;
use Nesto\Core\Container;
use Nesto\Logger\Channels\FileChannel;
use Nesto\Logger\Channels\LogChannel;
use Nesto\Logger\Channels\StreamChannel;
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
 * Configuration (process-wide, like Nest's static Logger) — `new LoggerModule()` does it from config/logging.php
 * and LOG_CHANNEL / LOG_PATH / LOG_LEVEL / LOG_DAILY (see LogConfig):
 *   Logger::useChannel(new FileChannel(storage_path('logs/nesto.log'), daily: true));   // or StreamChannel / StackChannel
 *   Logger::toFile(__DIR__ . '/storage/app.log');          // shorthand for a FileChannel
 *   Logger::setMinLevel('info');                           // PSR-3 threshold: drop debug everywhere
 *   Logger::setDefaultLogLevels(['log', 'error', 'warn']); // or an explicit list of Nest levels
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

    /** PSR-3 severity of every level name (Nest names included), lowest first. */
    public const SEVERITY = [
        'verbose' => 0, LogLevel::DEBUG => 0,
        LogLevel::INFO => 1, 'log' => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3, 'warn' => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5, 'fatal' => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    private static ?LoggerInterface $override = null;
    /** @var list<string>|null null = everything enabled */
    private static ?array $defaultLevels = null;
    /** Minimum PSR-3 severity (null = everything). */
    private static ?int $minSeverity = null;
    /** @var resource|null set by toStream(): wins over the channel (tests capture output this way) */
    private static $stream = null;
    private static ?LogChannel $channel = null;
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

    /** Append all output to a file (created if missing): a FileChannel. */
    public static function toFile(string $path, bool $daily = false, int $maxFiles = 14): void
    {
        self::$stream = null;
        self::useChannel(new FileChannel($path, $daily, $maxFiles));
    }

    /**
     * Write to any stream resource (php://memory in tests): it wins over the channel while set.
     * null restores the default (no channel: errors on STDERR, the rest on STDOUT).
     *
     * @param resource|null $stream
     */
    public static function toStream($stream): void
    {
        self::$stream = $stream;
        if ($stream === null) {
            self::useChannel(null);
        }
    }

    /** Where every Logger writes (null: StreamChannel::console(), errors on STDERR and the rest on STDOUT). */
    public static function useChannel(?LogChannel $channel): void
    {
        if (self::$channel !== null && self::$channel !== $channel) {
            self::$channel->close();
        }
        self::$channel = $channel;
    }

    public static function channel(): ?LogChannel
    {
        return self::$channel;
    }

    /** PSR-3 threshold for every Logger: 'info' drops debug/verbose (null: everything). */
    public static function setMinLevel(?string $level): void
    {
        if ($level === null || $level === '') {
            self::$minSeverity = null;

            return;
        }
        $severity = self::SEVERITY[strtolower($level)] ?? null;
        if ($severity === null) {
            throw new \InvalidArgumentException("Logger: unknown log level \"$level\" (debug, info, notice, warning, error, critical, alert, emergency).");
        }
        self::$minSeverity = $severity;
    }

    public static function minLevel(): ?string
    {
        if (self::$minSeverity === null) {
            return null;
        }

        return [LogLevel::DEBUG, LogLevel::INFO, LogLevel::NOTICE, LogLevel::WARNING, LogLevel::ERROR, LogLevel::CRITICAL, LogLevel::ALERT, LogLevel::EMERGENCY][self::$minSeverity];
    }

    /** The colour setting of useColors() (null: auto-detect). */
    public static function colorSetting(): ?bool
    {
        return self::$colors;
    }

    /** Forget every static setting (channel, stream, levels, override, colours, app name). */
    public static function reset(): void
    {
        self::$override = null;
        self::$defaultLevels = null;
        self::$minSeverity = null;
        self::$stream = null;
        self::useChannel(null);
        self::$colors = null;
        self::$appName = 'Nesto';
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

        if (! $this->isEnabled($nestLevel) || (self::$minSeverity !== null && (self::SEVERITY[$level] ?? 1) < self::$minSeverity)) {
            return;
        }

        if (self::$stream !== null) {
            fwrite(self::$stream, $this->format($nestLevel, (string) $message, $context) . PHP_EOL);

            return;
        }
        $plain = $this->format($nestLevel, (string) $message, $context, false);
        $colored = self::$colors === false ? $plain : $this->format($nestLevel, (string) $message, $context, true);
        try {
            (self::$channel ?? StreamChannel::console())->write($nestLevel, $plain, $colored);
        } catch (\Throwable $e) {
            // an unwritable log must not turn into an error of its own: report it once on STDERR, keep going
            StreamChannel::stderr()->write('error', 'Logger: ' . $e->getMessage() . "\n" . $plain, 'Logger: ' . $e->getMessage() . "\n" . $colored);
        }
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
