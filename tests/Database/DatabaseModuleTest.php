<?php

declare(strict_types=1);

namespace Nesto\Tests\Database;

use Nesto\Common\Attributes\Module;
use Nesto\Core\NestoFactory;
use Nesto\Database\DatabaseConfig;
use Nesto\Database\DatabaseModule;
use Nesto\Database\EntityScanner;
use Nesto\Logger\Logger;
use Nesto\Orm\Connection;
use Nesto\Orm\DataSource;
use Nesto\Orm\QueryException;
use Nesto\Prisma\Client\PrismaClient;
use Nesto\Tests\Support\ProjectSandbox;
use PHPUnit\Framework\TestCase;

#[Module(imports: [new DatabaseModule()])]
final class EnvDatabaseModule
{
}

/** ORM_DRIVER / DB_* / DATABASE_URL through DatabaseModule and config/database.php. */
final class DatabaseModuleTest extends TestCase
{
    use ProjectSandbox;

    private static bool $autoload = false;

    protected function setUp(): void
    {
        EntityScanner::clearCache();
        if (! self::$autoload) {
            self::$autoload = true;
            // the sandbox project's classes: NestoSandboxDb\... => <sandbox>/src/...
            spl_autoload_register(static function (string $class): void {
                if (str_starts_with($class, 'NestoSandboxDb\\') && ($base = getenv('NESTO_SANDBOX_SRC'))) {
                    $file = $base . '/' . str_replace('\\', '/', substr($class, strlen('NestoSandboxDb\\'))) . '.php';
                    if (is_file($file)) {
                        require $file;
                    }
                }
            });
        }
    }

    protected function tearDown(): void
    {
        $this->cleanSandbox();
    }

    private function project(array $env = []): string
    {
        $dir = $this->sandbox();
        $this->setEnv($env + ['NESTO_SANDBOX_SRC' => "$dir/src", 'ORM_DRIVER' => null, 'DB_CONNECTION' => null, 'DB_DATABASE' => null, 'DB_URL' => null, 'DB_SYNCHRONIZE' => null, 'DB_LOGGING' => null, 'DATABASE_URL' => null]);
        $this->sandboxFile('src/Shop/Entities/Product.php', <<<'PHP'
            <?php
            namespace NestoSandboxDb\Shop\Entities;

            use Nesto\Orm\Attributes\Column;
            use Nesto\Orm\Attributes\Entity;
            use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

            #[Entity('products')]
            class Product
            {
                #[PrimaryGeneratedColumn]
                public int $id;

                #[Column]
                public string $name;
            }
            PHP);
        $this->sandboxFile('src/Shop/Entities/Helper.php', "<?php\nnamespace NestoSandboxDb\\Shop\\Entities;\n\nfinal class Helper {}\n");
        $this->sandboxFile('src/Shop/ProductsService.php', "<?php\nnamespace NestoSandboxDb\\Shop;\n\nfinal class ProductsService {}\n");

        return $dir;
    }

    public function testOrmDriverConnectsWithTheEnvAndFindsTheEntities(): void
    {
        $dir = $this->project(['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => 'database/app.sqlite', 'DB_SYNCHRONIZE' => 'true']);
        $this->sandboxFile('nesto.config.php', "<?php\nreturn ['entities' => 'src/**/Entities'];\n");

        $app = NestoFactory::createApplicationContext(EnvDatabaseModule::class);
        $dataSource = $app->get(DataSource::class);
        $connection = $app->get(Connection::class);

        self::assertSame('sqlite', $connection->driver());
        self::assertFileExists("$dir/database/app.sqlite", 'relative to the project root, folder created');
        self::assertTrue($dataSource->schema()->hasTable('products'), 'entities of nesto.config.php, synchronized');
        self::assertSame('orm', $app->get(DatabaseModule::CONFIG)['driver']);
        self::assertSame(['NestoSandboxDb\\Shop\\Entities\\Product'], DatabaseConfig::entities(DatabaseConfig::load()), 'only #[Entity] classes');
    }

    public function testFailedQueriesReachTheApplicationLog(): void
    {
        $this->project(['DB_DATABASE' => ':memory:']);
        $stream = fopen('php://memory', 'w+b');
        Logger::toStream($stream);
        $app = NestoFactory::createApplicationContext(EnvDatabaseModule::class);

        try {
            $app->get(Connection::class)->fetchAll('SELECT * FROM no_such_table');
            self::fail('expected a QueryException');
        } catch (QueryException) {
        }
        rewind($stream);
        self::assertStringContainsString('ERROR [Orm]', (string) stream_get_contents($stream));
        self::assertStringContainsString('no_such_table', (string) stream_get_contents($stream, offset: 0));
    }

    public function testNoneImportsNothing(): void
    {
        $this->project(['ORM_DRIVER' => 'none']);
        $app = NestoFactory::createApplicationContext(EnvDatabaseModule::class);

        $provided = false;
        foreach ($app->container()->modules() as $module) {
            $provided = $provided || $module->has(DataSource::class);
        }
        self::assertFalse($provided);
        self::assertSame('none', DatabaseConfig::describe(DatabaseConfig::load()));
    }

    public function testPrismaDriverProvidesThePrismaClient(): void
    {
        $dir = $this->project(['ORM_DRIVER' => 'prisma', 'DATABASE_URL' => 'file:./prisma_test.db']);
        $this->sandboxFile('prisma/schema.prisma', <<<'PRISMA'
            datasource db {
              provider = "sqlite"
              url      = env("DATABASE_URL")
            }

            model Dog {
              id   Int    @id @default(autoincrement())
              name String
            }
            PRISMA);

        $app = NestoFactory::createApplicationContext(EnvDatabaseModule::class);
        $prisma = $app->get(PrismaClient::class);
        $prisma->executeRaw('CREATE TABLE "Dog" ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "name" TEXT NOT NULL)');
        $prisma->dog->create(['data' => ['name' => 'Rex']]);

        self::assertSame([['id' => 1, 'name' => 'Rex']], $prisma->dog->findMany());
        self::assertFileExists("$dir/prisma/prisma_test.db");
        self::assertSame('prisma (file:./prisma_test.db)', DatabaseConfig::describe(DatabaseConfig::load()));

        $stream = fopen('php://memory', 'w+b');
        Logger::toStream($stream);
        try {
            $prisma->dog->findUniqueOrThrow(['where' => ['id' => 99]]);
        } catch (\Throwable) {
        }
        rewind($stream);
        self::assertStringContainsString('ERROR [Prisma] prisma:error No Dog found (dog.findUniqueOrThrow)', (string) stream_get_contents($stream));
    }

    public function testConfigFileAndExplicitArguments(): void
    {
        $this->project();
        $this->sandboxFile('config/database.php', "<?php\nreturn ['default' => 'main', 'connections' => ['main' => ['driver' => 'pgsql', 'host' => 'db', 'port' => 5433, 'database' => 'shop', 'username' => 'app', 'password' => 'secret']]];\n");

        $config = DatabaseConfig::load();
        self::assertSame(['type' => 'pgsql', 'host' => 'db', 'port' => 5433, 'database' => 'shop', 'username' => 'app', 'password' => 'secret'], DatabaseConfig::connectionOptions($config));
        self::assertSame('orm, connection main (pgsql app@db:5433/shop)', DatabaseConfig::describe($config));

        $module = new DatabaseModule(driver: 'none', connection: 'sqlite');
        self::assertSame('none', $module->config()['driver']);
        self::assertSame('sqlite', $module->config()['default']);
    }

    public function testUrlLoggingAndCacheOptions(): void
    {
        $this->project(['DB_CONNECTION' => 'mysql', 'DB_URL' => 'mysql://root:pw@127.0.0.1:3306/app']);
        $config = DatabaseConfig::load();

        self::assertSame('orm, connection mysql (mysql://root:***@127.0.0.1:3306/app)', DatabaseConfig::describe($config));
        self::assertSame(['error', 'warn'], DatabaseConfig::logging(false), 'failed queries are always logged');
        self::assertTrue(DatabaseConfig::logging('true'));
        self::assertFalse(DatabaseConfig::logging('none'));
        self::assertSame(['query', 'error'], DatabaseConfig::logging('query, error'));
        self::assertSame(['type' => 'psr16'], DatabaseConfig::cache('cache'), 'the CacheModule store');
        self::assertTrue(DatabaseConfig::cache('database'));
        self::assertFalse(DatabaseConfig::cache(false));
    }

    public function testInvalidDriverIsReported(): void
    {
        $this->project(['ORM_DRIVER' => 'doctrine']);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ORM_DRIVER must be orm, prisma or none');
        (new DatabaseModule())->toDynamicModule();
    }

    public function testEntityScannerGlobs(): void
    {
        $dir = $this->project();
        $this->sandboxFile('src/Legacy/Models/Order.php', "<?php\nnamespace NestoSandboxDb\\Legacy\\Models;\n\n#[\\Nesto\\Orm\\Attributes\\Entity]\nfinal class Order { public int \$id; }\n");

        self::assertSame(["$dir/src/Shop/Entities/Helper.php", "$dir/src/Shop/Entities/Product.php"], EntityScanner::files('src/**/Entities', $dir));
        self::assertSame(["$dir/src/Legacy/Models/Order.php"], EntityScanner::files('src/*/Models/*.php', $dir));
        self::assertSame(
            ['NestoSandboxDb\\Shop\\Entities\\Product', 'NestoSandboxDb\\Legacy\\Models\\Order'],
            EntityScanner::scan(['src/**/Entities', 'src/Legacy'], $dir),
        );
        self::assertSame(['NestoSandboxDb\\Shop\\ProductsService'], EntityScanner::classesIn("$dir/src/Shop/ProductsService.php"));
    }
}
