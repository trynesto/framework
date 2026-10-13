<?php

declare(strict_types=1);

namespace Nesto\Tests\Prisma;

use Nesto\Cli\Application;
use Nesto\Cli\Commands\BuiltIn;
use Nesto\Cli\Output;
use Nesto\Cli\ProjectContext;
use Nesto\Orm\Connection;
use PHPUnit\Framework\TestCase;

/** The prisma:* console commands, end to end on SQLite. */
final class PrismaCommandsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nesto_prisma_cli_' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        file_put_contents($this->dir . '/composer.json', '{}');
    }

    protected function tearDown(): void
    {
        unset($_ENV['DATABASE_URL'], $_SERVER['DATABASE_URL']);
        putenv('DATABASE_URL');
        unset($_ENV['DIRECT_URL'], $_SERVER['DIRECT_URL']);
        putenv('DIRECT_URL');
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** @param list<string> $argv @return array{0: int, 1: string} */
    private function console(array $argv, array $config = [], ?string $input = null): array
    {
        $out = Output::memory();
        $app = (new Application())->setOutput($out)->setContext(new ProjectContext($this->dir, $config))->add(...BuiltIn::commands());
        if ($input !== null) {
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, $input);
            rewind($stream);
            $app->setInputStream($stream);
        } else {
            $app->setInteractive(false);
        }
        $code = $app->run($argv);

        return [$code, $out->fetch()];
    }

    private function writeSchema(string $models): void
    {
        file_put_contents($this->dir . '/prisma/schema.prisma', "generator client {\n  provider = \"nesto-client\"\n}\n\ndatasource db {\n  provider = \"sqlite\"\n  url      = env(\"DATABASE_URL\")\n}\n\n$models");
    }

    public function testInitCreatesTheSchemaAndTheEnvironmentVariable(): void
    {
        file_put_contents($this->dir . '/.env', "APP_NAME=demo");
        [$code, $output] = $this->console(['prisma:init', '--datasource-provider=sqlite']);
        self::assertSame(0, $code, $output);
        $schema = (string) file_get_contents($this->dir . '/prisma/schema.prisma');
        self::assertStringContainsString("generator client {\n  provider = \"nesto-client\"\n}", $schema);
        self::assertStringContainsString("datasource db {\n  provider = \"sqlite\"\n  url      = env(\"DATABASE_URL\")\n}", $schema);
        self::assertStringContainsString("APP_NAME=demo\n\n# Environment variables declared in this file", (string) file_get_contents($this->dir . '/.env'));
        self::assertStringContainsString('DATABASE_URL="file:./dev.db"', (string) file_get_contents($this->dir . '/.env'));

        [$code, $output] = $this->console(['prisma:init']);
        self::assertSame(1, $code);
        self::assertStringContainsString('already exists', $output);
    }

    public function testFormatAndValidate(): void
    {
        mkdir($this->dir . '/prisma');
        file_put_contents($this->dir . '/.env', 'DATABASE_URL="file:./dev.db"');
        $this->writeSchema("model User {\n  id Int @id @default(autoincrement())\n  email String @unique\n}\n");
        [$code, $output] = $this->console(['prisma:format', '--check']);
        self::assertSame(1, $code, $output);
        [$code] = $this->console(['prisma:format']);
        self::assertSame(0, $code);
        self::assertStringContainsString("  id    Int    @id @default(autoincrement())\n  email String @unique\n", (string) file_get_contents($this->dir . '/prisma/schema.prisma'));
        self::assertSame(0, $this->console(['prisma:format', '--check'])[0]);

        [$code, $output] = $this->console(['prisma:validate']);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('The schema at prisma/schema.prisma is valid', $output);

        $this->writeSchema("model User {\n  id Int @id\n  posts Post[]\n}\n");
        [$code, $output] = $this->console(['prisma:validate']);
        self::assertSame(1, $code);
        self::assertStringContainsString('Type "Post" is neither a built-in type', $output);
        self::assertStringContainsString('-->  prisma/schema.prisma:12:', $output);
        self::assertStringContainsString('Validation Error Count: 1', $output);
    }

    public function testDbPushPullAndSeed(): void
    {
        mkdir($this->dir . '/prisma');
        file_put_contents($this->dir . '/.env', 'DATABASE_URL="file:./dev.db"');
        $this->writeSchema("model User {\n  id    Int    @id @default(autoincrement())\n  email String @unique\n}\n");
        [$code, $output] = $this->console(['prisma:db', 'push']);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('Your database is now in sync with your Prisma schema.', $output);
        $db = Connection::fromOptions(['type' => 'sqlite', 'database' => $this->dir . '/prisma/dev.db']);
        self::assertSame('User', $db->fetchColumn("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'User'"));

        [$code, $output] = $this->console(['prisma:db', 'pull', '--print']);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString("model User {\n  id    Int    @id @default(autoincrement())\n  email String @unique\n}", $output);

        file_put_contents($this->dir . '/prisma/seed.php', "<?php\n\$pdo = new PDO('sqlite:' . __DIR__ . '/dev.db');\n\$pdo->exec(\"INSERT INTO \\\"User\\\" (email) VALUES ('seed@example.com')\");\necho \"seeded\\n\";\n");
        [$code, $output] = $this->console(['prisma:db', 'seed']);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('seeded', $output);
        self::assertSame('seed@example.com', $db->fetchColumn('SELECT email FROM "User"'));

        [$code, $output] = $this->console(['prisma:db', 'seed'], ['prisma' => ['seed' => 'php -r "exit(3);"']]);
        self::assertSame(1, $code);
        self::assertStringContainsString('An error occurred while running the seed command', $output);

        [$code, $output] = $this->console(['prisma:db', 'nope']);
        self::assertSame(1, $code);
        self::assertStringContainsString('Unknown command "prisma:db nope"', $output);
    }

    public function testMigrateAndDbCommandsUseTheDirectUrl(): void
    {
        mkdir($this->dir . '/prisma');
        // url: the pooled connection of the app (the client); directUrl: what the CLI migrates (Prisma semantics)
        file_put_contents($this->dir . '/.env', "DATABASE_URL=\"file:./pooled.db\"\nDIRECT_URL=\"file:./direct.db\"\n");
        file_put_contents($this->dir . '/prisma/schema.prisma', "datasource db {\n  provider  = \"sqlite\"\n  url       = env(\"DATABASE_URL\")\n  directUrl = env(\"DIRECT_URL\")\n}\n\nmodel User {\n  id    Int    @id @default(autoincrement())\n  email String @unique\n}\n");
        $tables = function (string $file): array {
            if (! is_file($this->dir . '/prisma/' . $file)) {
                return [];
            }
            $db = Connection::fromOptions(['type' => 'sqlite', 'database' => $this->dir . '/prisma/' . $file]);

            return array_map(fn (array $r) => $r['name'], $db->fetchAll("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"));
        };
        [$code, $output] = $this->console(['prisma:db', 'push', '--skip-generate']);
        self::assertSame(0, $code, $output);
        self::assertSame(['User'], $tables('direct.db'));
        self::assertSame([], $tables('pooled.db'), 'db push never touches the pooled url');

        [$code, $output] = $this->console(['prisma:migrate', 'reset', '--force', '--skip-generate', '--skip-seed']);
        self::assertSame(0, $code, $output);
        [$code, $output] = $this->console(['prisma:migrate', 'dev', '--name', 'init', '--skip-generate']);
        self::assertSame(0, $code, $output);
        self::assertSame(['User', '_prisma_migrations'], $tables('direct.db'));
        self::assertSame([], $tables('pooled.db'));
        [$code, $output] = $this->console(['prisma:migrate', 'status']);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('Database schema is up to date', $output);

        // a declared directUrl whose variable is missing is an error, not a silent fallback to url
        unset($_ENV['DIRECT_URL'], $_SERVER['DIRECT_URL']);
        putenv('DIRECT_URL');
        file_put_contents($this->dir . '/.env', "DATABASE_URL=\"file:./pooled.db\"\n");
        [$code, $output] = $this->console(['prisma:migrate', 'status']);
        self::assertSame(1, $code, $output);
        self::assertStringContainsString('DIRECT_URL', $output);
        self::assertSame([], $tables('pooled.db'));
    }

    public function testMigrateCommands(): void
    {
        mkdir($this->dir . '/prisma');
        file_put_contents($this->dir . '/.env', 'DATABASE_URL="file:./dev.db"');
        $this->writeSchema("model User {\n  id    Int    @id @default(autoincrement())\n  email String @unique\n}\n");

        [$code, $output] = $this->console(['prisma:migrate', 'diff', '--from-empty', '--to-schema-datamodel', 'prisma/schema.prisma', '--script']);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString("-- CreateTable\nCREATE TABLE \"User\" (", $output);
        [$code] = $this->console(['prisma:migrate', 'diff', '--from-empty', '--to-schema-datamodel', 'prisma/schema.prisma', '--exit-code']);
        self::assertSame(2, $code);

        [$code, $output] = $this->console(['prisma:migrate', 'dev', '--name', 'init']);
        self::assertSame(0, $code, $output);
        $folders = glob($this->dir . '/prisma/migrations/*_init') ?: [];
        self::assertCount(1, $folders);
        self::assertFileExists($folders[0] . '/migration.sql');

        [$code, $output] = $this->console(['prisma:migrate', 'status']);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('1 migration found in prisma/migrations', $output);
        self::assertStringContainsString('Database schema is up to date!', $output);

        [$code] = $this->console(['prisma:migrate', 'diff', '--from-migrations', 'prisma/migrations', '--to-schema-datamodel', 'prisma/schema.prisma', '--exit-code']);
        self::assertSame(0, $code);

        // reset needs --force without a terminal; with it the history is re-applied
        [$code, $output] = $this->console(['prisma:migrate', 'reset']);
        self::assertSame(1, $code);
        self::assertStringContainsString('--force', $output);
        [$code, $output] = $this->console(['prisma:migrate', 'reset', '--force', '--skip-seed']);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('Database reset successful', $output);

        // a new migration created without applying it is pending
        $this->writeSchema("model User {\n  id    Int     @id @default(autoincrement())\n  email String  @unique\n  name  String?\n}\n");
        [$code, $output] = $this->console(['prisma:migrate', 'dev', '--name', 'add name', '--create-only']);
        self::assertSame(0, $code, $output);
        [$code, $output] = $this->console(['prisma:migrate', 'status']);
        self::assertSame(1, $code);
        self::assertMatchesRegularExpression('/Following migration have not yet been applied:\n\d{14}_add_name/', $output);
        [$code, $output] = $this->console(['prisma:migrate', 'deploy']);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('All migrations have been successfully applied.', $output);

        [$code, $output] = $this->console(['prisma:migrate', 'resolve']);
        self::assertSame(1, $code);
        self::assertStringContainsString('--applied or --rolled-back must be part of the command', $output);
        [$code, $output] = $this->console(['prisma:migrate', 'resolve', '--applied', '20000101000000_missing']);
        self::assertSame(1, $code);
        self::assertStringContainsString('P3017', $output);
    }

    public function testMigrateDevAsksForTheNameInteractively(): void
    {
        mkdir($this->dir . '/prisma');
        file_put_contents($this->dir . '/.env', 'DATABASE_URL="file:./dev.db"');
        $this->writeSchema("model User {\n  id Int @id\n}\n");
        [$code, $output] = $this->console(['prisma:migrate', 'dev'], [], "first one\n");
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('Enter a name for the new migration:', $output);
        self::assertCount(1, glob($this->dir . '/prisma/migrations/*_first_one') ?: []);
    }

    public function testMissingSchemaIsReported(): void
    {
        [$code, $output] = $this->console(['prisma:validate']);
        self::assertSame(1, $code);
        self::assertStringContainsString('Could not find Prisma Schema that is required for this command.', $output);
        [$code, $output] = $this->console(['prisma:validate', '--schema', 'custom/schema.prisma']);
        self::assertSame(1, $code);
        self::assertStringContainsString('custom/schema.prisma: file not found', $output);
    }
}
