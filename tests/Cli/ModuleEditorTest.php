<?php

declare(strict_types=1);

namespace Nesto\Tests\Cli;

use Nesto\Cli\Generator\ModuleEditor;
use PHPUnit\Framework\TestCase;

final class ModuleEditorTest extends TestCase
{
    private const HEADER = "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Cats;\n\nuse Nesto\\Common\\Attributes\\Module;\n\n";

    public function testAddsArgumentListToBareAttribute(): void
    {
        $code = self::HEADER . "#[Module]\nfinal class CatsModule\n{\n}\n";
        $out = ModuleEditor::addToList($code, 'controllers', 'App\\Cats\\CatsController');
        self::assertStringContainsString('#[Module(controllers: [CatsController::class])]', $out);
        self::assertStringNotContainsString('use App\\Cats\\CatsController;', $out, 'same namespace → no use statement');
    }

    public function testAppendsToExistingInlineList(): void
    {
        $code = self::HEADER . "#[Module(controllers: [A::class], providers: [S::class])]\nfinal class CatsModule {}\n";
        $out = ModuleEditor::addToList($code, 'controllers', 'App\\Cats\\B');
        self::assertStringContainsString('controllers: [A::class, B::class]', $out);
        self::assertStringContainsString('providers: [S::class]', $out);
    }

    public function testAppendsToMultilineListAndAddsUseStatement(): void
    {
        $code = self::HEADER . "#[Module(\n    imports: [\n        A::class,\n    ],\n    providers: [S::class],\n)]\nfinal class CatsModule {}\n";
        $out = ModuleEditor::addToList($code, 'imports', 'App\\Dogs\\DogsModule');
        self::assertStringContainsString("    imports: [\n        A::class,\n        DogsModule::class,\n    ],", $out);
        self::assertStringContainsString("use App\\Dogs\\DogsModule;\nuse Nesto\\Common\\Attributes\\Module;", $out);
    }

    public function testAddsNewNamedArgument(): void
    {
        $code = self::HEADER . "#[Module(imports: [A::class])]\nfinal class CatsModule {}\n";
        $out = ModuleEditor::addToList($code, 'providers', 'App\\Cats\\CatsService');
        self::assertStringContainsString('#[Module(imports: [A::class], providers: [CatsService::class])]', $out);

        $code = self::HEADER . "#[Module(\n    imports: [A::class],\n)]\nfinal class CatsModule {}\n";
        $out = ModuleEditor::addToList($code, 'providers', 'App\\Cats\\CatsService');
        self::assertStringContainsString("    imports: [A::class],\n    providers: [CatsService::class],\n)]", $out);
    }

    public function testIsIdempotent(): void
    {
        $code = self::HEADER . "#[Module(controllers: [CatsController::class])]\nfinal class CatsModule {}\n";
        self::assertSame($code, ModuleEditor::addToList($code, 'controllers', 'App\\Cats\\CatsController'));
    }

    public function testEmptyListAndNestedBrackets(): void
    {
        $code = self::HEADER . "#[Module(imports: [[OrmModule::class, 'forFeature', [[Cat::class]]]], controllers: [])]\nfinal class CatsModule {}\n";
        $out = ModuleEditor::addToList($code, 'controllers', 'App\\Cats\\CatsController');
        self::assertStringContainsString("controllers: [CatsController::class])]", $out);
        self::assertStringContainsString("[[OrmModule::class, 'forFeature', [[Cat::class]]]]", $out);
    }

    public function testThrowsWithoutModuleAttribute(): void
    {
        $this->expectException(\RuntimeException::class);
        ModuleEditor::addToList("<?php\nclass X {}\n", 'controllers', 'A\\B');
    }

    public function testAddsAnExpressionOnceAndAddsItsUseStatement(): void
    {
        $code = "<?php\n\nnamespace App;\n\nuse Nesto\\Common\\Attributes\\Module;\n\n#[Module(imports: [CatsModule::class])]\nfinal class AppModule {}\n";
        $out = ModuleEditor::addExpressionToList($code, 'imports', 'Nesto\\Config\\ConfigModule', 'new %s(isGlobal: true)');
        self::assertStringContainsString('#[Module(imports: [CatsModule::class, new ConfigModule(isGlobal: true)])]', $out);
        self::assertStringContainsString("use Nesto\\Config\\ConfigModule;", $out);
        self::assertSame($out, ModuleEditor::addExpressionToList($out, 'imports', 'Nesto\\Config\\ConfigModule', 'new %s(isGlobal: true)'));

        // any existing form of the class counts (forRoot-style array, ::class)
        $listed = str_replace('CatsModule::class', '[ConfigModule::class, \'forRoot\', [[]]]', $out);
        $listed = str_replace(', new ConfigModule(isGlobal: true)', '', $listed);
        self::assertSame($listed, ModuleEditor::addExpressionToList($listed, 'imports', 'Nesto\\Config\\ConfigModule', 'new %s(isGlobal: true)'));
    }
}
