<?php

declare(strict_types=1);

namespace Nesto\Tests\Cli;

use Nesto\Cli\Application;
use Nesto\Cli\Commands\BuiltIn;
use Nesto\Cli\Commands\NewCommand;
use Nesto\Cli\Output;
use PHPUnit\Framework\TestCase;

final class NewCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nesto-new-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        GeneratorTest::rrmdir($this->dir);
    }

    public function testScaffoldsProject(): void
    {
        $out = Output::memory();
        $app = (new Application())->setOutput($out)->add(...BuiltIn::commands());
        $target = $this->dir . '/my-api';

        self::assertSame(0, $app->run(['new', $target, '--skip-install']));

        foreach ([
            'composer.json', 'nesto', 'public/index.php', 'src/AppModule.php', 'src/App/AppController.php', 'src/App/AppService.php',
            'nesto.config.php', '.env.example', '.gitignore', 'phpunit.xml', 'tests/App/AppControllerTest.php', 'README.md',
        ] as $file) {
            self::assertFileExists("$target/$file", $file);
        }
        foreach (['public/index.php', 'src/AppModule.php', 'src/App/AppController.php', 'src/App/AppService.php', 'nesto.config.php', 'tests/App/AppControllerTest.php'] as $php) {
            GeneratorTest::assertLints("$target/$php");
        }

        // by default the released package from Packagist, no path repository
        $composer = json_decode((string) file_get_contents("$target/composer.json"), true);
        self::assertSame(NewCommand::frameworkConstraint(), $composer['require']['nesto/framework']);
        self::assertArrayNotHasKey('repositories', $composer);
        self::assertSame('stable', $composer['minimum-stability']);

        // .env is created from .env.example (with its own JWT secret)
        self::assertFileExists("$target/.env");
        self::assertStringContainsString('APP_NAME=my-api', (string) file_get_contents("$target/.env"));
        self::assertStringNotContainsString('JWT_SECRET=change-me', (string) file_get_contents("$target/.env"));
        self::assertStringContainsString('A [Nesto](https://github.com/trynesto/framework) application', (string) file_get_contents("$target/README.md"));

        self::assertStringContainsString("'Hello World!'", (string) file_get_contents("$target/src/App/AppService.php"));
        self::assertStringContainsString("setGlobalPrefix('api')", (string) file_get_contents("$target/public/index.php"));
        self::assertStringContainsString('ValidationPipe(whitelist: true, transform: true)', (string) file_get_contents("$target/public/index.php"));
        self::assertStringContainsString('php -S', (string) file_get_contents("$target/README.md"));
        self::assertStringContainsString('php nesto start', (string) file_get_contents("$target/README.md"));

        // `php nesto` is the project's console (Nesto's artisan)
        GeneratorTest::assertLints("$target/nesto");
        self::assertTrue(is_executable("$target/nesto"));
        mkdir("$target/vendor");
        file_put_contents("$target/vendor/autoload.php", "<?php\nrequire " . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ";\n");
        exec(sprintf('cd %s && %s nesto list --no-ansi 2>&1', escapeshellarg($target), escapeshellarg(PHP_BINARY)), $lines, $code);
        $listing = implode("\n", $lines);
        self::assertSame(0, $code, $listing);
        self::assertStringContainsString('php nesto <command> [arguments] [options]', $listing);
        self::assertStringContainsString('start:prod', $listing);

        $config = require "$target/nesto.config.php";
        self::assertSame('App\\AppModule', $config['rootModule']);
        self::assertSame('src', $config['sourceRoot']);

        // the generated project is immediately usable by the generators
        $ctx = \Nesto\Cli\ProjectContext::fromDir($target);
        $app2 = (new Application())->setOutput($out)->setContext($ctx)->add(...BuiltIn::commands());
        self::assertSame(0, $app2->run(['g', 'resource', 'cats', '--no-spec']));
        self::assertStringContainsString('CatsModule::class', (string) file_get_contents("$target/src/AppModule.php"));
        GeneratorTest::assertLints("$target/src/AppModule.php");
    }

    public function testStarterImportsAGlobalConfigModuleAndBootsWithItsConfigFiles(): void
    {
        $out = Output::memory();
        $app = (new Application())->setOutput($out)->add(...BuiltIn::commands());
        $target = $this->dir . '/starter';
        self::assertSame(0, $app->run(['new', $target, '--skip-install']));

        // packages added later (`php nesto add acme/mail`) inject ConfigService: the starter must provide it
        $module = (string) file_get_contents("$target/src/AppModule.php");
        self::assertStringContainsString('use Nesto\\Config\\ConfigModule;', $module);
        self::assertStringContainsString('new ConfigModule(isGlobal: true)', $module);

        // `composer start` must not be killed by Composer's 300s process timeout
        $composer = json_decode((string) file_get_contents("$target/composer.json"), true);
        self::assertSame(['Composer\\Config::disableProcessTimeout', 'php -S 127.0.0.1:3000 -t public public/index.php'], $composer['scripts']['start']);

        // APP_DEBUG must not be read before ConfigModule loaded .env
        self::assertStringNotContainsString("env('APP_DEBUG'", (string) file_get_contents("$target/public/index.php"));

        // boot it in a child process (the framework's autoloader plus the starter's App\ => src/)
        mkdir("$target/vendor");
        file_put_contents("$target/vendor/autoload.php", "<?php\nrequire " . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ";\n" . <<<'PHP'
            spl_autoload_register(static function (string $class): void {
                $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
                if (str_starts_with($class, 'App\\') && is_file($file)) {
                    require $file;
                }
            }, prepend: true);
            PHP);
        file_put_contents("$target/.env", "APP_DEBUG=true\nFILESYSTEM_DISK=public\n"); // replaces the generated .env
        file_put_contents("$target/boot.php", <<<'PHP'
            <?php
            require __DIR__ . '/vendor/autoload.php';
            $app = Nesto\Core\NestoFactory::create(App\AppModule::class);
            $app->setGlobalPrefix('api');
            $response = $app->handle(Nesto\Http\Request::create('GET', '/api'));
            $config = $app->get(Nesto\Config\ConfigService::class);
            echo json_encode([$response->getStatusCode(), (string) $response->getBody(), $config->get('filesystems.default'), env('APP_DEBUG')]);
            PHP);
        exec(sprintf('cd %s && %s boot.php 2>&1', escapeshellarg($target), escapeshellarg(PHP_BINARY)), $lines, $code);
        $output = implode("\n", $lines);
        self::assertSame(0, $code, $output);
        self::assertSame([200, 'Hello World!', 'public', true], json_decode($output, true), $output);
    }

    public function testFrameworkConstraintFollowsTheFrameworkVersion(): void
    {
        self::assertSame('^0.1', NewCommand::frameworkConstraint('0.1.0'));
        self::assertSame('^0.3', NewCommand::frameworkConstraint('v0.3.2'));
        self::assertSame('^1.2', NewCommand::frameworkConstraint('1.2.0'));
        self::assertSame('^0.1', NewCommand::frameworkConstraint('dev-main'));
    }

    public function testDevAndFrameworkPathRequireALocalCheckout(): void
    {
        $out = Output::memory();
        $app = (new Application())->setOutput($out)->add(...BuiltIn::commands());

        self::assertSame(0, $app->run(['new', $this->dir . '/dev', '--dev', '--skip-install']));
        $composer = json_decode((string) file_get_contents($this->dir . '/dev/composer.json'), true);
        self::assertSame([['type' => 'path', 'url' => realpath(dirname(__DIR__, 2)), 'options' => ['symlink' => true]]], $composer['repositories']);
        self::assertSame('*@dev', $composer['require']['nesto/framework']);
        self::assertSame('App\\', array_key_first($composer['autoload']['psr-4']));

        mkdir($this->dir . '/checkout');
        file_put_contents($this->dir . '/checkout/composer.json', '{"name": "nesto/framework"}');
        self::assertSame(0, $app->run(['new', $this->dir . '/local', '--framework-path=' . $this->dir . '/checkout', '--skip-install']));
        $composer = json_decode((string) file_get_contents($this->dir . '/local/composer.json'), true);
        self::assertSame(realpath($this->dir . '/checkout'), $composer['repositories'][0]['url']);

        self::assertSame(1, $app->run(['new', $this->dir . '/bad', '--framework-path=' . $this->dir . '/missing', '--skip-install']));
    }

    public function testRefusesNonEmptyDirectory(): void
    {
        file_put_contents($this->dir . '/x', '');
        $out = Output::memory();
        $app = (new Application())->setOutput($out)->add(...BuiltIn::commands());
        self::assertSame(1, $app->run(['new', $this->dir, '--skip-install']));
        self::assertStringContainsString('not empty', $out->fetch());
    }
}
