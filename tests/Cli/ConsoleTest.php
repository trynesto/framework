<?php

declare(strict_types=1);

namespace Nesto\Tests\Cli;

use Nesto\Cli\Command;
use Nesto\Cli\Console;
use Nesto\Cli\Output;
use Nesto\Cli\ProjectContext;
use PHPUnit\Framework\TestCase;

final class GreetCommand extends Command
{
    protected string $signature = 'greet';
    protected string $description = 'Say hello';
    protected string $help = 'Example: {bin} greet';

    public function handle(): int
    {
        $this->line("Run it again with {$this->bin()} greet");

        return self::SUCCESS;
    }
}

final class ConsoleTest extends TestCase
{
    public function testRegistersBuiltInAndProjectCommandsUnderTheGivenName(): void
    {
        $out = Output::memory();
        $app = Console::application(new ProjectContext(sys_get_temp_dir(), ['commands' => [GreetCommand::class]]))->setOutput($out);

        self::assertSame('php nesto', $app->name());
        self::assertTrue($app->has('start:prod'));
        self::assertTrue($app->has('greet'));

        self::assertSame(0, $app->run(['greet', '--no-ansi']));
        self::assertStringContainsString('Run it again with php nesto greet', $out->fetch());

        $app->run(['help', 'greet', '--no-ansi']);
        self::assertStringContainsString('Example: php nesto greet', $out->fetch());
    }

    public function testTheGlobalBinaryKeepsItsOwnName(): void
    {
        $out = Output::memory();
        $app = Console::application(null, 'nesto')->setOutput($out);

        $app->run(['list', '--no-ansi']);
        self::assertStringContainsString('nesto <command> [arguments] [options]', $out->fetch());
    }
}
