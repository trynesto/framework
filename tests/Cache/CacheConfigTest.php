<?php

declare(strict_types=1);

namespace Nesto\Tests\Cache;

use Nesto\Cache\CacheConfig;
use Nesto\Cache\CacheModule;
use Nesto\Cache\Stores\ArrayStore;
use Nesto\Cache\Stores\DatabaseStore;
use Nesto\Cache\Stores\FileStore;
use Nesto\Cache\Stores\RedisStore;
use Nesto\Cli\Application;
use Nesto\Cli\Commands\BuiltIn;
use Nesto\Cli\Output;
use Nesto\Cli\ProjectContext;
use Nesto\Common\Attributes\Module;
use Nesto\Core\NestoFactory;
use Nesto\Orm\Connection;
use Nesto\Orm\OrmModule;
use Nesto\Tests\Support\ProjectSandbox;
use Nesto\Throttler\Storage\Psr16Storage;
use Nesto\Throttler\ThrottlerModule;
use Nesto\Throttler\ThrottlerStorage;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

#[Module(imports: [new CacheModule()])]
final class ConfiguredCacheModule
{
}

#[Module(imports: [
    new OrmModule(type: 'sqlite', database: ':memory:'),
    new CacheModule(store: 'database', prefix: 'orm_'),
    new ThrottlerModule(storage: 'cache'),
])]
final class DatabaseCacheModule
{
}

/** CACHE_DRIVER / CACHE_STORE / CACHE_TTL / CACHE_PREFIX / CACHE_PATH / REDIS_* through CacheModule and config/cache.php. */
final class CacheConfigTest extends TestCase
{
    use ProjectSandbox;

    protected function tearDown(): void
    {
        $this->cleanSandbox();
    }

    private function store(): CacheInterface
    {
        return NestoFactory::createApplicationContext(ConfiguredCacheModule::class)->get(CacheInterface::class);
    }

    public function testWithoutConfigurationTheMemoryStoreIsUsed(): void
    {
        $this->sandbox();
        $this->setEnv(['CACHE_DRIVER' => null, 'CACHE_STORE' => null, 'CACHE_TTL' => null]);

        self::assertInstanceOf(ArrayStore::class, $this->store());
        self::assertSame(['ttl' => 5, 'store' => 'memory', 'prefix' => '', 'name' => 'memory', 'max' => null], CacheConfig::options(CacheConfig::load()));
    }

    public function testFileDriverFromEnv(): void
    {
        $this->sandbox();
        $this->setEnv(['CACHE_DRIVER' => 'file', 'CACHE_PATH' => 'storage/cache-here', 'CACHE_TTL' => '120']);
        $store = $this->store();

        self::assertInstanceOf(FileStore::class, $store);
        $store->set('k', 'v');
        self::assertCount(1, glob($this->sandbox() . '/storage/cache-here/*.cache') ?: []);
        self::assertSame('file (storage/cache-here), ttl 120s', CacheConfig::describe(CacheConfig::load()));
    }

    public function testCacheStoreIsAcceptedAndCacheDriverWins(): void
    {
        $this->sandbox();
        $this->setEnv(['CACHE_DRIVER' => null, 'CACHE_STORE' => 'file']);
        self::assertSame('file', CacheConfig::options(CacheConfig::load())['store']);

        $this->setEnv(['CACHE_DRIVER' => 'memory']);
        self::assertSame('memory', CacheConfig::options(CacheConfig::load())['store']);
    }

    public function testRedisDriverFromEnvConnectsLazily(): void
    {
        $this->sandbox();
        $this->setEnv(['CACHE_DRIVER' => 'redis', 'REDIS_HOST' => 'redis.invalid', 'REDIS_PORT' => '6390', 'REDIS_DB' => '3', 'CACHE_PREFIX' => 'shop:']);
        $store = $this->store(); // no connection yet: the host does not even resolve

        self::assertInstanceOf(RedisStore::class, $store);
        self::assertSame('shop:', $store->prefix());
        self::assertSame('redis (redis.invalid:6390/3), ttl 5s, prefix shop:', CacheConfig::describe(CacheConfig::load()));
    }

    public function testConfigFileStoresAndExplicitArgumentsWin(): void
    {
        $this->sandboxFile('config/cache.php', "<?php\nreturn ['default' => 'tmp', 'ttl' => 30, 'stores' => ['tmp' => ['driver' => 'file', 'path' => 'tmp-cache', 'ttl' => 15]]];\n");
        $this->setEnv(['CACHE_DRIVER' => 'memory']);

        $options = CacheModule::resolveOptions([]);
        self::assertSame('file', $options['store'], 'config/cache.php decides, like Laravel');
        self::assertSame(15, $options['ttl'], 'a store-level ttl wins over the global one');
        self::assertSame($this->sandbox() . '/tmp-cache', dirname(CacheModule::createStore($options)->path('x')));

        $explicit = CacheModule::resolveOptions(['ttl' => 99]);
        self::assertSame(99, $explicit['ttl']);
        self::assertSame(['ttl' => 1, 'store' => 'memory'], array_intersect_key(CacheModule::resolveOptions(['ttl' => 1, 'store' => 'memory']), ['ttl' => 0, 'store' => 0]));
    }

    public function testDatabaseStoreUsesTheOrmConnectionAndThrottlerReusesTheStore(): void
    {
        $app = NestoFactory::createApplicationContext(DatabaseCacheModule::class);
        $cache = $app->get(CacheInterface::class);
        self::assertInstanceOf(DatabaseStore::class, $cache);

        $cache->set('hits', 3);
        $connection = $app->get(Connection::class);
        self::assertSame(1, (int) $connection->fetchColumn('SELECT COUNT(*) FROM cache WHERE ' . $connection->quote('key') . " = 'orm_hits'"));
        self::assertSame($connection, $cache->connection(), 'the ORM connection, not a second one');

        $storage = $app->get(ThrottlerStorage::class);
        self::assertInstanceOf(Psr16Storage::class, $storage);
        $storage->increment('ip', 60);
        self::assertSame(2, $storage->increment('ip', 60)['totalHits']);
    }

    public function testDatabaseStoreWithoutAnApplicationUsesConfigDatabase(): void
    {
        $this->sandbox();
        $this->setEnv(['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => 'var/app.sqlite', 'DB_URL' => null]);
        $store = CacheModule::createStore(['store' => 'database']);
        $store->set('k', 'v');

        self::assertFileExists($this->sandbox() . '/var/app.sqlite');
        self::assertSame('v', $store->get('k'));
    }

    public function testCacheClearCommand(): void
    {
        $this->sandbox();
        $this->setEnv(['CACHE_DRIVER' => 'file', 'CACHE_PATH' => 'storage/cache']);
        $file = CacheModule::createStore(CacheModule::resolveOptions([]));
        $file->set('a', 1);
        $file->set('b', 2, 1);
        $out = Output::memory();
        $app = (new Application())->setOutput($out)->setContext(new ProjectContext($this->sandbox(), ['rootModule' => 'App\\Missing']))->add(...BuiltIn::commands());

        sleep(2);
        self::assertSame(0, $app->run(['cache:clear', '--expired']));
        self::assertStringContainsString('Removed 1 expired entry', $out->fetch());
        self::assertSame(1, $file->get('a'));

        self::assertSame(0, $app->run(['cache:clear']));
        self::assertStringContainsString('"file" cache store cleared', $out->fetch());
        self::assertNull($file->get('a'));

        self::assertSame(1, $app->run(['cache:clear', 'nope']));
    }
}
