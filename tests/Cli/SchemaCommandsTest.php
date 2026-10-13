<?php

declare(strict_types=1);

namespace Nesto\Tests\Cli;

use Nesto\Cli\Application;
use Nesto\Cli\Commands\BuiltIn;
use Nesto\Cli\Output;
use Nesto\Cli\ProjectContext;
use Nesto\Common\Attributes\Module;
use Nesto\Core\NestoFactory;
use Nesto\Orm\DataSource;
use Nesto\Orm\MetadataRegistry;
use Nesto\Orm\OrmModule;
use Nesto\Tests\Orm\Fixtures\Cat;
use PHPUnit\Framework\TestCase;

#[Module(imports: [new OrmModule(type: 'sqlite', database: ':memory:', entities: [Cat::class])])]
final class SchemaCommandsModule
{
}

/** TypeORM-style migration:* and schema:* commands against an in-memory SQLite app. */
final class SchemaCommandsTest extends TestCase
{
    private string $dir;
    private Output $out;
    private Application $app;
    private DataSource $ds;

    protected function setUp(): void
    {
        MetadataRegistry::clear();
        $this->dir = sys_get_temp_dir() . '/nesto-schema-cmd-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/database/migrations', 0777, true);
        $this->out = Output::memory();
        $ctx = new ProjectContext($this->dir, ['rootModule' => SchemaCommandsModule::class]);
        $app = NestoFactory::createApplicationContext(SchemaCommandsModule::class);
        $ctx->setApp($app);
        $this->ds = $app->get(DataSource::class);
        $this->app = (new Application())->setOutput($this->out)->setContext($ctx)->add(...BuiltIn::commands());
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV');
        array_map('unlink', glob($this->dir . '/database/migrations/*.php') ?: []);
        rmdir($this->dir . '/database/migrations');
        rmdir($this->dir . '/database');
        rmdir($this->dir);
    }

    public function testGenerateRunShowRevertAndFake(): void
    {
        $this->assertSame(0, $this->cli('migration:generate', 'create_cats', '--dry-run'));
        $this->assertStringContainsString("\$schema->createTable('cats', function (Blueprint \$table): void {", $this->text());
        $this->assertSame([], glob($this->dir . '/database/migrations/*.php'), '--dry-run writes nothing');

        $this->assertSame(1, $this->cli('migration:generate', 'create_cats', '--check'));
        $this->assertSame(0, $this->cli('migration:generate', 'create_cats'));
        $files = glob($this->dir . '/database/migrations/*_create_cats.php');
        $this->assertCount(1, $files);
        $code = (string) file_get_contents($files[0]);
        $this->assertMatchesRegularExpression('/final class CreateCats\d{14} extends Migration/', $code);
        $this->assertStringContainsString("\$table->string('name');", $code);
        $this->assertStringContainsString("\$schema->dropTable('cats');", $code);

        $this->assertSame(0, $this->cli('migration:show'));
        $this->assertStringContainsString('1 pending', $this->text());
        $this->assertSame(0, $this->cli('migration:run'));
        $this->assertTrue($this->ds->schema()->hasTable('cats'));
        $this->assertSame(0, $this->cli('migration:generate', 'nothing'));
        $this->assertStringContainsString('No changes', $this->text());
        $this->assertSame(0, $this->cli('migration:generate', 'nothing', '--check'), 'in sync: CI check passes');
        $this->assertSame(0, $this->cli('schema:log'));
        $this->assertStringContainsString('up to date', $this->text());

        $this->assertSame(0, $this->cli('migration:revert'));
        $this->assertFalse($this->ds->schema()->hasTable('cats'));
        $this->assertSame(0, $this->cli('migrate', '--fake'), 'old name still works');
        $this->assertStringContainsString('Faked', $this->text());
        $this->assertFalse($this->ds->schema()->hasTable('cats'), '--fake records without running');
        $this->assertSame([true], array_column(\Nesto\Cli\Commands\MigratorCache::get($this->ds)->status(), 'ran'));
        $this->assertSame(0, $this->cli('migrate:revert', '--fake'));
        $this->assertSame([false], array_column(\Nesto\Cli\Commands\MigratorCache::get($this->ds)->status(), 'ran'));
        $this->assertSame(0, $this->cli('migration:run', '--transaction=each'));
        $this->assertTrue($this->ds->schema()->hasTable('cats'));
        $this->assertSame(1, $this->cli('migration:run', '--transaction=sometimes'));
    }

    public function testMigrationCreateWritesAnEmptyMigration(): void
    {
        $this->assertSame(0, $this->cli('migration:create', 'add_nickname'));
        $files = glob($this->dir . '/database/migrations/*_add_nickname.php');
        $this->assertCount(1, $files);
        $code = (string) file_get_contents($files[0]);
        $this->assertMatchesRegularExpression('/final class AddNickname\d{14} extends Migration/', $code);
        $this->assertStringNotContainsString('createTable', $code);
        $this->assertSame(0, $this->cli('migrate:status'), 'old name still works');
    }

    public function testSchemaLogSyncAndDrop(): void
    {
        $this->assertSame(0, $this->cli('schema:log'));
        $this->assertStringContainsString('CREATE TABLE "cats"', $this->text());
        $this->assertFalse($this->ds->schema()->hasTable('cats'), 'schema:log runs nothing');

        putenv('APP_ENV=production');
        $this->assertSame(1, $this->cli('schema:sync'));
        $this->assertStringContainsString('production', $this->text());
        $this->assertFalse($this->ds->schema()->hasTable('cats'));
        $this->assertSame(0, $this->cli('schema:sync', '--force'));
        $this->assertTrue($this->ds->schema()->hasTable('cats'));
        putenv('APP_ENV');
        $this->assertSame(0, $this->cli('schema:sync'));
        $this->assertStringContainsString('already in sync', $this->text());

        $this->assertSame(1, $this->cli('schema:drop'));
        $this->assertTrue($this->ds->schema()->hasTable('cats'));
        $this->assertSame(0, $this->cli('schema:drop', '--force'));
        $this->assertFalse($this->ds->schema()->hasTable('cats'));
    }

    private function cli(string ...$tokens): int
    {
        $this->out->clear();

        return $this->app->run($tokens);
    }

    private function text(): string
    {
        return $this->out->fetch();
    }
}
