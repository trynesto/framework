<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm;

use Nesto\Orm\Connection;
use Nesto\Orm\DataSource;
use Nesto\Orm\Mapping\EntitySchemaBuilder;
use Nesto\Orm\MetadataRegistry;
use Nesto\Orm\Migration\Blueprint;
use Nesto\Orm\Migration\Grammar;
use Nesto\Orm\Migration\Migration;
use Nesto\Orm\Migration\MigrationRenderer;
use Nesto\Orm\Migration\Migrator;
use Nesto\Orm\Migration\Schema;
use Nesto\Orm\OrmException;
use Nesto\Orm\Schema\Normalizer;
use Nesto\Orm\SqlExpression;
use Nesto\Tests\Orm\Fixtures\SchemaOptions\OptOwner;
use Nesto\Tests\Orm\Fixtures\SchemaOptions\OptPet;
use Nesto\Tests\Orm\Fixtures\SchemaOptions\OptToy;
use Nesto\Tests\Orm\Fixtures\SchemaOptions\OptView;
use PDO;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixtures/SchemaOptions.php';

/** Column / constraint options in metadata and DDL, the normalizer, migration transaction modes. */
final class SchemaEngineTest extends TestCase
{
    protected function setUp(): void
    {
        MetadataRegistry::clear();
    }

    public function testColumnAndConstraintOptionsReachTheMetadata(): void
    {
        $owner = MetadataRegistry::get(OptOwner::class);
        $first = $owner->column('firstName');
        $this->assertSame(['Given name', 'utf8mb4', 'utf8mb4_bin', 40], [$first->comment, $first->charset, $first->collation, $first->length]);
        $this->assertTrue($owner->column('age')->unsigned);
        $this->assertSame(['enum', ['a', 'b'], 'opt_kind'], [$owner->column('kind')->type, $owner->column('kind')->enumValues, $owner->column('kind')->enumName]);
        $this->assertSame(['enum', ['draft', 'published']], [$owner->column('status')->type, $owner->column('status')->enumValues]);
        $this->assertInstanceOf(SqlExpression::class, $owner->column('seenAt')->default);
        $this->assertSame([['columns' => ['first_name', 'last_name'], 'name' => 'uq_opt_owners_names'], ['columns' => ['code'], 'name' => null]], $owner->uniques);
        $this->assertSame([['expression' => 'age >= 0', 'name' => null], ['expression' => 'age < 200', 'name' => 'chk_named']], $owner->checks);
        $this->assertSame([['columns' => ['last_name'], 'unique' => false, 'name' => 'idx_opt_last', 'where' => 'last_name IS NOT NULL']], $owner->indexes);

        $pet = MetadataRegistry::get(OptPet::class);
        $relation = $pet->relation('owner');
        $this->assertSame(['CASCADE', 'CASCADE', 'fk_pet_owner', true], [$relation->onDelete, $relation->onUpdate, $relation->foreignKeyConstraintName, $relation->createForeignKeyConstraints]);
        $this->assertFalse($pet->relation('vet')->createForeignKeyConstraints);
        $toys = $pet->relation('toys');
        $this->assertSame(['pet_id', 'toy_id', 'fk_pt_pet', null, 'RESTRICT'], [$toys->joinTableColumn, $toys->inverseJoinColumn, $toys->joinForeignKeyName, $toys->inverseJoinForeignKeyName, $toys->onDelete]);
        $this->assertFalse($pet->relation('legacyToys')->joinTableSynchronize);
        $this->assertFalse(MetadataRegistry::get(OptView::class)->synchronize);
    }

    public function testEntitySchemaBuilderMapsOptionsToTheSchemaModel(): void
    {
        $tables = (new EntitySchemaBuilder)->tables([OptOwner::class, OptPet::class, OptToy::class, OptView::class]);
        $this->assertSame(['opt_owners', 'opt_pets', 'opt_toys', 'opt_pet_toys'], array_keys($tables), 'synchronize: false tables are left out; join tables last');

        $owners = $tables['opt_owners'];
        $this->assertSame(['a', 'b'], $owners->columns['kind']->values);
        $partial = array_values(array_filter($owners->indexes, fn ($i) => $i->name === 'idx_opt_last'))[0];
        $this->assertSame([['last_name'], 'last_name IS NOT NULL', true], [$partial->columns, $partial->where, $partial->explicitName]);
        $checkNames = array_map(fn ($c) => $c->name, $owners->checks);
        $this->assertSame('chk_named', $checkNames[1]);
        $this->assertMatchesRegularExpression('/^chk_opt_owners_[0-9a-f]{8}$/', $checkNames[0]);

        $pets = $tables['opt_pets'];
        $this->assertCount(1, $pets->foreignKeys, 'vet: createForeignKeyConstraints false');
        $fk = $pets->foreignKeys[0];
        $this->assertSame([['owner_ref'], 'opt_owners', 'CASCADE', 'CASCADE', 'fk_pet_owner', true], [$fk->columns, $fk->referencedTable, $fk->onDelete, $fk->onUpdate, $fk->name, $fk->explicitName]);
        $this->assertArrayHasKey('vet_id', $pets->columns);

        $joins = $tables['opt_pet_toys']->foreignKeys;
        $this->assertSame([['RESTRICT', 'CASCADE', 'fk_pt_pet'], ['RESTRICT', 'CASCADE', null]], array_map(fn ($f) => [$f->onDelete, $f->onUpdate, $f->explicitName ? $f->name : null], $joins));
    }

    public function testDdlPerPlatform(): void
    {
        $bp = (new EntitySchemaBuilder)->blueprints([OptOwner::class])[0];
        $pdo = new PDO('sqlite::memory:');

        $mysql = implode("\n", (new Grammar(new Connection($pdo, 'mysql', null, 'mysql')))->compileCreate($bp));
        $this->assertStringContainsString("`first_name` VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Given name'", $mysql);
        $this->assertStringContainsString('`age` SMALLINT UNSIGNED NOT NULL DEFAULT 0', $mysql);
        $this->assertStringContainsString("`kind` ENUM('a', 'b') NOT NULL DEFAULT 'a'", $mysql);
        $this->assertStringContainsString('`seen_at` DATETIME DEFAULT CURRENT_TIMESTAMP', $mysql);
        $this->assertStringContainsString('CONSTRAINT `chk_named` CHECK (age < 200)', $mysql);
        $this->assertStringContainsString('CREATE UNIQUE INDEX `uq_opt_owners_names` ON `opt_owners` (`first_name`, `last_name`)', $mysql);
        $this->assertStringContainsString('CREATE INDEX `idx_opt_last` ON `opt_owners` (`last_name`)' . "\n", $mysql . "\n", 'no partial indexes on MySQL');

        $pg = implode("\n", (new Grammar(new Connection($pdo, 'pgsql')))->compileCreate($bp));
        $this->assertStringContainsString('DO $$ BEGIN CREATE TYPE "opt_kind" AS ENUM (\'a\', \'b\'); EXCEPTION WHEN duplicate_object THEN NULL; END $$', $pg);
        $this->assertStringContainsString('"kind" "opt_kind" NOT NULL DEFAULT \'a\'', $pg);
        $this->assertStringContainsString('"first_name" VARCHAR(40) COLLATE "utf8mb4_bin" NOT NULL', $pg);
        $this->assertStringContainsString('COMMENT ON COLUMN "opt_owners"."first_name" IS \'Given name\'', $pg);
        $this->assertStringContainsString('CREATE INDEX "idx_opt_last" ON "opt_owners" ("last_name") WHERE last_name IS NOT NULL', $pg);

        $pet = (new EntitySchemaBuilder)->blueprints([OptPet::class])[1];
        $sql = (new Grammar(new Connection($pdo, 'pgsql')))->compileCreate($pet)[0];
        $this->assertStringContainsString('CONSTRAINT "fk_pet_owner" FOREIGN KEY ("owner_ref") REFERENCES "opt_owners" ("id") ON DELETE CASCADE ON UPDATE CASCADE', $sql);
        $this->assertStringNotContainsString('"vet_id") REFERENCES', $sql);
    }

    public function testNormalizerCanonicalTypesAndDefaults(): void
    {
        $this->assertSame(['int', false], Normalizer::type('pgsql', 'SERIAL'));
        $this->assertSame(['varchar(255)', false], Normalizer::type('pgsql', 'character varying(255)'));
        $this->assertSame(['timestamp', false], Normalizer::type('pgsql', 'timestamp(6) without time zone'));
        $this->assertSame(['timestamptz(3)', false], Normalizer::type('pgsql', 'timestamp(3) with time zone'));
        $this->assertSame(['numeric(10,2)', false], Normalizer::type('pgsql', 'DECIMAL(10, 2)'));
        $this->assertSame(['opt_kind', false], Normalizer::type('pgsql', '"opt_kind"'));
        $this->assertSame(['int', true], Normalizer::type('mysql', 'int(10) unsigned'));
        $this->assertSame(['tinyint(1)', false], Normalizer::type('mysql', 'BOOLEAN'));
        $this->assertSame(['longtext', false], Normalizer::type('mariadb', 'JSON'));
        $this->assertSame(["enum('a','b')", false], Normalizer::type('mysql', "ENUM('a', 'b')"));
        $this->assertSame(['decimal(10,2)', false], Normalizer::type('sqlite', 'DECIMAL(10, 2)'));

        $this->assertSame('abc', Normalizer::parseDefault("'abc'::character varying"));
        $this->assertSame("it's", Normalizer::parseDefault("'it''s'"));
        $this->assertSame('[]', Normalizer::parseDefault("_utf8mb4\\'[]\\'"));
        $this->assertSame(-1, Normalizer::parseDefault('(-1)'));
        $this->assertSame('0.00', Normalizer::parseDefault('0.00'));
        $this->assertTrue(Normalizer::parseDefault('true'));
        $this->assertNull(Normalizer::parseDefault('NULL'));
        $this->assertEquals(new SqlExpression('current_timestamp()'), Normalizer::parseDefault('current_timestamp()'));
        $this->assertSame('1.5', Normalizer::number('1.500'));
        $this->assertSame('0', Normalizer::number('-0.00'));
        $this->assertSame('current_timestamp', Normalizer::expression('NOW()'));
        $this->assertSame(Normalizer::expression('"deleted_at" IS NULL'), Normalizer::expression('(deleted_at IS NULL)'));
        $this->assertSame('NO ACTION', Normalizer::action('mysql', 'RESTRICT'));
        $this->assertSame('RESTRICT', Normalizer::action('pgsql', 'RESTRICT'));
        $this->assertSame('SET DEFAULT', Normalizer::action('pgsql', 'DEFAULT'));
    }

    public function testRendererExportsReadableBlueprintCode(): void
    {
        $bp = new Blueprint('t');
        $bp->id();
        $bp->string('name', 80)->nullable()->comment("it's");
        $bp->decimal('price', 8, 3)->default('0.000');
        $bp->datetime('seen_at')->useCurrent();
        $bp->enum('kind', ['a', 'b'])->default('a');
        $bp->index(['name'], 'idx_t_name', where: 'name IS NOT NULL');
        $bp->check('price >= 0', 'chk_price');
        $bp->foreign('owner_id', 'fk_owner')->references('id')->on('owners')->onDelete('CASCADE');
        $lines = (new MigrationRenderer)->blueprintLines($bp, true);
        $this->assertSame([
            '$table->id();',
            "\$table->string('name', 80)->nullable()->comment('it\\'s');",
            "\$table->decimal('price', 8, 3)->default('0.000');",
            "\$table->datetime('seen_at')->default(new SqlExpression('CURRENT_TIMESTAMP'));",
            "\$table->enum('kind', ['a', 'b'])->default('a');",
            "\$table->index(['name'], 'idx_t_name', where: 'name IS NOT NULL');",
            "\$table->check('price >= 0', 'chk_price');",
            "\$table->foreign('owner_id', 'fk_owner')->references('id')->on('owners')->onDelete('CASCADE');",
        ], $lines);
    }

    public function testMigrationTransactionModes(): void
    {
        $connection = Connection::fromOptions(['type' => 'sqlite', 'database' => ':memory:']);
        $create = fn (string $table) => new class ($table) extends Migration {
            public function __construct(private readonly string $table) {}

            public function up(Schema $schema): void
            {
                $schema->createTable($this->table, fn (Blueprint $t) => $t->id());
            }

            public function down(Schema $schema): void
            {
                $schema->dropTable($this->table);
            }

            public function name(): string
            {
                return 'create_' . $this->table;
            }
        };
        $failing = new class extends Migration {
            public function up(Schema $schema): void
            {
                throw new \RuntimeException('boom');
            }

            public function down(Schema $schema): void
            {
            }
        };
        $schema = new Schema($connection);

        // all (default): one transaction for the batch — nothing is kept
        $migrator = new Migrator($connection, [$create('ta'), $failing]);
        try {
            $migrator->run();
            $this->fail('the second migration fails');
        } catch (\RuntimeException) {
        }
        $this->assertFalse($schema->hasTable('ta'));
        $this->assertSame([false, false], array_column($migrator->status(), 'ran'));

        // each: the first migration stays applied and recorded
        try {
            $migrator->run('each');
        } catch (\RuntimeException) {
        }
        $this->assertTrue($schema->hasTable('ta'));
        $this->assertSame([true, false], array_column($migrator->status(), 'ran'));

        // fake: recorded without running
        $fake = new Migrator($connection, [$create('ta'), $create('tb')]);
        $this->assertSame(['create_tb'], $fake->run(fake: true));
        $this->assertFalse($schema->hasTable('tb'));
        $this->assertSame('create_tb', $fake->revert(fake: true));

        // a migration that disables transactions cannot run in mode "all"
        $noTx = new class extends Migration {
            public function up(Schema $schema): void
            {
            }

            public function down(Schema $schema): void
            {
            }

            public function transactional(): ?bool
            {
                return false;
            }
        };
        $this->expectException(OrmException::class);
        $this->expectExceptionMessage('"all" transaction mode');
        (new Migrator($connection, [$noTx]))->run();
    }

    public function testSynchronizeWarnsInProduction(): void
    {
        $messages = [];
        putenv('APP_ENV=production');
        try {
            $ds = new DataSource(['type' => 'sqlite', 'database' => ':memory:', 'entities' => [OptToy::class], 'synchronize' => true, 'logger' => function (string $m) use (&$messages): void {
                $messages[] = $m;
            }]);
            $ds->initialize();
        } finally {
            putenv('APP_ENV');
        }
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('APP_ENV is production', $messages[0]);
        $this->assertTrue($ds->schema()->hasTable('opt_toys'), 'it still synchronizes (like TypeORM)');
        $this->assertSame([], $ds->logSchema());
        $this->assertNull($ds->generateMigration('Nothing'));
    }
}
