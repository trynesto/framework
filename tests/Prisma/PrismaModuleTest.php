<?php

declare(strict_types=1);

namespace Nesto\Tests\Prisma;

use Nesto\Common\DynamicModule;
use Nesto\Core\NestoFactory;
use Nesto\Core\Paths;
use Nesto\Http\Request;
use Nesto\Prisma\Client\PrismaClient;
use Nesto\Prisma\Generator\ClientGenerator;
use Nesto\Prisma\PrismaModule;
use Nesto\Prisma\PrismaService;
use Nesto\Prisma\Schema\SchemaLoader;
use Nesto\Prisma\Testing\PrismaTestDatabase;
use Nesto\Prisma\Testing\RefreshPrismaDatabase;
use Nesto\Tests\Prisma\Fixtures\App\AppModule;
use Nesto\Tests\Prisma\Fixtures\App\UsersModule;
use Nesto\Tests\Prisma\Fixtures\App\UsersService;
use PHPUnit\Framework\TestCase;

/** PrismaModule / PrismaService in a Nesto application (SQLite): DI, lifecycle hooks, HTTP requests. */
final class PrismaModuleTest extends TestCase
{
    use RefreshPrismaDatabase;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nesto_prisma_app_' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/prisma', 0777, true);
        file_put_contents($this->dir . '/composer.json', '{}');
        file_put_contents($this->dir . '/.env', "DATABASE_URL=\"file:./app_test.db\"\n");
        file_put_contents($this->dir . '/prisma/schema.prisma', <<<'PRISMA'
            generator client {
              provider  = "nesto-client"
              namespace = "PRISMA_NAMESPACE"
            }

            datasource db {
              provider = "sqlite"
              url      = env("DATABASE_URL")
            }

            model User {
              id    Int    @id @default(autoincrement())
              email String @unique
              posts Post[]
            }

            model Post {
              id       Int    @id @default(autoincrement())
              title    String
              author   User   @relation(fields: [authorId], references: [id])
              authorId Int
            }
            PRISMA);
        Paths::setBase($this->dir);
    }

    protected function tearDown(): void
    {
        Paths::setBase(null);
        unset($_ENV['DATABASE_URL'], $_SERVER['DATABASE_URL']);
        putenv('DATABASE_URL');
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function useNamespace(string $namespace): string
    {
        $file = $this->dir . '/prisma/schema.prisma';
        file_put_contents($file, str_replace('PRISMA_NAMESPACE', str_replace('\\', '\\\\', $namespace), (string) file_get_contents($file)));

        return $file;
    }

    public function testPrismaServiceThroughDependencyInjection(): void
    {
        $schema = $this->useNamespace('PrismaModuleTest\\NotGenerated');
        PrismaTestDatabase::push($schema, 'file:./app_test.db');

        $app = NestoFactory::create(AppModule::class);
        $app->init();
        $service = $app->get(PrismaService::class);
        self::assertInstanceOf(PrismaService::class, $service, 'the runtime service without a generated client');
        self::assertSame($service, $app->get(PrismaClient::class));
        self::assertSame($service, $app->get(UsersService::class)->prisma);
        self::assertFileExists($this->dir . '/storage/cache/prisma-dmmf.php', 'the schema is compiled once into the cache');
        $this->refreshPrismaDatabase($service);

        $created = $app->handle(Request::create('POST', '/users', body: ['email' => 'ada@example.com']));
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        self::assertSame(['id' => 1, 'email' => 'ada@example.com'], json_decode((string) $created->getBody(), true));
        $post = $app->handle(Request::create('POST', '/users/1/posts', body: ['title' => 'Hello']));
        self::assertSame(201, $post->getStatusCode(), (string) $post->getBody());
        // worker mode: the same client serves every request, no state is left behind
        \Nesto\Logger\Logger::toStream(fopen('php://memory', 'w+b'));
        $failed = $app->handle(Request::create('POST', '/users/99/posts', body: ['title' => 'Nobody']));
        \Nesto\Logger\Logger::toStream(null);
        self::assertSame(500, $failed->getStatusCode());
        self::assertFalse($service->connection()->inTransaction());
        $list = $app->handle(Request::create('GET', '/users'));
        self::assertSame([['id' => 1, 'email' => 'ada@example.com', '_count' => ['posts' => 1]]], json_decode((string) $list->getBody(), true));
        self::assertSame($service, $app->get(PrismaService::class));
        $app->close();
    }

    public function testTheGeneratedPrismaServiceIsPickedAutomatically(): void
    {
        $schema = $this->useNamespace('PrismaModuleTest\\Generated');
        $generator = new ClientGenerator(SchemaLoader::loadFile($schema), $schema, $this->dir);
        $generator->generate();
        require $this->dir . '/generated/prisma/autoload.php';
        PrismaTestDatabase::push($schema, 'file:./app_test.db');

        $app = NestoFactory::create(AppModule::class);
        $app->init();
        $service = $app->get('PrismaModuleTest\\Generated\\PrismaService');
        self::assertInstanceOf('PrismaModuleTest\\Generated\\PrismaClient', $service);
        self::assertSame($service, $app->get(PrismaClient::class));
        self::assertInstanceOf('PrismaModuleTest\\Generated\\Delegates\\UserDelegate', $service->user);
        $this->refreshPrismaDatabase($service);
        $service->user->create(['data' => ['email' => 'gen@example.com']]);
        $list = $app->handle(Request::create('GET', '/users'));
        self::assertSame('gen@example.com', json_decode((string) $list->getBody(), true)[0]['email']);
        $app->close();
    }

    public function testTheGeneratedClientIsLoadedWithoutAComposerMapping(): void
    {
        // like a real project right after `prisma:generate`: nobody requires generated/prisma/autoload.php
        $schema = $this->useNamespace('PrismaModuleTest\\Unmapped');
        (new ClientGenerator(SchemaLoader::loadFile($schema), $schema, $this->dir))->generate();
        self::assertFalse(class_exists('PrismaModuleTest\\Unmapped\\PrismaService', false));

        $app = NestoFactory::create(AppModule::class);
        $app->init();

        self::assertInstanceOf('PrismaModuleTest\\Unmapped\\PrismaService', $app->get(PrismaClient::class));
        $app->close();
    }

        public function testForRootAsyncAndOptions(): void
    {
        $schema = $this->useNamespace('PrismaModuleTest\\Async');
        PrismaTestDatabase::push($schema, 'file:./app_test.db');
        $events = [];
        $root = new DynamicModule(
            module: AsyncRootModule::class,
            imports: [
                PrismaModule::forRootAsync([
                    'useFactory' => fn () => ['datasourceUrl' => 'file:./app_test.db', 'log' => [['emit' => 'event', 'level' => 'query']]],
                    'isGlobal' => true,
                ]),
                UsersModule::class,
            ],
        );
        $app = NestoFactory::create($root);
        $app->init();
        $service = $app->get(PrismaClient::class);
        self::assertInstanceOf(PrismaService::class, $service);
        $service->on('query', function (array $event) use (&$events): void {
            $events[] = $event['query'];
        });
        $this->refreshPrismaDatabase($service);
        $app->get(UsersService::class)->create(['email' => 'async@example.com']);
        self::assertNotEmpty($events);
        self::assertSame(1, $service->user->count());
        $app->close();
    }

    public function testRefreshPrismaDatabaseOnlyTouchesTheTestDatabase(): void
    {
        // the development database of .env, with data in it; the app boots and connects to it
        file_put_contents($this->dir . '/.env', "DATABASE_URL=\"file:./dev.db\"\n");
        $schema = $this->useNamespace('PrismaModuleTest\\Isolated');
        PrismaTestDatabase::push($schema, 'file:./dev.db', force: true);
        $app = NestoFactory::create(AppModule::class);
        $app->init();
        $service = $app->get(PrismaService::class);
        $service->user->create(['data' => ['email' => 'dev@example.com']]);
        $emails = fn (string $file) => (new \PDO('sqlite:' . $this->dir . '/prisma/' . $file))->query('SELECT email FROM "User" ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);

        // the documented pattern: the service of the booted app, a test URL
        $this->refreshPrismaDatabase($service, $schema, 'file:./dev_test.db');
        self::assertSame(['dev@example.com'], $emails('dev.db'), 'the development database is left alone');
        self::assertSame(0, $service->user->count(), 'the service now uses the test database');
        $service->user->create(['data' => ['email' => 'test@example.com']]);
        self::assertSame(['test@example.com'], $emails('dev_test.db'));
        $this->refreshPrismaDatabase($service, $schema, 'file:./dev_test.db');
        self::assertSame([], $emails('dev_test.db'), 'emptied between tests');
        self::assertSame(['dev@example.com'], $emails('dev.db'));

        // a database whose name does not say "test" is refused unless forced
        try {
            $this->refreshPrismaDatabase($service, $schema, 'file:./dev.db');
            self::fail('dev.db is not a test database');
        } catch (\Nesto\Prisma\PrismaException $e) {
            self::assertStringContainsString('"test"', $e->getMessage());
        }
        self::assertSame(['dev@example.com'], $emails('dev.db'));
        $devClient = new PrismaClient(['schema' => $schema, 'datasourceUrl' => 'file:./dev.db']);
        try {
            PrismaTestDatabase::truncate($devClient);
            self::fail('truncate of a client connected to dev.db');
        } catch (\Nesto\Prisma\PrismaException $e) {
            self::assertStringContainsString('dev.db', $e->getMessage());
        }
        try {
            PrismaTestDatabase::push($schema, 'file:./dev.db', again: true);
            self::fail('push to dev.db');
        } catch (\Nesto\Prisma\PrismaException) {
        }
        self::assertSame(['dev@example.com'], $emails('dev.db'));
        PrismaTestDatabase::truncate($devClient, force: true);
        self::assertSame([], $emails('dev.db'), 'forced');
        $app->close();
    }
}

final class AsyncRootModule
{
}
