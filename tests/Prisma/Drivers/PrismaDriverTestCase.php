<?php

declare(strict_types=1);

namespace Nesto\Tests\Prisma\Drivers;

use Nesto\Orm\Connection;
use Nesto\Orm\Schema\Introspection\Introspector;
use Nesto\Prisma\Migrate\BufferedReporter;
use Nesto\Prisma\Migrate\DatabaseReset;
use Nesto\Prisma\Migrate\DatabaseSchema;
use Nesto\Prisma\Migrate\DatabaseUrl;
use Nesto\Prisma\Migrate\DbPull;
use Nesto\Prisma\Migrate\DbPush;
use Nesto\Prisma\Migrate\Differ;
use Nesto\Prisma\Migrate\Migrate;
use Nesto\Prisma\Migrate\MigrateDiff;
use Nesto\Prisma\Migrate\MigrationsTable;
use Nesto\Prisma\Migrate\SchemaTranslator;
use Nesto\Prisma\PrismaException;
use Nesto\Prisma\PrismaProject;
use PHPUnit\Framework\TestCase;

/**
 * Prisma Migrate / db push / db pull against a real database. SQLite (a temporary file) always runs; the
 * server databases run when NESTO_TEST_PGSQL_URL / NESTO_TEST_MYSQL_URL / NESTO_TEST_MARIADB_URL are set
 * (a throwaway database whose name contains "test": every table and type in it is dropped). MariaDB uses
 * the mysql provider. Shadow databases are created on the same server (the user needs CREATE DATABASE).
 */
abstract class PrismaDriverTestCase extends TestCase
{
    protected string $dir;
    protected string $url;

    /** Database URL for this driver, or null to skip. */
    abstract protected static function databaseUrl(): ?string;

    /** "postgresql" | "mysql" | "sqlite" */
    abstract protected static function provider(): string;

    protected static function urlFromEnv(string $var): ?string
    {
        $url = getenv($var);
        if ($url === false || trim($url) === '') {
            return null;
        }
        $url = trim($url);
        if (! str_contains(strtolower((string) parse_url($url, PHP_URL_PATH)), 'test')) {
            throw new \RuntimeException("$var must point at a throwaway database whose name contains \"test\".");
        }

        return $url;
    }

    protected function setUp(): void
    {
        $url = static::databaseUrl();
        if ($url === null) {
            $this->markTestSkipped(static::class . ': database URL not configured.');
        }
        $this->url = $url;
        $this->dir = sys_get_temp_dir() . '/nesto_prisma_' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/prisma', 0777, true);
        $this->resetDatabase();
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            $this->resetDatabase();
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    private function resetDatabase(): void
    {
        if (static::provider() === 'sqlite') {
            return; // a fresh file per test
        }
        DatabaseReset::reset((new DatabaseUrl($this->url, static::provider()))->connectOrCreate());
    }

    protected function supportsEnums(): bool
    {
        return static::provider() !== 'sqlite';
    }

    /** Write prisma/schema.prisma with these models and return the project. */
    protected function schema(string $models): PrismaProject
    {
        file_put_contents($this->dir . '/prisma/schema.prisma', sprintf(
            "generator client {\n  provider = \"nesto-client\"\n}\n\ndatasource db {\n  provider = \"%s\"\n  url      = \"%s\"\n}\n\n%s",
            static::provider(),
            $this->url,
            $models,
        ));

        return new PrismaProject($this->dir . '/prisma/schema.prisma', $this->dir);
    }

    protected function blog(string $userExtra = '', string $postExtra = ''): string
    {
        $enum = $this->supportsEnums();
        $list = static::provider() === 'postgresql';

        return "model User {\n  id        Int      @id @default(autoincrement())\n  email     String   @unique\n  name      String?\n"
            . ($enum ? "  role      Role     @default(USER)\n" : '')
            . "  createdAt DateTime @default(now())\n  updatedAt DateTime @updatedAt\n  posts     Post[]\n  profile   Profile?\n$userExtra}\n\n"
            . "model Profile {\n  id     String @id @default(uuid())\n  bio    String @default(\"\")\n  user   User   @relation(fields: [userId], references: [id], onDelete: Cascade)\n  userId Int    @unique\n}\n\n"
            . "model Post {\n  id         Int        @id @default(autoincrement())\n  title      String\n  views      Int        @default(0)\n  rating     Float?\n  price      Decimal    @default(9.99)\n  published  Boolean    @default(false)\n  meta       Json?\n"
            . ($list ? "  tags       String[]\n" : '')
            . "  author     User?      @relation(fields: [authorId], references: [id])\n  authorId   Int?\n  categories Category[]\n$postExtra\n  @@unique([authorId, title])\n  @@index([title])\n}\n\n"
            . "model Category {\n  id    Int    @id @default(autoincrement())\n  name  String @db_NAME\n  posts Post[]\n}\n"
            . ($enum ? "\nenum Role {\n  USER\n  ADMIN\n}\n" : '');
    }

    protected function blogSchema(string $userExtra = '', string $postExtra = ''): string
    {
        return str_replace(' @db_NAME', static::provider() === 'sqlite' ? '' : ' @db.VarChar(100)', $this->blog($userExtra, $postExtra));
    }

    protected function connection(PrismaProject $project): Connection
    {
        return $project->connect();
    }

    protected function q(Connection $connection, string $name): string
    {
        return $connection->quote($name);
    }

    protected function inSync(PrismaProject $project): bool
    {
        $connection = $project->connect();
        $plan = (new Differ($connection))->plan(DatabaseSchema::introspect($connection), SchemaTranslator::translate($project->document()));
        if (! $plan->isEmpty()) {
            fwrite(STDERR, \Nesto\Prisma\Migrate\DiffSummary::describe($plan) . "\n" . implode("\n", $plan->statements(new \Nesto\Orm\Migration\Schema($connection))) . "\n");
        }

        return $plan->isEmpty();
    }

    // ---- db push ------------------------------------------------------------------------------------

    public function testDbPushCreatesPrismasSchemaAndIsIdempotent(): void
    {
        $project = $this->schema($this->blogSchema());
        $statements = (new DbPush($project, new BufferedReporter()))->push();
        self::assertNotEmpty($statements);
        $connection = $project->connect();
        $introspector = Introspector::for($connection);
        foreach (['User', 'Profile', 'Post', 'Category', '_CategoryToPost'] as $table) {
            self::assertContains($table, $introspector->tableNames());
        }
        $post = $introspector->table('Post');
        $fk = $post->foreignKeyOn(['authorId']);
        self::assertSame('Post_authorId_fkey', $fk->name);
        self::assertSame('SET NULL', strtoupper((string) $fk->onDelete));
        self::assertSame('CASCADE', strtoupper((string) $fk->onUpdate));
        $indexNames = array_map(fn ($i) => $i->name, $post->indexes);
        self::assertContains('Post_authorId_title_key', $indexNames);
        self::assertContains('Post_title_idx', $indexNames);
        self::assertContains('User_email_key', array_map(fn ($i) => $i->name, $introspector->table('User')->indexes));
        if (static::provider() === 'postgresql') {
            self::assertSame('Post_pkey', $post->primaryKeyName);
            self::assertSame('_CategoryToPost_AB_pkey', $introspector->table('_CategoryToPost')->primaryKeyName);
            self::assertSame(['Role' => ['USER', 'ADMIN']], DatabaseSchema::postgresEnums($connection));
        } else {
            self::assertContains('_CategoryToPost_AB_unique', array_map(fn ($i) => $i->name, $introspector->table('_CategoryToPost')->indexes));
        }
        self::assertContains('_CategoryToPost_B_index', array_map(fn ($i) => $i->name, $introspector->table('_CategoryToPost')->indexes));

        // data round trip through the new tables
        $q = fn (string $n) => $connection->quote($n);
        $connection->execute('INSERT INTO ' . $q('User') . ' (' . $q('email') . ', ' . $q('updatedAt') . ') VALUES (:e, :u)', ['e' => 'a@b.c', 'u' => '2024-01-01 10:00:00']);
        $user = $connection->fetchOne('SELECT * FROM ' . $q('User'));
        self::assertSame('a@b.c', $user['email']);
        if ($this->supportsEnums()) {
            self::assertSame('USER', $user['role']);
        }
        self::assertNotNull($user['createdAt']);

        self::assertSame([], (new DbPush($project, new BufferedReporter()))->push(), 'a second push changes nothing');
        self::assertTrue($this->inSync($project));
    }

    public function testDbPushNeedsAcceptDataLossOrForceReset(): void
    {
        $project = $this->schema($this->blogSchema());
        (new DbPush($project, new BufferedReporter()))->push();
        $connection = $project->connect();
        $q = fn (string $n) => $connection->quote($n);
        $connection->execute('INSERT INTO ' . $q('User') . ' (' . $q('email') . ', ' . $q('name') . ', ' . $q('updatedAt') . ") VALUES ('a@b.c', 'Ann', '2024-01-01 10:00:00')");

        // dropping a column that holds data
        $project = $this->schema(str_replace("  name      String?\n", '', $this->blogSchema()));
        try {
            (new DbPush($project, new BufferedReporter()))->push();
            self::fail('expected the data loss to be refused');
        } catch (PrismaException $e) {
            self::assertStringContainsString('You are about to drop the column `name` on the `User` table, which still contains 1 non-null values.', $e->getMessage());
            self::assertStringContainsString('--accept-data-loss', $e->getMessage());
        }
        (new DbPush($project, new BufferedReporter()))->push(acceptDataLoss: true);
        self::assertTrue($this->inSync($project));

        // a required column without default cannot be added to a non-empty table
        $project = $this->schema(str_replace("  updatedAt DateTime @updatedAt\n", "  updatedAt DateTime @updatedAt\n  nickname  String\n", str_replace("  name      String?\n", '', $this->blogSchema())));
        try {
            (new DbPush($project, new BufferedReporter()))->push(acceptDataLoss: true);
            self::fail('expected an unexecutable step');
        } catch (PrismaException $e) {
            self::assertStringContainsString('Added the required column `nickname` to the `User` table without a default value. There are 1 rows in this table, it is not possible to execute this step.', $e->getMessage());
        }
        (new DbPush($project, new BufferedReporter()))->push(forceReset: true);
        self::assertTrue($this->inSync($project));
        self::assertSame(0, (int) $project->connect()->fetchColumn('SELECT COUNT(*) FROM ' . $q('User')));
    }

    // ---- db pull ------------------------------------------------------------------------------------

    public function testDbPullRoundTrip(): void
    {
        $project = $this->schema($this->blogSchema());
        (new DbPush($project, new BufferedReporter()))->push();

        // re-introspection keeps the schema's names (posts, author, categories, @updatedAt, uuid())
        $pulled = (new DbPull($project, new BufferedReporter()))->pull();
        foreach (['posts     Post[]', 'author ', 'categories Category[]', '@updatedAt', '@default(uuid())', '@@unique([authorId, title])', '@@index([title])', 'generator client'] as $needle) {
            self::assertStringContainsString($needle, $pulled);
        }
        file_put_contents($project->schemaPath, $pulled);
        $project->refresh();
        self::assertTrue($this->inSync($project), "the pulled schema describes the database:\n$pulled");

        // a fresh introspection names relations after the models, like Prisma
        $fresh = (new DbPull($project, new BufferedReporter()))->pull(force: true);
        self::assertStringContainsString('User      User?      @relation(fields: [authorId], references: [id])', $fresh);
        self::assertMatchesRegularExpression('/\n  Post\s+Post\[\]\n/', $fresh);
        if (static::provider() !== 'sqlite') {
            self::assertStringContainsString('@db.VarChar(100)', $fresh);
        }
        file_put_contents($project->schemaPath, $fresh);
        $project->refresh();
        self::assertTrue($this->inSync($project), "the fresh schema describes the database:\n$fresh");
    }

    public function testDbPullMapsNamesAndForeignKeysOfAnExistingDatabase(): void
    {
        $project = $this->schema('');
        $connection = $project->connect(true);
        $q = fn (string $n) => $connection->quote($n);
        $serial = match (static::provider()) {
            'postgresql' => 'SERIAL PRIMARY KEY',
            'mysql' => 'INTEGER AUTO_INCREMENT PRIMARY KEY',
            default => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        };
        $connection->execute('CREATE TABLE ' . $q('blog-authors') . ' (' . $q('id') . " $serial, " . $q('full name') . ' VARCHAR(50) NOT NULL)');
        $connection->execute('CREATE TABLE ' . $q('articles') . ' (' . $q('id') . " $serial, " . $q('author_id') . ' INTEGER NOT NULL, '
            . $q('reviewer_id') . ' INTEGER, CONSTRAINT ' . $q('fk_author') . ' FOREIGN KEY (' . $q('author_id') . ') REFERENCES ' . $q('blog-authors') . ' (' . $q('id') . ') ON DELETE CASCADE, '
            . 'FOREIGN KEY (' . $q('reviewer_id') . ') REFERENCES ' . $q('blog-authors') . ' (' . $q('id') . '))');
        $connection->execute('CREATE TABLE ' . $q('logs') . ' (' . $q('message') . ' TEXT)');
        $schema = (new DbPull($project, new BufferedReporter()))->pull();

        self::assertStringContainsString('model blog_authors {', $schema);
        self::assertStringContainsString('@@map("blog-authors")', $schema);
        self::assertMatchesRegularExpression('/full_name\s+String\s+@map\("full name"\)/', $schema);
        self::assertStringContainsString('@relation("articles_author_idToblog_authors", fields: [author_id], references: [id], onDelete: Cascade', $schema);
        self::assertStringContainsString('@relation("articles_reviewer_idToblog_authors", fields: [reviewer_id], references: [id]', $schema);
        self::assertMatchesRegularExpression('/articles_articles_author_idToblog_authors\s+articles\[\]\s+@relation\("articles_author_idToblog_authors"\)/', $schema);
        self::assertStringContainsString('/// The underlying table does not contain a valid unique identifier and can therefore currently not be handled by Prisma Client.', $schema);
        self::assertStringContainsString('@@ignore', $schema);
        self::assertStringContainsString('map: "fk_author"', $schema);
        file_put_contents($project->schemaPath, $schema);
        $project->refresh();
        self::assertTrue($this->inSync($project), "the pulled schema validates and describes the database:\n$schema");
    }

    // ---- migrate ------------------------------------------------------------------------------------

    public function testMigrateDevCreatesAppliesAndRecordsMigrations(): void
    {
        $project = $this->schema($this->blogSchema());
        $result = (new Migrate($project, new BufferedReporter()))->dev('init');
        self::assertNotNull($result->created);
        self::assertMatchesRegularExpression('/^\d{14}_init$/', $result->created->name);
        $sql = $result->created->sql();
        self::assertStringContainsString("-- CreateTable\nCREATE TABLE ", $sql);
        if (static::provider() !== 'sqlite') {
            self::assertStringContainsString("-- AddForeignKey\nALTER TABLE ", $sql);
        }
        self::assertStringContainsString('Post_authorId_title_key', $sql);
        if (static::provider() === 'postgresql') {
            self::assertStringContainsString("-- CreateEnum\nCREATE TYPE \"Role\" AS ENUM ('USER', 'ADMIN');", $sql);
            self::assertStringContainsString('CONSTRAINT "User_pkey" PRIMARY KEY ("id")', $sql);
        }
        self::assertStringContainsString('provider = "' . static::provider() . '"', (string) file_get_contents($this->dir . '/prisma/migrations/migration_lock.toml'));

        $records = (new MigrationsTable($project->connect()))->records();
        self::assertCount(1, $records);
        self::assertSame($result->created->name, $records[0]->name);
        self::assertSame(hash('sha256', $sql), $records[0]->checksum);
        self::assertTrue($records[0]->isApplied());
        self::assertSame(1, $records[0]->appliedStepsCount);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $records[0]->id);
        self::assertTrue($this->inSync($project));

        $reporter = new BufferedReporter();
        $again = (new Migrate($project, $reporter))->dev('nothing');
        self::assertNull($again->created);
        self::assertStringContainsString('Already in sync', $reporter->output());

        $project = $this->schema($this->blogSchema("  bio       String?\n"));
        $second = (new Migrate($project, new BufferedReporter()))->dev('add bio');
        self::assertStringEndsWith('_add_bio', $second->created->name);
        self::assertMatchesRegularExpression('/ALTER TABLE .User. ADD COLUMN .bio./', $second->created->sql());
        self::assertTrue($this->inSync($project));
        $status = (new Migrate($project, new BufferedReporter()))->status();
        self::assertTrue($status->isUpToDate());
        self::assertSame([$result->created->name, $second->created->name], $status->applied);
    }

    public function testMigrateDevWarnsBeforeDataLoss(): void
    {
        $project = $this->schema($this->blogSchema());
        (new Migrate($project, new BufferedReporter()))->dev('init');
        $connection = $project->connect();
        $q = fn (string $n) => $connection->quote($n);
        $connection->execute('INSERT INTO ' . $q('User') . ' (' . $q('email') . ', ' . $q('name') . ', ' . $q('updatedAt') . ") VALUES ('a@b.c', 'Ann', '2024-01-01 10:00:00')");

        $project = $this->schema(str_replace("  name      String?\n", '', $this->blogSchema()));
        $reporter = new BufferedReporter([false]);
        try {
            (new Migrate($project, $reporter))->dev('drop name');
            self::fail('expected the confirmation to stop it');
        } catch (PrismaException $e) {
            self::assertSame('Migration cancelled.', $e->getMessage());
        }
        self::assertStringContainsString('You are about to drop the column `name` on the `User` table, which still contains 1 non-null values.', $reporter->output());
        self::assertSame([], glob($this->dir . '/prisma/migrations/*_drop_name') ?: []);

        $result = (new Migrate($project, new BufferedReporter([true])))->dev('drop name');
        self::assertStringStartsWith("/*\n  Warnings:\n\n  - You are about to drop the column `name` on the `User` table. All the data in the column will be lost.\n", $result->created->sql());
        self::assertTrue($this->inSync($project));
    }

    public function testMigrateDevDetectsDriftAndResetsAfterConfirmation(): void
    {
        $project = $this->schema($this->blogSchema());
        (new Migrate($project, new BufferedReporter()))->dev('init');
        $connection = $project->connect();
        $connection->execute('CREATE TABLE ' . $connection->quote('extra') . ' (' . $connection->quote('id') . ' INTEGER PRIMARY KEY)');

        try {
            (new Migrate($project, new BufferedReporter()))->dev('next');
            self::fail('expected drift to stop a non-interactive run');
        } catch (PrismaException $e) {
            self::assertStringContainsString('We need to reset the', $e->getMessage());
            self::assertStringContainsString('non-interactive', $e->getMessage());
        }
        $reporter = new BufferedReporter([true]);
        $result = (new Migrate($project, $reporter))->dev('next');
        self::assertStringContainsString("Drift detected: Your database schema is not in sync with your migration history.", $reporter->output());
        self::assertStringContainsString("[+] Added tables\n  - extra", $reporter->output());
        self::assertTrue($result->reset);
        self::assertNull($result->created);
        self::assertNotContains('extra', Introspector::for($project->connect())->tableNames());
        self::assertTrue($this->inSync($project));
    }

    public function testMigrateDevDetectsEditedMigrations(): void
    {
        $project = $this->schema($this->blogSchema());
        $init = (new Migrate($project, new BufferedReporter()))->dev('init')->created;
        file_put_contents($init->path . '/migration.sql', $init->sql() . "\n-- edited\n");
        $reporter = new BufferedReporter([true]);
        $result = (new Migrate($project, $reporter))->dev();
        self::assertStringContainsString(sprintf('The migration `%s` was modified after it was applied.', $init->name), $reporter->output());
        self::assertTrue($result->reset);
        self::assertSame([$init->name], $result->applied);
    }

    public function testCreateOnlyDeployStatusAndReset(): void
    {
        $project = $this->schema($this->blogSchema());
        $created = (new Migrate($project, new BufferedReporter()))->dev('init', createOnly: true);
        self::assertNotNull($created->created);
        self::assertSame([], $created->applied);
        $status = (new Migrate($project, new BufferedReporter()))->status();
        self::assertSame([$created->created->name], $status->pending);
        self::assertFalse($status->isUpToDate());

        $applied = (new Migrate($project, new BufferedReporter()))->deploy();
        self::assertSame([$created->created->name], $applied);
        self::assertTrue((new Migrate($project, new BufferedReporter()))->status()->isUpToDate());
        $reporter = new BufferedReporter();
        self::assertSame([], (new Migrate($project, $reporter))->deploy());
        self::assertStringContainsString('No pending migrations to apply.', $reporter->output());

        $connection = $project->connect();
        $connection->execute('INSERT INTO ' . $connection->quote('Category') . ' (' . $connection->quote('name') . ") VALUES ('news')");
        self::assertSame([$created->created->name], (new Migrate($project, new BufferedReporter()))->reset());
        self::assertSame(0, (int) $project->connect()->fetchColumn('SELECT COUNT(*) FROM ' . $connection->quote('Category')));
        self::assertTrue($this->inSync($project));
    }

    public function testFailedMigrationsBlockDeployUntilResolved(): void
    {
        $project = $this->schema($this->blogSchema());
        $init = (new Migrate($project, new BufferedReporter()))->dev('init')->created;
        $broken = $this->dir . '/prisma/migrations/29990101000000_broken';
        mkdir($broken);
        file_put_contents($broken . '/migration.sql', "CREATE TABLE nope_table (id INTEGER PRIMARY KEY);\nTHIS IS NOT SQL;\n");
        try {
            (new Migrate($project, new BufferedReporter()))->deploy();
            self::fail('expected the broken migration to fail');
        } catch (PrismaException $e) {
            self::assertStringContainsString("A migration failed to apply.", $e->getMessage());
            self::assertStringContainsString('Migration name: 29990101000000_broken', $e->getMessage());
        }
        $status = (new Migrate($project, new BufferedReporter()))->status();
        self::assertSame(['29990101000000_broken'], $status->failed);
        try {
            (new Migrate($project, new BufferedReporter()))->deploy();
            self::fail('expected P3009');
        } catch (PrismaException $e) {
            self::assertStringContainsString('migrate found failed migrations in the target database', $e->getMessage());
            self::assertStringContainsString('The `29990101000000_broken` migration started at', $e->getMessage());
        }

        $connection = $project->connect();
        $connection->execute('DROP TABLE IF EXISTS nope_table');
        (new Migrate($project, new BufferedReporter()))->resolveRolledBack('29990101000000_broken');
        file_put_contents($broken . '/migration.sql', "CREATE TABLE nope_table (id INTEGER PRIMARY KEY);\n");
        self::assertSame(['29990101000000_broken'], (new Migrate($project, new BufferedReporter()))->deploy());

        $manual = $this->dir . '/prisma/migrations/29990102000000_manual';
        mkdir($manual);
        file_put_contents($manual . '/migration.sql', "CREATE TABLE manual_table (id INTEGER PRIMARY KEY);\n");
        (new Migrate($project, new BufferedReporter()))->resolveApplied('29990102000000_manual');
        self::assertSame([], (new Migrate($project, new BufferedReporter()))->deploy(), 'resolved migrations are not run');
        self::assertNotContains('manual_table', Introspector::for($project->connect())->tableNames());
        try {
            (new Migrate($project, new BufferedReporter()))->resolveApplied('29990102000000_manual');
            self::fail('expected P3008');
        } catch (PrismaException $e) {
            self::assertStringContainsString('is already recorded as applied', $e->getMessage());
        }
        try {
            (new Migrate($project, new BufferedReporter()))->resolveRolledBack($init->name);
            self::fail('expected P3012');
        } catch (PrismaException $e) {
            self::assertStringContainsString('is not in a failed state', $e->getMessage());
        }
    }

    public function testEnumChanges(): void
    {
        if (! $this->supportsEnums()) {
            // like Prisma: SQLite schemas cannot declare enums
            $this->expectException(\Nesto\Prisma\Schema\SchemaException::class);
            $this->expectExceptionMessage('You defined the enum `Role`. But the current connector does not support enums.');
            $this->schema($this->blogSchema() . "\nenum Role {\n  USER\n}\n")->document();
        }
        $project = $this->schema($this->blogSchema());
        (new Migrate($project, new BufferedReporter()))->dev('init');
        $connection = $project->connect();
        $q = fn (string $n) => $connection->quote($n);

        $project = $this->schema(str_replace("  USER\n  ADMIN\n", "  USER\n  EDITOR\n  ADMIN\n", $this->blogSchema()));
        $added = (new Migrate($project, new BufferedReporter()))->dev('editor')->created->sql();
        self::assertStringContainsString(static::provider() === 'postgresql' ? "ALTER TYPE \"Role\" ADD VALUE 'EDITOR' AFTER 'USER';" : "ENUM('USER', 'EDITOR', 'ADMIN')", $added);
        self::assertStringNotContainsString('Warnings', $added);
        $connection->execute('INSERT INTO ' . $q('User') . ' (' . $q('email') . ', ' . $q('role') . ', ' . $q('updatedAt') . ") VALUES ('e@x.y', 'EDITOR', '2024-01-01 10:00:00')");
        $connection->execute('INSERT INTO ' . $q('User') . ' (' . $q('email') . ', ' . $q('role') . ', ' . $q('updatedAt') . ") VALUES ('a@x.y', 'ADMIN', '2024-01-01 10:00:00')");

        // removing a value that is in use: warned, and the database refuses the cast
        $project = $this->schema($this->blogSchema());
        $reporter = new BufferedReporter([true]);
        $failed = false;
        try {
            (new Migrate($project, $reporter))->dev('remove editor');
        } catch (PrismaException $e) {
            $failed = true;
        }
        self::assertStringContainsString('The values [EDITOR] on the enum', $reporter->output());
        self::assertTrue($failed, 'the database refuses to drop an enum value still in use');
        $connection = $project->connect();
        $connection->execute('DELETE FROM ' . $q('User') . " WHERE " . $q('email') . " = 'e@x.y'");
        (new Migrate($project, new BufferedReporter()))->resolveRolledBack(basename((string) glob($this->dir . '/prisma/migrations/*_remove_editor')[0]));
        // the failed migration (already written) applies now
        $result = (new Migrate($project, new BufferedReporter([true])))->dev();
        self::assertContains(basename((string) glob($this->dir . '/prisma/migrations/*_remove_editor')[0]), $result->applied);
        self::assertTrue($this->inSync($project));
        self::assertSame('ADMIN', $project->connect()->fetchColumn('SELECT ' . $q('role') . ' FROM ' . $q('User')));
    }

    public function testMigrateDiff(): void
    {
        $project = $this->schema($this->blogSchema());
        $diff = new MigrateDiff($this->dir);
        $result = $diff->diff(['empty'], ['schema-datamodel', 'prisma/schema.prisma']);
        self::assertFalse($result['empty']);
        self::assertStringContainsString("[+] Added tables\n  - User\n  - Profile\n  - Post\n  - Category\n  - _CategoryToPost", $result['summary']);
        self::assertStringContainsString('-- CreateTable', $result['sql']);

        (new Migrate($project, new BufferedReporter()))->dev('init');
        self::assertTrue($diff->diff(['migrations', 'prisma/migrations'], ['schema-datamodel', 'prisma/schema.prisma'])['empty']);
        self::assertTrue($diff->diff(['schema-datasource', 'prisma/schema.prisma'], ['schema-datamodel', 'prisma/schema.prisma'])['empty']);
        $toEmpty = $diff->diff(['url', $this->url === 'file:./dev.db' ? 'file:' . $this->dir . '/prisma/dev.db' : $this->url], ['empty']);
        self::assertStringContainsString("[-] Removed tables\n", $toEmpty['summary']);
        self::assertStringContainsString('DROP TABLE', $toEmpty['sql']);

        $this->schema($this->blogSchema("  bio       String?\n"));
        $changed = $diff->diff(['migrations', 'prisma/migrations'], ['schema-datamodel', 'prisma/schema.prisma']);
        self::assertSame("[*] Changed the `User` table\n  [+] Added column `bio`", $changed['summary']);
    }
}
