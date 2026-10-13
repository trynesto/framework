<?php

declare(strict_types=1);

namespace Nesto\Tests\Cache;

use Nesto\Cache\Attributes\CacheTTL;
use Nesto\Cache\CacheInterceptor;
use Nesto\Cache\CacheModule;
use Nesto\Cache\Stores\ArrayStore;
use Nesto\Cache\Stores\FileStore;
use Nesto\Cache\Stores\Psr16Adapter;
use Nesto\Common\Attributes\Controller;
use Nesto\Common\Attributes\Get;
use Nesto\Common\Attributes\Module;
use Nesto\Common\Attributes\UseInterceptors;
use Nesto\Common\DynamicModule;
use Nesto\Core\NestoFactory;
use Nesto\Http\Request;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

#[Controller('ttl')]
#[UseInterceptors(CacheInterceptor::class)]
final class TtlController
{
    #[Get('forever')]
    #[CacheTTL(0)]
    public function forever(): array
    {
        return ['at' => microtime(true)];
    }

    #[Get('default')]
    public function default(): array
    {
        return ['at' => microtime(true)];
    }

    #[Get('ten')]
    #[CacheTTL(10)]
    public function ten(): array
    {
        return ['at' => microtime(true)];
    }
}

#[Module(imports: [new CacheModule(ttl: 1, store: 'memory')], controllers: [TtlController::class])]
final class TtlAppModule
{
}

#[Module(controllers: [TtlController::class])]
final class TtlThirdPartyAppModule
{
}

/** Spec-following third-party PSR-16 store that records the TTL it receives (0 = "already expired"). */
final class RecordingPsr16 implements CacheInterface
{
    /** @var array<string, null|int|\DateInterval> */
    public array $ttls = [];
    private ArrayStore $inner;

    public function __construct()
    {
        $this->inner = new ArrayStore;
    }

    public function get(string $key, mixed $default = null): mixed { return $this->inner->get($key, $default); }
    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->ttls[$key] = $ttl;
        if (is_int($ttl) && $ttl <= 0) {
            return $this->inner->delete($key);
        }

        return $this->inner->set($key, $value, $ttl);
    }
    public function delete(string $key): bool { return $this->inner->delete($key); }
    public function clear(): bool { return $this->inner->clear(); }
    public function getMultiple(iterable $keys, mixed $default = null): iterable { return $this->inner->getMultiple($keys, $default); }
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool { return $this->inner->setMultiple($values, $ttl); }
    public function deleteMultiple(iterable $keys): bool { return $this->inner->deleteMultiple($keys); }
    public function has(string $key): bool { return $this->inner->has($key); }
}

/** #[CacheTTL(0)] means "no expiry", not "the module's default TTL". */
final class CacheTtlTest extends TestCase
{
    public function testCacheTtlZeroNeverExpiresWhileTheModuleDefaultDoes(): void
    {
        $app = NestoFactory::create(TtlAppModule::class);
        foreach (['/ttl/forever', '/ttl/default'] as $path) {
            self::assertSame('MISS', $app->handle(Request::create('GET', $path))->getHeaderLine('X-Cache'));
        }
        usleep(1_100_000);   // past the module's ttl: 1

        self::assertSame('HIT', $app->handle(Request::create('GET', '/ttl/forever'))->getHeaderLine('X-Cache'));
        self::assertSame('MISS', $app->handle(Request::create('GET', '/ttl/default'))->getHeaderLine('X-Cache'));
    }

    public function testThirdPartyStoreGetsNullNotZeroForForever(): void
    {
        $store = new RecordingPsr16;
        $app = NestoFactory::create(new DynamicModule(TtlThirdPartyAppModule::class, imports: [CacheModule::register(['store' => $store, 'ttl' => 7])]));
        foreach (['/ttl/forever', '/ttl/default', '/ttl/ten'] as $path) {
            $app->handle(Request::create('GET', $path));
        }

        self::assertSame(
            [CacheInterceptor::keyFor('/ttl/forever') => null, CacheInterceptor::keyFor('/ttl/default') => 7, CacheInterceptor::keyFor('/ttl/ten') => 10],
            $store->ttls,
        );
        self::assertSame('HIT', $app->handle(Request::create('GET', '/ttl/forever'))->getHeaderLine('X-Cache'));
    }

    public function testBuiltInStoresTreatZeroAsForeverOverTheirDefault(): void
    {
        $dir = sys_get_temp_dir() . '/nesto-cache-ttl-' . uniqid();
        $stores = [
            'array' => new ArrayStore(defaultTtl: 1),
            'file' => new FileStore($dir, defaultTtl: 1),
            'adapter' => new Psr16Adapter(new ArrayStore, defaultTtl: 1),
        ];
        foreach ($stores as $store) {
            $store->set('zero', 1, 0);
            $store->set('default', 1);
        }
        usleep(1_100_000);
        foreach ($stores as $name => $store) {
            self::assertTrue($store->has('zero'), "$name: ttl 0 = forever");
            self::assertFalse($store->has('default'), "$name: null = store default");
        }
        $stores['file']->clear();
        @rmdir($dir);

        // the adapter turns "forever" into null for the wrapped store (0 means "expired" in PSR-16)
        $inner = new RecordingPsr16;
        (new Psr16Adapter($inner, defaultTtl: 30))->set('k', 1, 0);
        self::assertSame(['k' => null], $inner->ttls);
        self::assertTrue($inner->has('k'));
    }
}
