<?php

declare(strict_types=1);

namespace Nesto\Tests\Cache;

use Nesto\Cache\CacheModule;
use Nesto\Cache\InvalidArgumentException;
use Nesto\Cache\Stores\ArrayStore;
use Nesto\Cache\Stores\FileStore;
use Nesto\Cache\Stores\Psr16Adapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

final class StoresTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nesto-cache-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            array_map('unlink', glob($this->dir . '/*') ?: []);
            rmdir($this->dir);
        }
    }


    private static function tempDir(): string
    {
        return sys_get_temp_dir() . '/nesto-cache-test-shared-' . getmypid();
    }

    public static function tearDownAfterClass(): void
    {
        $dir = self::tempDir();
        foreach (glob($dir . '/{,*/}{.,}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $d) {
            @rmdir($d);
        }
        @rmdir($dir);
    }

    /** @return array<string, array{\Closure(): CacheInterface}> */
    public static function stores(): array
    {
        return [
            'array' => [fn () => new ArrayStore],
            'file' => [fn () => new FileStore(self::tempDir())],
            'adapter' => [fn () => new Psr16Adapter(new ArrayStore, prefix: 'p_')],
        ];
    }

    /** @param \Closure(): CacheInterface $factory */
    #[DataProvider('stores')]
    public function testBasicOperations(\Closure $factory): void
    {
        $cache = $factory();
        $cache->clear();
        self::assertFalse($cache->has('k'));
        self::assertSame('default', $cache->get('k', 'default'));
        self::assertTrue($cache->set('k', ['a' => 1]));
        self::assertTrue($cache->has('k'));
        self::assertSame(['a' => 1], $cache->get('k'));
        self::assertTrue($cache->setMultiple(['x' => 1, 'y' => 2]));
        self::assertSame(['x' => 1, 'y' => 2, 'nope' => null], iterator_to_array($cache->getMultiple(['x', 'y', 'nope'])));
        self::assertTrue($cache->deleteMultiple(['x']));
        self::assertFalse($cache->has('x'));
        self::assertTrue($cache->delete('k'));
        self::assertFalse($cache->has('k'));
        self::assertTrue($cache->clear());
        self::assertFalse($cache->has('y'));
    }

    public function testTtlExpiry(): void
    {
        $array = new ArrayStore;
        $file = new FileStore($this->dir);
        $array->set('a', 1, 1);
        $file->set('a', 1, 1);
        $array->set('forever', 1, 0);
        self::assertTrue($array->has('a'));
        self::assertTrue($file->has('a'));
        usleep(1_100_000);
        self::assertFalse($array->has('a'));
        self::assertNull($file->get('a'));
        self::assertTrue($array->has('forever'));
        self::assertSame(0, $file->gc());
    }

    public function testArrayStoreMaxEvictsOldest(): void
    {
        $store = new ArrayStore(max: 2);
        $store->set('a', 1);
        $store->set('b', 2);
        $store->set('c', 3);
        self::assertFalse($store->has('a'));
        self::assertTrue($store->has('b'));
        self::assertSame(2, $store->count());
    }

    public function testInvalidKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ArrayStore)->get('bad/key');
    }

    public function testFileStoreSurvivesNewInstance(): void
    {
        (new FileStore($this->dir))->set('obj', new \DateTimeImmutable('2026-01-01'));
        $again = new FileStore($this->dir);
        self::assertInstanceOf(\DateTimeImmutable::class, $again->get('obj'));
    }

    public function testCreateStore(): void
    {
        self::assertInstanceOf(ArrayStore::class, CacheModule::createStore(['store' => 'memory']));
        self::assertInstanceOf(FileStore::class, CacheModule::createStore(['store' => 'file', 'path' => $this->dir]));
        $custom = new ArrayStore;
        self::assertSame($custom, CacheModule::createStore(['store' => $custom]));
    }
}
