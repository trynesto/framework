<?php

declare(strict_types=1);

namespace Nesto\Tests\Cli;

use Nesto\Cli\Application;
use Nesto\Cli\Command;
use Nesto\Cli\Output;
use PHPUnit\Framework\TestCase;

final class HelloCommand extends Command
{
    protected string $signature = 'hi|hello {name=World : Who to greet} {--shout : Upper-case it}';
    protected string $description = 'Say hello';

    public function handle(): int
    {
        $text = 'Hello ' . $this->argument('name') . '!';
        $this->line($this->option('shout') ? strtoupper($text) : $text);

        return self::SUCCESS;
    }
}

final class FailingCommand extends Command
{
    protected string $signature = 'fail';
    protected string $description = 'Always fails';

    public function handle(): int
    {
        $this->error('nope');

        return self::FAILURE;
    }
}

final class ApplicationTest extends TestCase
{
    private Application $app;
    private Output $out;

    protected function setUp(): void
    {
        $this->out = Output::memory();
        $this->app = (new Application('nesto', '9.9.9'))->setOutput($this->out)->add(new HelloCommand(), new FailingCommand());
    }

    public function testRunsCommandWithArgumentsOptionsAndAliases(): void
    {
        self::assertSame(0, $this->app->run(['hello']));
        self::assertStringContainsString('Hello World!', $this->out->fetch());

        $this->out->clear();
        self::assertSame(0, $this->app->run(['hi', 'Nesto', '--shout']));
        self::assertStringContainsString('HELLO NESTO!', $this->out->fetch());
    }

    public function testExitCodes(): void
    {
        self::assertSame(1, $this->app->run(['fail']));
        self::assertSame(1, $this->app->run(['does-not-exist']));
        self::assertStringContainsString('is not defined', $this->out->fetch());
        self::assertSame(1, $this->app->run(['hello', '--bogus']));
        self::assertStringContainsString('Usage: hello', $this->out->fetch());
    }

    public function testListHelpAndVersion(): void
    {
        self::assertSame(0, $this->app->run([]));
        self::assertStringContainsString('Available commands', $this->out->fetch());
        self::assertStringContainsString('hello (hi)', $this->out->fetch());
        self::assertStringContainsString('Say hello', $this->out->fetch());

        $this->out->clear();
        self::assertSame(0, $this->app->run(['list']));
        self::assertStringContainsString('fail', $this->out->fetch());

        $this->out->clear();
        self::assertSame(0, $this->app->run(['help', 'hello']));
        self::assertStringContainsString('Who to greet', $this->out->fetch());
        self::assertStringContainsString('--shout', $this->out->fetch());

        $this->out->clear();
        self::assertSame(0, $this->app->run(['hello', '--help']));
        self::assertStringContainsString('Usage:', $this->out->fetch());

        $this->out->clear();
        self::assertSame(0, $this->app->run(['--version']));
        self::assertSame('nesto 9.9.9', trim($this->out->fetch()));
    }

    public function testHelpAlignsOptionsAndArgumentsInOneColumn(): void
    {
        $this->app->add(new \Nesto\Cli\Commands\StartProdCommand());
        self::assertSame(0, $this->app->run(['help', 'start:prod', '--no-ansi']));
        $columns = [];
        foreach (explode("\n", $this->out->fetch()) as $line) {
            if (preg_match('/^  (-\S.*?)  +(\S.*)$/', $line, $m)) {
                $columns[$m[1]] = strlen($line) - strlen($m[2]);
            }
        }
        self::assertArrayHasKey('--max-requests=MAX-REQUESTS', $columns);
        self::assertCount(1, array_unique($columns), 'option descriptions start in the same column: ' . json_encode($columns));

        $this->app->add(new \Nesto\Cli\Commands\GenerateCommand());
        $this->out->clear();
        self::assertSame(0, $this->app->run(['help', 'generate', '--no-ansi']));
        $starts = [];
        foreach (explode("\n", $this->out->fetch()) as $line) {
            if (preg_match('/^  (schematic|name|--flat|--path=PATH)  +(\S.*)$/', $line, $m)) {
                $starts[$m[1]] = strlen($line) - strlen($m[2]);
            }
        }
        self::assertCount(4, $starts);
        self::assertCount(1, array_unique($starts), 'arguments and options share the column: ' . json_encode($starts));
    }

    public function testListAlignsLongCommandNamesWithAliases(): void
    {
        $this->app->add(new \Nesto\Cli\Commands\GenerateCommand(), new \Nesto\Cli\Commands\StartProdCommand());
        self::assertSame(0, $this->app->run(['list', '--no-ansi']));
        $starts = [];
        foreach (explode("\n", $this->out->fetch()) as $line) {
            if (preg_match('/^  (\S+(?: \([^)]*\))?)  +(\S.*)$/', $line, $m)) {
                $starts[$m[1]] = strlen($line) - strlen($m[2]);
            }
        }
        self::assertArrayHasKey('generate (g)', $starts);
        self::assertArrayHasKey('start:prod', $starts);
        self::assertCount(1, array_unique($starts), json_encode($starts));
    }

    public function testNoAnsiFlagDisablesColours(): void
    {
        $this->out->setAnsi(true);
        $this->app->run(['fail', '--no-ansi']);
        self::assertStringNotContainsString("\033[", $this->out->fetch());
    }

    public function testSuggestsSimilarCommand(): void
    {
        $this->app->run(['helo']);
        self::assertStringContainsString('Did you mean "hello"?', $this->out->fetch());
    }

    public function testTheFrameworkVersionIsASemanticVersion(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', \Nesto\Cli\Application::frameworkVersion());
        self::assertSame('^0.1', \Nesto\Cli\Commands\NewCommand::frameworkConstraint('v0.1.2'));
    }
}
