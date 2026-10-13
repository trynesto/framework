<?php

declare(strict_types=1);

namespace Nesto\Tests\Prisma\Generator;

use Nesto\Cli\Application;
use Nesto\Cli\Commands\BuiltIn;
use Nesto\Cli\Output;
use Nesto\Cli\ProjectContext;
use Nesto\Prisma\Client\ModelDelegate;
use Nesto\Prisma\Client\PrismaClient as RuntimeClient;
use Nesto\Prisma\Generator\ClientGenerator;
use Nesto\Prisma\Migrate\DatabaseReset;
use Nesto\Prisma\Migrate\DatabaseUrl;
use Nesto\Prisma\Schema\SchemaLoader;
use Nesto\Prisma\Testing\PrismaTestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * prisma:generate: the generated files (snapshot, php -l, deterministic), the namespace / output options, and
 * the generated client used against SQLite (and PostgreSQL when NESTO_TEST_PGSQL_URL is set). Set
 * NESTO_UPDATE_SNAPSHOTS=1 to rewrite tests/Prisma/Generator/snapshots.
 */
final class ClientGeneratorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nesto_prisma_gen_' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/prisma', 0777, true);
    }

    protected function tearDown(): void
    {
        unset($_ENV['DATABASE_URL'], $_SERVER['DATABASE_URL']);
        putenv('DATABASE_URL');
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private static function models(bool $enums): string
    {
        return ($enums ? "/// Who can do what.\nenum Role {\n  USER\n  ADMIN @map(\"admin\")\n}\n\n" : '') . <<<'PRISMA'
            /// A person with an account.
            model User {
              id        Int       @id @default(autoincrement())
              email     String    @unique
              name      String?
            PRISMA . "\n" . ($enums ? "  role      Role      @default(USER)\n" : '') . <<<'PRISMA'
              score     Float     @default(0)
              balance   Decimal   @default(0)
              createdAt DateTime  @default(now())
              updatedAt DateTime  @updatedAt
              posts     Post[]
              secret    Unsupported("circle")?
              legacy    String?   @ignore

              @@map("users")
            }

            model Post {
              id       Int        @id @default(autoincrement())
              title    String
              meta     Json?
              author   User       @relation(fields: [authorId], references: [id])
              authorId Int
              tags     Tag[]

              @@unique([authorId, title], name: "authorTitle")
            }

            model Tag {
              id    Int    @id @default(autoincrement())
              name  String @unique
              posts Post[]
            }

            model Audit {
              id Int @id

              @@ignore
            }
            PRISMA;
    }

    private function schema(string $provider, string $url, string $generator, bool $enums): string
    {
        $models = self::models($enums);
        if ($provider !== 'postgresql') {
            $models = str_replace("  secret    Unsupported(\"circle\")?\n", '', $models);
        }
        $source = $generator . "\n\ndatasource db {\n  provider = \"$provider\"\n  url      = env(\"DATABASE_URL\")\n}\n\n" . $models;
        file_put_contents($this->dir . '/prisma/schema.prisma', $source);

        return $this->dir . '/prisma/schema.prisma';
    }

    /** @return array<string, string> */
    private function generateFiles(string $namespace = 'Acme\\Blog\\Prisma'): array
    {
        $path = $this->schema('postgresql', '', "generator client {\n  provider  = \"nesto-client\"\n  output    = \"../generated/prisma\"\n  namespace = \"" . str_replace('\\', '\\\\', $namespace) . "\"\n}", true);
        $generator = new ClientGenerator(SchemaLoader::loadFile($path), $path);

        return $generator->files($generator->namespace($generator->generators()[0]), $generator->outputDir($generator->generators()[0]));
    }

    public function testGeneratedFilesMatchTheSnapshot(): void
    {
        $files = $this->generateFiles();
        self::assertSame([
            'Delegates/PostDelegate.php', 'Delegates/TagDelegate.php', 'Delegates/UserDelegate.php',
            'Enums/Role.php', 'PrismaClient.php', 'PrismaService.php', 'autoload.php', 'dmmf.php',
        ], array_keys($files), 'ignored models get no delegate');
        $snapshots = __DIR__ . '/snapshots/blog';
        if (getenv('NESTO_UPDATE_SNAPSHOTS') === '1') {
            exec('rm -rf ' . escapeshellarg($snapshots));
            foreach ($files as $path => $contents) {
                @mkdir(dirname($snapshots . '/' . $path), 0777, true);
                file_put_contents($snapshots . '/' . $path . '.snap', $contents);
            }
        }
        foreach ($files as $path => $contents) {
            self::assertFileExists($snapshots . '/' . $path . '.snap', "missing snapshot for $path (run with NESTO_UPDATE_SNAPSHOTS=1)");
            self::assertSame(file_get_contents($snapshots . '/' . $path . '.snap'), $contents, "snapshot of $path");
        }
        self::assertSame($files, $this->generateFiles(), 'generation is deterministic');

        $user = $files['Delegates/UserDelegate.php'];
        self::assertStringContainsString("@phpstan-type User array{id: int, email: string, name: string|null, role: \\Acme\\Blog\\Prisma\\Enums\\Role, score: float, balance: numeric-string, createdAt: \\DateTimeImmutable, updatedAt: \\DateTimeImmutable, posts?: list<array<string, mixed>>, _count?: array{posts?: int}}", $user);
        self::assertStringNotContainsString('secret', $user, 'Unsupported fields are skipped');
        self::assertStringNotContainsString('legacy', $user, '@ignore fields are skipped');
        self::assertStringContainsString("authorTitle?: array{authorId: int, title: string}", $files['Delegates/PostDelegate.php']);
        self::assertStringContainsString("case ADMIN = 'ADMIN';", $files['Enums/Role.php']);
        self::assertStringContainsString("return __DIR__ . '/../../prisma';", $files['PrismaClient.php']);
    }

    public function testGeneratedCodeIsValidPhp(): void
    {
        $files = $this->generateFiles();
        $out = $this->dir . '/lint';
        (new ClientGenerator(SchemaLoader::loadFile($this->dir . '/prisma/schema.prisma'), $this->dir . '/prisma/schema.prisma'))->write($out, $files);
        foreach (array_keys($files) as $path) {
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($out . '/' . $path) . ' 2>&1', $output, $code);
            self::assertSame(0, $code, $path . ': ' . implode("\n", $output));
            $output = [];
        }
        $dmmf = require $out . '/dmmf.php';
        self::assertSame(['User', 'Post', 'Tag', 'Audit'], array_column($dmmf['datamodel']['models'], 'name'));
        // the PHPDoc array shapes are balanced
        foreach (glob($out . '/Delegates/*.php') as $file) {
            foreach (file($file) as $line) {
                if (str_contains($line, '@phpstan-type')) {
                    self::assertSame(substr_count($line, '{'), substr_count($line, '}'), $line);
                    self::assertSame(substr_count($line, '<'), substr_count($line, '>'), $line);
                }
            }
        }
    }

    public function testNamespaceAndOutputDefaults(): void
    {
        file_put_contents($this->dir . '/composer.json', json_encode(['autoload' => ['psr-4' => ['Acme\\' => 'src/']]]));
        $path = $this->schema('sqlite', 'file:./dev.db', "generator client {\n  provider = \"nesto-client\"\n  output   = \"../src/Data/Prisma\"\n}", false);
        $generator = new ClientGenerator(SchemaLoader::loadFile($path), $path, $this->dir);
        $block = $generator->generators()[0];
        self::assertSame($this->dir . '/src/Data/Prisma', $generator->outputDir($block));
        self::assertSame('Acme\\Data\\Prisma', $generator->namespace($block), 'namespace from composer.json PSR-4');

        $path = $this->schema('sqlite', 'file:./dev.db', "generator client {\n  provider = \"nesto-client\"\n}\n\ngenerator other {\n  provider = \"prisma-client-js\"\n}", false);
        $generator = new ClientGenerator(SchemaLoader::loadFile($path), $path, $this->dir);
        self::assertCount(1, $generator->generators(), 'other generators are left alone');
        $block = $generator->generators()[0];
        self::assertSame($this->dir . '/generated/prisma', $generator->outputDir($block));
        self::assertSame(ClientGenerator::DEFAULT_NAMESPACE, $generator->namespace($block));
    }

    public function testGenerateCommandAndUsingTheGeneratedClientOnSqlite(): void
    {
        $this->useGeneratedClient('sqlite', 'file:./dev.db', 'GenTestSqlite\\Prisma');
    }

    public function testUsingTheGeneratedClientOnPostgres(): void
    {
        $url = getenv('NESTO_TEST_PGSQL_URL');
        if ($url === false || $url === '') {
            $this->markTestSkipped('NESTO_TEST_PGSQL_URL not configured.');
        }
        DatabaseReset::reset((new DatabaseUrl($url, 'postgresql'))->connectOrCreate());
        try {
            $this->useGeneratedClient('postgresql', $url, 'GenTestPg\\Prisma');
        } finally {
            DatabaseReset::reset((new DatabaseUrl($url, 'postgresql'))->connect());
        }
    }

    private function useGeneratedClient(string $provider, string $url, string $namespace): void
    {
        $enums = $provider !== 'sqlite';
        $this->schema($provider, $url, "generator client {\n  provider  = \"nesto-client\"\n  namespace = \"" . str_replace('\\', '\\\\', $namespace) . "\"\n}", $enums);
        file_put_contents($this->dir . '/.env', 'DATABASE_URL="' . $url . "\"\n");
        file_put_contents($this->dir . '/composer.json', '{}');

        // db push creates the tables and runs prisma:generate
        $out = Output::memory();
        $app = (new Application())->setOutput($out)->setContext(new ProjectContext($this->dir, []))->add(...BuiltIn::commands())->setInteractive(false);
        $code = $app->run(['prisma:db', 'push', '--accept-data-loss']);
        $output = $out->fetch();
        self::assertSame(0, $code, $output);
        self::assertStringContainsString("Generated Nesto Prisma Client ($namespace) to ./generated/prisma", $output);
        self::assertStringContainsString('is not mapped in composer.json', $output);
        self::assertFileExists($this->dir . '/storage/cache/prisma-dmmf.php', 'the compiled schema cache is refreshed');

        $out = Output::memory();
        $app->setOutput($out);
        self::assertSame(0, $app->run(['prisma:generate', '--no-hints']), $out->fetch());

        require $this->dir . '/generated/prisma/autoload.php';
        $clientClass = $namespace . '\\PrismaClient';
        // a fresh process would read DATABASE_URL from .env next to the project; this one points at it directly
        PrismaTestDatabase::pointAt(new \Nesto\Prisma\PrismaProject($this->dir . '/prisma/schema.prisma', $this->dir), $url);
        /** @var RuntimeClient $prisma */
        $prisma = new $clientClass();
        self::assertInstanceOf(RuntimeClient::class, $prisma);
        self::assertInstanceOf($namespace . '\\Delegates\\UserDelegate', $prisma->user);
        self::assertInstanceOf(ModelDelegate::class, $prisma->post);
        self::assertSame($prisma->user, $prisma->model('User'));

        $user = $prisma->user->create(['data' => ['email' => 'ada@example.com', 'posts' => ['create' => [['title' => 'Hi', 'tags' => ['create' => [['name' => 'php']]]]]]], 'include' => ['posts' => ['include' => ['tags' => true]]]]);
        self::assertSame('php', $user['posts'][0]['tags'][0]['name']);
        self::assertArrayNotHasKey('legacy', $user);
        self::assertArrayNotHasKey('secret', $user);
        if ($enums) {
            $roleClass = $namespace . '\\Enums\\Role';
            self::assertSame($roleClass::USER, $user['role']);
            $admin = $prisma->user->update(['where' => ['id' => $user['id']], 'data' => ['role' => $roleClass::ADMIN]]);
            self::assertSame($roleClass::ADMIN, $admin['role']);
            self::assertSame('admin', $prisma->queryRawUnsafe('SELECT role FROM users')[0]['role'], '@map value in the database');
            self::assertCount(1, $prisma->user->findMany(['where' => ['role' => 'ADMIN']]));
        }
        self::assertSame(1, $prisma->post->count(['where' => ['authorTitle' => ['authorId' => $user['id'], 'title' => 'Hi']]]));
        $extended = $prisma->extends(['result' => ['user' => ['upper' => ['needs' => ['email' => true], 'compute' => fn (array $u) => strtoupper($u['email'])]]]]);
        self::assertInstanceOf($clientClass, $extended);
        self::assertInstanceOf($namespace . '\\Delegates\\UserDelegate', $extended->user);
        self::assertSame('ADA@EXAMPLE.COM', $extended->user->findFirst()['upper']);
        $prisma->disconnect();
    }
}
