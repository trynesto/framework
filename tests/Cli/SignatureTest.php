<?php

declare(strict_types=1);

namespace Nesto\Tests\Cli;

use Nesto\Cli\Signature;
use PHPUnit\Framework\TestCase;

final class SignatureTest extends TestCase
{
    public function testParsesNameAliasesArgumentsAndOptions(): void
    {
        $s = Signature::parse('g|generate {schematic : What to make} {name?} {--flat} {--no-spec} {--p|path=} {--port=3000} {--tag=*} {files*}');

        self::assertSame('generate', $s->name);
        self::assertSame(['g'], $s->aliases);

        self::assertSame('schematic', $s->arguments[0]['name']);
        self::assertFalse($s->arguments[0]['optional']);
        self::assertSame('What to make', $s->arguments[0]['description']);
        self::assertSame('name', $s->arguments[1]['name']);
        self::assertTrue($s->arguments[1]['optional']);
        self::assertTrue($s->arguments[2]['array']);

        self::assertTrue($s->options['flat']['flag']);
        self::assertTrue($s->options['no-spec']['flag']);
        self::assertFalse($s->options['path']['flag']);
        self::assertSame('p', $s->options['path']['shortcut']);
        self::assertNull($s->options['path']['default']);
        self::assertSame('3000', $s->options['port']['default']);
        self::assertTrue($s->options['tag']['array']);
    }

    public function testDefaultsAndSynopsis(): void
    {
        $s = Signature::parse('hello {name=World}');
        self::assertTrue($s->arguments[0]['optional']);
        self::assertSame('World', $s->arguments[0]['default']);
        self::assertSame('hello [name]', $s->synopsis());

        $s = Signature::parse('start {--port=3000} {--watch}');
        self::assertSame('start [--port=PORT] [--watch]', $s->synopsis());
    }

    public function testEmptySignatureThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Signature::parse('   ');
    }
}
