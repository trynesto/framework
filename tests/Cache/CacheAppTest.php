<?php

declare(strict_types=1);

namespace Nesto\Tests\Cache;

use Nesto\Cache\Attributes\CacheKey;
use Nesto\Cache\Attributes\CacheTTL;
use Nesto\Cache\Attributes\NoCache;
use Nesto\Cache\CacheInterceptor;
use Nesto\Cache\CacheModule;
use Nesto\Common\Attributes\Controller;
use Nesto\Common\Attributes\Get;
use Nesto\Common\Attributes\Inject;
use Nesto\Common\Attributes\Module;
use Nesto\Common\Attributes\Post;
use Nesto\Common\Attributes\Query;
use Nesto\Common\Attributes\UseInterceptors;
use Nesto\Common\DynamicModule;
use Nesto\Common\Provider;
use Nesto\Core\NestoFactory;
use Nesto\Http\Request;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

#[Controller('cats')]
#[UseInterceptors(CacheInterceptor::class)]
final class CatsController
{
    public int $calls = 0;

    public function __construct(private readonly CacheInterface $cache, #[Inject('CACHE_MANAGER')] private readonly CacheInterface $manager)
    {
    }

    #[Get()]
    #[CacheTTL(30)]
    public function findAll(#[Query('page')] int $page = 1): array
    {
        return ['calls' => ++$this->calls, 'page' => $page, 'same' => $this->cache === $this->manager];
    }

    #[Get('fresh')]
    #[NoCache]
    public function fresh(): array
    {
        return ['calls' => ++$this->calls];
    }

    #[Get('keyed')]
    #[CacheKey('cats.keyed')]
    public function keyed(): array
    {
        return ['calls' => ++$this->calls];
    }

    #[Post()]
    public function create(): array
    {
        return ['calls' => ++$this->calls];
    }
}

#[Module(imports: [new CacheModule(ttl: 5, store: 'memory')], controllers: [CatsController::class])]
final class CacheAppModule
{
}

#[Module(providers: [new Provider('CACHE_DIR', useFactory: [CacheConfigModule::class, 'dir'])], exports: ['CACHE_DIR'])]
final class CacheConfigModule
{
    public static function dir(): string
    {
        return CacheAppTest::asyncDir();
    }
}

#[Module(controllers: [CatsController::class])]
final class AsyncCacheAppModule
{
}

final class CacheAppTest extends TestCase
{
    public static function asyncDir(): string
    {
        return self::tempDir();
    }

    private static function tempDir(): string
    {
        return sys_get_temp_dir() . '/nesto-cache-async-' . getmypid();
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

    public function testSecondGetIsServedFromCache(): void
    {
        $app = NestoFactory::create(CacheAppModule::class);

        $first = $app->handle(Request::create('GET', '/cats'));
        $second = $app->handle(Request::create('GET', '/cats'));
        self::assertSame(200, $second->getStatusCode());
        self::assertSame('MISS', $first->getHeaderLine('X-Cache'));
        self::assertSame('HIT', $second->getHeaderLine('X-Cache'));
        self::assertSame((string) $first->getBody(), (string) $second->getBody());
        self::assertSame(['calls' => 1, 'page' => 1, 'same' => true], json_decode((string) $second->getBody(), true));

        // different query → different key
        $paged = $app->handle(Request::create('GET', '/cats', ['page' => '2']));
        self::assertSame(['calls' => 2, 'page' => 2, 'same' => true], json_decode((string) $paged->getBody(), true));

        // #[NoCache] and POST never cached
        $app->handle(Request::create('GET', '/cats/fresh'));
        $fresh = $app->handle(Request::create('GET', '/cats/fresh'));
        self::assertSame(['calls' => 4], json_decode((string) $fresh->getBody(), true));
        $app->handle(Request::create('POST', '/cats'));
        $post = $app->handle(Request::create('POST', '/cats'));
        self::assertSame(['calls' => 6], json_decode((string) $post->getBody(), true));

        // #[CacheKey] is used verbatim and can be invalidated by hand
        $app->handle(Request::create('GET', '/cats/keyed'));
        $cache = $app->get(CacheInterface::class);
        self::assertTrue($cache->has('cats.keyed'));
        self::assertTrue($cache->has(CacheInterceptor::keyFor('/cats', [])));
        $cache->delete('cats.keyed');
        $keyed = $app->handle(Request::create('GET', '/cats/keyed'));
        self::assertSame(['calls' => 8], json_decode((string) $keyed->getBody(), true));
    }

    public function testRegisterAsyncWithInjectedOptions(): void
    {
        $root = new DynamicModule(
            module: AsyncCacheAppModule::class,
            imports: [CacheModule::registerAsync([
                'imports' => [CacheConfigModule::class],
                'useFactory' => fn (string $path) => ['store' => 'file', 'path' => $path, 'ttl' => 10],
                'inject' => ['CACHE_DIR'],
            ])],
        );
        $app = NestoFactory::create($root);
        $app->handle(Request::create('GET', '/cats'));
        $hit = $app->handle(Request::create('GET', '/cats'));

        self::assertSame('HIT', $hit->getHeaderLine('X-Cache'));
        self::assertInstanceOf(\Nesto\Cache\Stores\FileStore::class, $app->get(CacheInterface::class));
        self::assertSame(10, $app->get(CacheModule::OPTIONS)['ttl']);
        $app->get(CacheInterface::class)->clear();
    }
}
