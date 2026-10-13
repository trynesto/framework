<?php

declare(strict_types=1);

namespace Nesto\Tests\Logger;

use Nesto\Logger\ErrorHandler;
use Nesto\Logger\Logger;
use Nesto\Tests\Support\ProjectSandbox;
use PHPUnit\Framework\TestCase;

/** PHP warnings / notices / deprecations, uncaught exceptions and fatal errors reach the configured log. */
final class ErrorHandlerTest extends TestCase
{
    use ProjectSandbox;

    /** @var resource */
    private $stream;

    private int $errorReporting;

    protected function setUp(): void
    {
        $this->errorReporting = error_reporting(E_ALL);
        $this->stream = fopen('php://memory', 'w+b');
        Logger::toStream($this->stream);
    }

    protected function tearDown(): void
    {
        error_reporting($this->errorReporting);
        ErrorHandler::terminateWith(null);
        $this->cleanSandbox();
    }

    private function captured(): string
    {
        rewind($this->stream);

        return (string) stream_get_contents($this->stream);
    }

    public function testWarningsNoticesAndDeprecationsAreLoggedThenLeftToPhp(): void
    {
        self::assertFalse(ErrorHandler::handleError(E_WARNING, 'Undefined variable $x', '/app/src/A.php', 12), 'PHP\'s own handler still runs');
        ErrorHandler::handleError(E_USER_NOTICE, 'a notice', '/app/src/B.php', 3);
        ErrorHandler::handleError(E_USER_DEPRECATED, 'old api', '/app/src/C.php', 7);

        $log = $this->captured();
        self::assertStringContainsString('WARN [PHP] PHP Warning:  Undefined variable $x in /app/src/A.php:12', $log);
        self::assertStringContainsString('[PHP] PHP Notice:  a notice in /app/src/B.php:3', $log);
        self::assertStringContainsString('[PHP] PHP Deprecated:  old api in /app/src/C.php:7', $log);
    }

    public function testErrorReportingIsRespected(): void
    {
        $previous = error_reporting(E_ALL & ~E_USER_DEPRECATED);
        try {
            ErrorHandler::handleError(E_USER_DEPRECATED, 'muted', __FILE__, 1);
        } finally {
            error_reporting($previous);
        }

        self::assertSame('', $this->captured());
    }

    public function testUncaughtExceptionIsLoggedAndEndsTheScript(): void
    {
        $code = null;
        ErrorHandler::terminateWith(function (int $exit) use (&$code): void {
            $code = $exit;
        });
        $display = ini_set('display_errors', '0');
        $logErrors = ini_set('log_errors', '0');
        try {
            ErrorHandler::handleException(new \DomainException('nobody caught me'));
        } finally {
            ini_set('display_errors', (string) $display);
            ini_set('log_errors', (string) $logErrors);
        }

        self::assertSame(255, $code);
        self::assertStringContainsString('FATAL [PHP] Uncaught DomainException: nobody caught me', $this->captured());
        self::assertStringContainsString('#0 ', $this->captured());
    }

    /** @return iterable<string, array{0: string, 1: int, 2: list<string>}> */
    public static function scripts(): iterable
    {
        yield 'warning' => ['$a = []; echo $a["missing"] ?? "", @$a["silenced"], $a["undefined"]; echo "still running\n";', 0, ['WARN [PHP] PHP Warning:  Undefined array key "undefined"']];
        yield 'uncaught exception' => ['throw new RuntimeException("worker exploded");', 255, ['FATAL [PHP] Uncaught RuntimeException: worker exploded', '#0 {main}']];
        yield 'fatal error' => ['eval("function nesto_twice() {} function nesto_twice() {}");', 255, ['FATAL [PHP] PHP Fatal error:  Cannot redeclare']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('scripts')]
    public function testInARealProcess(string $code, int $exit, array $expected): void
    {
        $dir = $this->sandbox();
        $script = $this->sandboxFile('script.php', '<?php' . "\n"
            . 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ";\n"
            . 'Nesto\Core\Paths::setBase(' . var_export($dir, true) . ");\n"
            . "Nesto\\Logger\\LogConfig::apply(['default' => 'file', 'channels' => ['file' => ['path' => 'storage/logs/nesto.log']]]);\n"
            . "Nesto\\Logger\\ErrorHandler::register();\n"
            . $code . "\n");

        exec(sprintf('%s -d display_errors=stderr -d log_errors=0 %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($script)), $output, $status);
        $log = (string) @file_get_contents("$dir/storage/logs/nesto.log");

        self::assertSame($exit, $status, implode("\n", $output));
        foreach ($expected as $text) {
            self::assertStringContainsString($text, $log);
        }
        self::assertStringNotContainsString('silenced', $log, '@ is respected');
        if ($exit === 0) {
            self::assertStringContainsString('still running', implode("\n", $output), 'a warning never stops the script');
        } else {
            self::assertStringContainsString('Fatal error: ', implode("\n", $output), 'PHP still reports it');
            self::assertSame(1, substr_count($log, 'FATAL [PHP]'), 'logged once');
        }
    }
}
