<?php

declare(strict_types=1);

namespace Nesto\Tests\Cli;

use Nesto\Cli\Application;
use Nesto\Cli\Commands\BuiltIn;
use Nesto\Cli\Console;
use Nesto\Cli\Output;
use Nesto\Cli\ProjectContext;
use Nesto\Core\Packages\PackageManifest;
use PHPUnit\Framework\TestCase;

/** `php nesto add` against a fake project whose vendor/ holds a copy of the tests/Fixtures/packages/mail fixture package. */
final class AddCommandTest extends TestCase
{
    private string $dir;
    private Application $app;
    private Output $out;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nesto-add-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/src', 0777, true);
        mkdir($this->dir . '/vendor/composer', 0777, true);
        file_put_contents($this->dir . '/composer.json', '{}');
        file_put_contents($this->dir . '/.env', "APP_NAME=test\nMAIL_DRIVER=array\n");
        // the root module already imports a *different* MailModule: the package one must get an alias
        file_put_contents($this->dir . '/src/AppModule.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\nuse App\\Mail\\MailModule;\nuse Nesto\\Common\\Attributes\\Module;\n\n#[Module(imports: [MailModule::class])]\nfinal class AppModule\n{\n}\n");

        self::copyDir(dirname(__DIR__) . '/Fixtures/packages/mail', $this->dir . '/vendor/acme/mail');
        file_put_contents($this->dir . '/vendor/composer/installed.json', json_encode(['packages' => [
            ['name' => 'acme/mail', 'install-path' => '../acme/mail', 'extra' => json_decode((string) file_get_contents($this->dir . '/vendor/acme/mail/composer.json'), true)['extra']],
            ['name' => 'psr/log', 'install-path' => '../psr/log'],
        ]]));
        mkdir($this->dir . '/vendor/psr/log', 0777, true);

        PackageManifest::flush();
        $this->out = Output::memory();
        $context = new ProjectContext($this->dir, ['rootModule' => 'App\\AppModule', 'sourceRoot' => 'src', 'appNamespace' => 'App']);
        $this->app = (new Application('php nesto'))->setOutput($this->out)->setContext($context)->add(...BuiltIn::commands());
    }

    protected function tearDown(): void
    {
        GeneratorTest::rrmdir($this->dir);
        PackageManifest::flush();
    }

    public function testAddPublishesConfigImportsTheModuleAndListsEnvKeys(): void
    {
        self::assertSame(0, $this->app->run(['add', 'acme/mail', '--skip-install', '--no-ansi']));
        $output = $this->out->fetch();

        self::assertFileEquals($this->dir . '/vendor/acme/mail/config/mail.php', $this->dir . '/config/mail.php');
        $root = (string) file_get_contents($this->dir . '/src/AppModule.php');
        self::assertStringContainsString('use Acme\\Mail\\MailModule as AcmeMailModule;', $root);
        // the package injects ConfigService and the project had no ConfigModule yet: it is imported too
        self::assertStringContainsString('use Nesto\\Config\\ConfigModule;', $root);
        self::assertStringContainsString('#[Module(imports: [MailModule::class, AcmeMailModule::class, new ConfigModule(isGlobal: true)])]', $root);
        GeneratorTest::assertLints($this->dir . '/src/AppModule.php');

        self::assertStringContainsString('CREATE config/mail.php', $output);
        self::assertStringContainsString('UPDATE src/AppModule.php (imports Acme\\Mail\\MailModule)', $output);
        self::assertStringContainsString('UPDATE src/AppModule.php (imports Nesto\\Config\\ConfigModule, isGlobal: true)', $output);
        self::assertStringContainsString('MAIL_FROM_ADDRESS, MAIL_FROM_NAME, MAIL_LOG_PATH', $output);
        self::assertStringNotContainsString('MAIL_DRIVER,', $output, 'keys already in .env are not listed');
        self::assertStringContainsString('acme/mail is ready.', $output);
    }

    public function testAddIsIdempotentAndForceRepublishes(): void
    {
        $this->app->run(['add', 'acme/mail', '--skip-install', '--no-ansi']);
        file_put_contents($this->dir . '/config/mail.php', "<?php return ['driver' => 'edited'];");
        $this->out->fetch();

        $this->app->run(['add', 'acme/mail', '--skip-install', '--no-ansi']);
        $again = $this->out->fetch();
        self::assertStringContainsString('SKIP   config/mail.php', $again);
        self::assertStringContainsString('Acme\\Mail\\MailModule is already imported', $again);
        self::assertStringContainsString("'edited'", (string) file_get_contents($this->dir . '/config/mail.php'));
        self::assertSame(1, substr_count((string) file_get_contents($this->dir . '/src/AppModule.php'), 'AcmeMailModule::class'));

        $this->app->run(['config:publish', 'acme/mail', '--force', '--no-ansi']);
        self::assertStringContainsString('UPDATE config/mail.php', $this->out->fetch());
        self::assertFileEquals($this->dir . '/vendor/acme/mail/config/mail.php', $this->dir . '/config/mail.php');
    }

    public function testConfigModuleIsNotAddedTwice(): void
    {
        // already set up somewhere in the project (here: a CommonModule)
        file_put_contents($this->dir . '/src/CommonModule.php', "<?php\n\nnamespace App;\n\nuse Nesto\\Common\\Attributes\\Module;\nuse Nesto\\Config\\ConfigModule;\n\n#[Module(imports: [new ConfigModule(isGlobal: true)])]\nfinal class CommonModule {}\n");
        $this->app->run(['add', 'acme/mail', '--skip-install', '--no-ansi']);
        self::assertStringNotContainsString('ConfigModule', (string) file_get_contents($this->dir . '/src/AppModule.php'));

        $this->app->run(['add', 'acme/mail', '--skip-install', '--no-ansi']);
        self::assertStringNotContainsString('ConfigModule', (string) file_get_contents($this->dir . '/src/AppModule.php'));
    }

    public function testCommandsAreListedByNameRightAfterComposerRequire(): void
    {
        // `composer require` ran in a child process: this process' autoloader does not know the package yet,
        // only the regenerated vendor/composer maps do
        $class = 'Acme\\Fresh' . bin2hex(random_bytes(4)) . '\\PingCommand';
        $namespace = substr($class, 0, (int) strrpos($class, '\\'));
        mkdir($this->dir . '/vendor/acme/fresh/src', 0777, true);
        file_put_contents($this->dir . '/vendor/acme/fresh/src/PingCommand.php', "<?php\n\nnamespace $namespace;\n\nfinal class PingCommand extends \\Nesto\\Cli\\Command\n{\n    protected string \$signature = 'fresh:ping';\n\n    public function handle(): int\n    {\n        return self::SUCCESS;\n    }\n}\n");
        file_put_contents($this->dir . '/vendor/composer/autoload_psr4.php', "<?php\n\nreturn [" . var_export($namespace . '\\', true) . " => [" . var_export($this->dir . '/vendor/acme/fresh/src', true) . "]];\n");
        file_put_contents($this->dir . '/vendor/composer/installed.json', json_encode(['packages' => [
            ['name' => 'acme/fresh', 'install-path' => '../acme/fresh', 'extra' => ['nesto' => ['commands' => [$class]]]],
        ]]));
        self::assertFalse(class_exists($class));

        self::assertSame(0, $this->app->run(['add', 'acme/fresh', '--skip-install', '--no-ansi']));
        $output = $this->out->fetch();
        self::assertStringContainsString('CMD    php nesto fresh:ping', $output);
        self::assertStringNotContainsString('PingCommand', $output);
    }

    public function testNoPublishNoImport(): void
    {
        $this->app->run(['add', 'acme/mail', '--skip-install', '--no-publish', '--no-import', '--no-ansi']);

        self::assertFileDoesNotExist($this->dir . '/config/mail.php');
        self::assertStringNotContainsString('Acme', (string) file_get_contents($this->dir . '/src/AppModule.php'));
    }

    public function testPlainComposerPackagesAreReportedNotWired(): void
    {
        self::assertSame(0, $this->app->run(['add', 'psr/log', '--skip-install', '--no-ansi']));
        self::assertStringContainsString('it is not a Nesto package', $this->out->fetch());

        self::assertNotSame(0, $this->app->run(['add', 'nobody/missing', '--skip-install', '--no-ansi']));
        self::assertStringContainsString('nobody/missing is not installed', $this->out->fetch());
    }

    public function testConfigPublishListsAndPackageDiscoverShowsPackages(): void
    {
        $this->app->run(['config:publish', '--no-ansi']);
        $list = $this->out->fetch();
        self::assertMatchesRegularExpression('/\| mail\s+\| acme\/mail\s+\| default \(not published\)\s+\|/', $list);

        $this->app->run(['config:publish', 'mail', '--no-ansi']);
        self::assertStringContainsString('CREATE config/mail.php', $this->out->fetch());

        $this->app->run(['package:discover', '--no-ansi']);
        self::assertMatchesRegularExpression('/\| acme\/mail\s+\| MailModule\s+\| MailTestCommand\s+\| mail\s+\|/', $this->out->fetch());
    }

    public function testConsoleRegistersCommandsDeclaredByPackages(): void
    {
        spl_autoload_register($loader = function (string $class): void {
            if (str_starts_with($class, 'Acme\\Mail\\')) {
                require $this->dir . '/vendor/acme/mail/src/' . str_replace('\\', '/', substr($class, 10)) . '.php';
            }
        });

        try {
            $app = Console::application(new ProjectContext($this->dir));
            self::assertTrue($app->has('mail:test'));
        } finally {
            spl_autoload_unregister($loader);
        }
    }

    private static function copyDir(string $from, string $to): void
    {
        mkdir($to, 0777, true);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $item) {
            $target = $to . '/' . substr($item->getPathname(), strlen($from) + 1);
            $item->isDir() ? @mkdir($target, 0777, true) : copy($item->getPathname(), $target);
        }
    }
}
