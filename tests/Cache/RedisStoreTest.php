<?php

declare(strict_types=1);

namespace Nesto\Tests\Cache;

use Nesto\Cache\CacheException;
use Nesto\Cache\InvalidArgumentException;
use Nesto\Cache\Redis\PhpRedisClient;
use Nesto\Cache\Redis\RedisConnector;
use Nesto\Cache\Stores\RedisStore;
use PHPUnit\Framework\TestCase;

/**
 * RedisStore against a fake client (always), and against a real server when NESTO_TEST_REDIS_URL is set
 * (e.g. redis://127.0.0.1:6379/15 — keys use the "nesto_test:" prefix and only those are deleted).
 */
final class RedisStoreTest extends TestCase
{
    public function testValuesAreSerializedUnderThePrefixWithNativeTtl(): void
    {
        $fake = new FakeRedisClient();
        $store = new RedisStore($fake, prefix: 'app:', defaultTtl: 60);

        self::assertTrue($store->set('cat', ['name' => 'Tom', 'age' => 3]));
        self::assertTrue($store->set('flag', false, 10));
        self::assertTrue($store->set('forever', 1, 0));

        self::assertSame(['name' => 'Tom', 'age' => 3], $store->get('cat'));
        self::assertFalse($store->get('flag', 'default'), 'false is a value, not a miss');
        self::assertSame('default', $store->get('nope', 'default'));
        self::assertSame(serialize(['name' => 'Tom', 'age' => 3]), $fake->data['app:cat'][0]);
        self::assertContains('SETEX app:cat 60', $fake->calls, 'the default TTL');
        self::assertContains('SETEX app:flag 10', $fake->calls);
        self::assertContains('SET app:forever', $fake->calls, '0 = no expiry');

        $fake->now += 11;
        self::assertFalse($store->has('flag'));
        self::assertTrue($store->has('cat'));
    }

    public function testMultipleAndDelete(): void
    {
        $fake = new FakeRedisClient();
        $store = new RedisStore($fake, prefix: 'p_');
        $store->setMultiple(['a' => 1, 'b' => 2]);

        self::assertSame(['a' => 1, 'b' => 2, 'c' => 'x'], $store->getMultiple(['a', 'b', 'c'], 'x'));
        self::assertContains('MGET p_a p_b p_c', $fake->calls);
        $store->delete('a');
        $store->deleteMultiple(['b']);
        self::assertSame([], $fake->data);
    }

    public function testClearOnlyRemovesThePrefixedKeys(): void
    {
        $fake = new FakeRedisClient();
        $fake->set('other_app:key', 'keep');
        $store = new RedisStore($fake, prefix: 'mine*:');
        $store->set('one', 1);
        $store->set('two', 2);

        self::assertTrue($store->clear());
        self::assertSame(['other_app:key'], array_keys($fake->data));
        self::assertContains('SCAN mine\*:*', $fake->calls, 'glob characters of the prefix are escaped');

        (new RedisStore($fake))->clear();
        self::assertContains('FLUSHDB', $fake->calls, 'no prefix: the whole database');
    }

    public function testKeysAreValidatedAndTheConnectionIsLazy(): void
    {
        $opened = 0;
        $store = new RedisStore(function () use (&$opened) {
            $opened++;

            return new FakeRedisClient();
        });
        self::assertSame(0, $opened);
        $store->set('k', 'v');
        $store->get('k');
        self::assertSame(1, $opened);

        $this->expectException(InvalidArgumentException::class);
        $store->get('bad:key');
    }

    public function testUrlParsing(): void
    {
        self::assertSame(
            ['scheme' => 'tls', 'host' => 'cache.internal', 'port' => 6380, 'username' => 'app', 'password' => 's3cr=t', 'database' => 2, 'timeout' => 1.5],
            RedisConnector::parse(['url' => 'rediss://app:s3cr%3Dt@cache.internal:6380/2', 'timeout' => 1.5]),
        );
        self::assertSame(['host' => 'h', 'port' => 1], RedisConnector::parse(['url' => null, 'host' => 'h', 'port' => 1]));
    }

    public function testMissingClientLibraryIsAClearError(): void
    {
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('predis/predis');
        RedisConnector::connect(['client' => 'none-of-them']);
    }

    public function testLiveServer(): void
    {
        $url = getenv('NESTO_TEST_REDIS_URL');
        if ($url === false || $url === '') {
            self::markTestSkipped('NESTO_TEST_REDIS_URL not configured (e.g. redis://127.0.0.1:6379/15).');
        }
        $store = new RedisStore(['url' => $url], prefix: 'nesto_test:', defaultTtl: 30);
        $store->clear();
        try {
            if (extension_loaded('redis')) {
                self::assertInstanceOf(PhpRedisClient::class, $store->client());
            }
            self::assertTrue($store->set('user', ['id' => 7, 'tags' => ['a']]));
            self::assertSame(['id' => 7, 'tags' => ['a']], $store->get('user'));
            self::assertTrue($store->has('user'));
            $store->set('short', 'gone soon', 1);
            $store->setMultiple(['m1' => 1, 'm2' => null]);
            self::assertSame(['m1' => 1, 'm2' => null, 'm3' => 'd'], $store->getMultiple(['m1', 'm2', 'm3'], 'd'));

            // another prefix in the same database survives clear()
            $neighbour = new RedisStore(['url' => $url], prefix: 'nesto_test_other:');
            $neighbour->set('keep', 'me');
            $store->clear();
            self::assertNull($store->get('user'));
            self::assertSame('me', $neighbour->get('keep'));
            $neighbour->clear();

            $store->set('ttl', 'x', 1);
            sleep(2);
            self::assertFalse($store->has('ttl'), 'native expiry');
        } finally {
            $store->clear();
        }
    }
}
