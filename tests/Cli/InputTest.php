<?php

declare(strict_types=1);

namespace Nesto\Tests\Cli;

use Nesto\Cli\CliException;
use Nesto\Cli\Input;
use Nesto\Cli\Output;
use Nesto\Cli\Signature;
use PHPUnit\Framework\TestCase;

final class InputTest extends TestCase
{
    private function sig(): Signature
    {
        return Signature::parse('g {schematic} {name?} {--flat} {--no-spec} {--p|path=} {--port=3000} {--tag=*}');
    }

    public function testParsesArgumentsAndOptions(): void
    {
        $in = Input::fromTokens($this->sig(), ['controller', 'cats', '--flat', '--path=Admin', '--port', '8080', '--tag=a', '--tag', 'b']);

        self::assertSame('controller', $in->argument('schematic'));
        self::assertSame('cats', $in->argument('name'));
        self::assertTrue($in->option('flat'));
        self::assertFalse($in->option('no-spec'));
        self::assertSame('Admin', $in->option('path'));
        self::assertSame('8080', $in->option('port'));
        self::assertSame(['a', 'b'], $in->option('tag'));
        self::assertTrue($in->flag('flat'));
        self::assertSame(['schematic' => 'controller', 'name' => 'cats'], $in->arguments());
    }

    public function testDefaultsShortcutsAndOptionalArguments(): void
    {
        $in = Input::fromTokens($this->sig(), ['service', '-p', 'Core', '--no-spec']);

        self::assertNull($in->argument('name'));
        self::assertFalse($in->hasArgument('name'));
        self::assertSame('3000', $in->option('port'));
        self::assertSame('Core', $in->option('path'));
        self::assertTrue($in->option('no-spec'));
        self::assertSame([], $in->option('tag'));
    }

    public function testMissingRequiredArgumentThrows(): void
    {
        $this->expectException(CliException::class);
        $this->expectExceptionMessage('missing: "schematic"');
        Input::fromTokens($this->sig(), []);
    }

    public function testUnknownOptionThrows(): void
    {
        $this->expectException(CliException::class);
        $this->expectExceptionMessage('--bogus');
        Input::fromTokens($this->sig(), ['x', '--bogus']);
    }

    public function testTooManyArgumentsThrows(): void
    {
        $this->expectException(CliException::class);
        Input::fromTokens($this->sig(), ['a', 'b', 'c']);
    }

    public function testArrayArgumentCollectsTheRest(): void
    {
        $in = Input::fromTokens(Signature::parse('rm {paths*} {--f}'), ['a', 'b', '-f']);
        self::assertSame(['a', 'b'], $in->argument('paths'));
        self::assertTrue($in->option('f'));
    }

    public function testInteractiveHelpersReadFromStream(): void
    {
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, "Alice\n\nyes\n1\n");
        rewind($stream);
        $out = Output::memory();
        $in = new Input([], [], $stream);

        self::assertSame('Alice', $in->ask('Name', 'Bob', $out));
        self::assertSame('Bob', $in->ask('Name', 'Bob', $out));
        self::assertTrue($in->confirm('Sure?', false, $out));
        self::assertSame('green', $in->choice('Colour', ['red', 'green'], 'red', $out));
        self::assertStringContainsString('Name [Bob]:', $out->fetch());
        self::assertStringContainsString('[1] green', $out->fetch());
    }

    public function testNonInteractiveReturnsDefaults(): void
    {
        $in = new Input([], [], fopen('php://memory', 'r'), interactive: false);
        self::assertSame('d', $in->ask('Q', 'd'));
        self::assertTrue($in->confirm('Q', true));
        self::assertSame('a', $in->choice('Q', ['a', 'b'], 'a'));
    }
}
