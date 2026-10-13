<?php

declare(strict_types=1);

namespace Nesto\Tests\Common\Console;

use Nesto\Common\Attributes\Controller;
use Nesto\Common\Attributes\Get;
use Nesto\Common\Debug\Dumper;
use Nesto\Testing\Test;
use PHPUnit\Framework\TestCase;

enum Status: string
{
    case Active = 'active';
}

final class Node
{
    public string $name = 'root';
    protected int $depth = 0;
    private ?Node $self = null;
    public string $notSet;

    public function loop(): self
    {
        $this->self = $this;

        return $this;
    }
}

#[Controller('debug')]
final class DebugController
{
    #[Get('dump')]
    public function dump(): array
    {
        console(['id' => 7, 'tags' => ['php']], Status::Active);
    }

    #[Get('ok')]
    public function ok(): array
    {
        return ['ok' => true];
    }
}

final class ConsoleHelperTest extends TestCase
{
    public function testTheDumperShowsTypesVisibilityAndRecursion(): void
    {
        $text = Dumper::text(['id' => 7, 'ok' => true, 'none' => null, 'list' => [1.5], 'status' => Status::Active, 'node' => (new Node)->loop()]);

        self::assertStringContainsString('"id" => 7', $text);
        self::assertStringContainsString('"ok" => true', $text);
        self::assertStringContainsString('"none" => null', $text);
        self::assertStringContainsString('0 => 1.5', $text);
        self::assertStringContainsString(Status::class . '::Active = \'active\'', $text);
        self::assertStringContainsString('+name: "root" (4)', $text);
        self::assertStringContainsString('#depth: 0', $text);
        self::assertStringContainsString('-self: ' . Node::class, $text);
        self::assertStringContainsString('*RECURSION*', $text);
        self::assertStringContainsString('+notSet: uninitialized', $text);
    }

    public function testDuringARequestItAnswersWithTheDumpAndTheAppKeepsServing(): void
    {
        $module = Test::createTestingModule(['controllers' => [DebugController::class]])->compile();

        $plain = $module->request()->get('/debug/dump')->expect(500);
        self::assertStringContainsString('ConsoleHelperTest.php:', $plain->text());
        self::assertStringContainsString('"tags" => array:1', $plain->text());
        self::assertStringContainsString(Status::class . '::Active', $plain->text());

        $html = $module->request()->withHeader('Accept', 'text/html')->get('/debug/dump')->expect(500);
        self::assertStringContainsString('<pre class="mdump">', $html->text());
        self::assertStringContainsString('text/html', (string) $html->header('Content-Type'));

        $module->request()->get('/debug/ok')->expect(200)->assertJson(['ok' => true]);
        self::assertFalse(Dumper::handlingRequest());
    }

    public function testInATerminalItPrintsAndExitsWithCodeOne(): void
    {
        $script = 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . '; console(["a" => 1], "two"); echo "not reached";';
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1', $output, $code);
        $out = implode("\n", $output);

        self::assertSame(1, $code);
        self::assertStringContainsString('"a" => 1', $out);
        self::assertStringContainsString('"two" (3)', $out);
        self::assertStringNotContainsString('not reached', $out);
    }
}
