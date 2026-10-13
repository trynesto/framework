<?php

declare(strict_types=1);

namespace Nesto\Tests\Throttler;

use Nesto\Cache\Stores\ArrayStore;
use Nesto\Throttler\Storage\FileStorage;
use Nesto\Throttler\Storage\MemoryStorage;
use Nesto\Throttler\Storage\Psr16Storage;
use Nesto\Throttler\ThrottlerModule;
use Nesto\Throttler\ThrottlerStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StorageTest extends TestCase
{

    private static function tempDir(): string
    {
        return sys_get_temp_dir() . '/nesto-throttler-test-' . getmypid();
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

    /** @return array<string, array{\Closure(): ThrottlerStorage}> */
    public static function storages(): array
    {
        return [
            'memory' => [fn () => new MemoryStorage],
            'file' => [fn () => new FileStorage(self::tempDir())],
            'psr16' => [fn () => new Psr16Storage(new ArrayStore)],
        ];
    }

    /** @param \Closure(): ThrottlerStorage $factory */
    #[DataProvider('storages')]
    public function testIncrementCountsWithinWindow(\Closure $factory): void
    {
        $storage = $factory();
        $key = 'k-' . uniqid();
        $first = $storage->increment($key, 60);
        $second = $storage->increment($key, 60);
        $other = $storage->increment($key . '-other', 60);

        self::assertSame(1, $first['totalHits']);
        self::assertSame(2, $second['totalHits']);
        self::assertSame(1, $other['totalHits']);
        self::assertGreaterThanOrEqual(59, $second['timeToExpire']);
        self::assertLessThanOrEqual(60, $second['timeToExpire']);
    }

    public function testWindowResets(): void
    {
        $storage = new MemoryStorage;
        $storage->increment('k', 1);
        $storage->increment('k', 1);
        usleep(1_100_000);
        self::assertSame(1, $storage->increment('k', 1)['totalHits']);
    }

    public function testCreateStorage(): void
    {
        self::assertInstanceOf(MemoryStorage::class, ThrottlerModule::createStorage(null));
        self::assertInstanceOf(FileStorage::class, ThrottlerModule::createStorage('file:' . self::tempDir()));
        self::assertInstanceOf(MemoryStorage::class, ThrottlerModule::createStorage(MemoryStorage::class));
        $this->expectException(\InvalidArgumentException::class);
        ThrottlerModule::createStorage('redis');
    }
}
