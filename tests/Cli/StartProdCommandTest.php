<?php

declare(strict_types=1);

namespace Nesto\Tests\Cli;

use Nesto\Cli\Application;
use Nesto\Cli\Commands\BuiltIn;
use Nesto\Cli\Output;
use Nesto\Cli\ProjectContext;
use PHPUnit\Framework\TestCase;

final class StartProdCommandTest extends TestCase
{
    private string $dir;
    private string $binary;
    private Application $app;
    private Output $out;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nesto-prod-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/public', 0777, true);
        file_put_contents($this->dir . '/composer.json', '{}');
        file_put_contents($this->dir . '/public/index.php', "<?php\n");
        $this->binary = $this->dir . '/frankenphp';
        file_put_contents($this->binary, "#!/bin/sh\n");
        chmod($this->binary, 0755);

        $this->out = Output::memory();
        $this->app = (new Application())->setOutput($this->out)->setContext(new ProjectContext($this->dir))->add(...BuiltIn::commands());
    }

    protected function tearDown(): void
    {
        GeneratorTest::rrmdir($this->dir);
    }

    public function testBuildsTheFrankenPhpWorkerCommand(): void
    {
        self::assertSame(0, $this->app->run(['start:prod', '--dry-run', "--binary={$this->binary}", '--no-ansi']));

        // macOS resolves /var to /private/var: compare with one spelling of the temp dir.
        $output = str_replace(realpath($this->dir) ?: $this->dir, $this->dir, $this->out->fetch());
        self::assertStringContainsString(
            sprintf("'%1\$s/frankenphp' 'php-server' '--root' '%1\$s/public' '--worker' '%1\$s/public/index.php' '--listen' '0.0.0.0:3000'", $this->dir),
            $output,
        );
    }

    public function testWorkersMaxRequestsPortAndAccessLog(): void
    {
        $this->app->run(['start:prod', '--dry-run', "--binary={$this->binary}", '--workers=4', '--max-requests=500', '--port=8080', '--host=127.0.0.1', '--access-log', '--no-ansi']);
        $output = $this->out->fetch();

        self::assertStringStartsWith("MAX_REQUESTS='500' ", trim($output));
        self::assertStringContainsString("/public/index.php,4' '--listen' '127.0.0.1:8080' '--access-log'", $output);
    }

    public function testDomainEnablesHttpsInsteadOfListen(): void
    {
        $this->app->run(['start:prod', '--dry-run', "--binary={$this->binary}", '--domain=api.example.com', '--no-ansi']);
        $output = $this->out->fetch();

        self::assertStringContainsString("'--domain' 'api.example.com'", $output);
        self::assertStringNotContainsString('--listen', $output);
    }

    public function testMissingBinaryExplainsHowToInstall(): void
    {
        self::assertNotSame(0, $this->app->run(['start:prod', '--dry-run', '--binary=/nope/frankenphp', '--no-ansi']));
        self::assertStringContainsString('FrankenPHP was not found', $this->out->fetch());
    }
}
