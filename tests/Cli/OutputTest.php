<?php

declare(strict_types=1);

namespace Nesto\Tests\Cli;

use Nesto\Cli\Output;
use PHPUnit\Framework\TestCase;

final class OutputTest extends TestCase
{
    public function testPlainWritersWithoutAnsi(): void
    {
        $out = Output::memory();
        $out->line('one');
        $out->info('two');
        $out->success('three');
        $out->warn('four');
        $out->error('five');
        $out->newLine();
        $out->write('six');

        self::assertSame("one\ntwo\nthree\nfour\nfive\n\nsix", str_replace(PHP_EOL, "\n", $out->fetch()));
        self::assertStringNotContainsString("\033[", $out->fetch());
    }

    public function testAnsiColours(): void
    {
        $out = Output::memory()->setAnsi(true);
        $out->success('ok');
        self::assertStringContainsString("\033[32mok\033[0m", $out->fetch());
        self::assertSame("\033[1mb\033[0m", $out->bold('b'));
        $out->setAnsi(false);
        self::assertSame('b', $out->bold('b'));
    }

    public function testTable(): void
    {
        $out = Output::memory();
        $out->table(['METHOD', 'PATH'], [['GET', '/cats'], ['POST', '/cats/{id}']]);
        $lines = explode(PHP_EOL, trim($out->fetch()));

        self::assertCount(6, $lines);
        self::assertSame('+--------+------------+', $lines[0]);
        self::assertSame('| METHOD | PATH       |', $lines[1]);
        self::assertSame('| POST   | /cats/{id} |', $lines[4]);
    }
}
