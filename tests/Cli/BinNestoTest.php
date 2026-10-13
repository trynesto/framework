<?php

declare(strict_types=1);

namespace Nesto\Tests\Cli;

use PHPUnit\Framework\TestCase;

/** bin/nesto, the binary `composer global require nesto/framework` puts on the PATH. */
final class BinNestoTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nesto-bin-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        GeneratorTest::rrmdir($this->dir);
    }

    /** @return array{int, string} */
    private function nesto(string $cwd, string ...$args): array
    {
        $bin = dirname(__DIR__, 2) . '/bin/nesto';
        exec(sprintf('cd %s && %s %s %s 2>&1', escapeshellarg($cwd), escapeshellarg(PHP_BINARY), escapeshellarg($bin), implode(' ', array_map('escapeshellarg', $args))), $lines, $code);

        return [$code, implode("\n", $lines)];
    }

    public function testRunsOutsideAnyProject(): void
    {
        [$code, $output] = $this->nesto($this->dir, 'list', '--no-ansi');
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('Scaffold a new Nesto project', $output);
    }

    public function testIgnoresTheAutoloaderOfAProjectWithoutNesto(): void
    {
        // e.g. `nesto new api` run inside some other Composer project: its vendor/ has no Nesto
        file_put_contents($this->dir . '/composer.json', '{"name": "acme/other"}');
        mkdir($this->dir . '/vendor');
        file_put_contents($this->dir . '/vendor/autoload.php', "<?php\nfwrite(STDERR, 'wrong autoloader');\nexit(9);\n");

        [$code, $output] = $this->nesto($this->dir, 'new', 'api', '--skip-install', '--no-ansi');
        self::assertSame(0, $code, $output);
        self::assertFileExists($this->dir . '/api/nesto');
    }

    public function testUsesTheProjectsOwnAutoloaderInsideANestoProject(): void
    {
        file_put_contents($this->dir . '/composer.json', '{"name": "acme/api"}');
        mkdir($this->dir . '/vendor/nesto/framework', 0777, true);
        file_put_contents($this->dir . '/vendor/autoload.php', "<?php\nfwrite(STDOUT, 'project autoloader');\nexit(0);\n");

        [$code, $output] = $this->nesto($this->dir, 'list');
        self::assertSame(0, $code, $output);
        self::assertSame('project autoloader', $output);
    }
}
