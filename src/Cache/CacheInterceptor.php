<?php

declare(strict_types=1);

namespace Nesto\Cache;

use Nesto\Cache\Attributes\CacheKey;
use Nesto\Cache\Attributes\CacheTTL;
use Nesto\Cache\Attributes\NoCache;
use Nesto\Common\Attributes\Inject;
use Nesto\Common\Attributes\Optional;
use Nesto\Common\Contracts\CallHandler;
use Nesto\Common\Contracts\Interceptor;
use Nesto\Core\ExecutionContext;
use Nesto\Core\Reflector;
use Psr\Http\Message\ResponseInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Caches GET handler results (Nest's CacheInterceptor). The key is `#[CacheKey]` when present,
 * otherwise a hash of the request path + query (see keyFor()); the TTL comes from `#[CacheTTL]`
 * (method, then class) or the module's default, and `0` (either way) means no expiry. `#[NoCache]`
 * opts a handler out. Hits carry an `X-Cache: HIT` header. PSR-7 responses returned by handlers
 * are never cached.
 *
 *   #[Controller('cats')] #[UseInterceptors(CacheInterceptor::class)]
 *   final class CatsController {
 *       #[Get()] #[CacheTTL(30)]
 *       public function findAll(): array {}
 *   }
 *
 *   // globally: new Provider(Constants::APP_INTERCEPTOR, useClass: CacheInterceptor::class)
 *
 * Extend and override trackBy() / isRequestCacheable() to customise (per-user keys, other verbs…).
 */
class CacheInterceptor implements Interceptor
{
    private const MISS = "\0nesto.cache.miss";

    /** @param array{ttl?: int} $options */
    public function __construct(
        protected readonly CacheInterface $cache,
        protected readonly Reflector $reflector,
        #[Inject(CacheModule::OPTIONS)] #[Optional] protected readonly array $options = [],
    ) {
    }

    public function intercept(ExecutionContext $context, CallHandler $next): mixed
    {
        if (! $this->isRequestCacheable($context)) {
            return $next->handle();
        }
        $key = $this->trackBy($context);
        if ($key === null) {
            return $next->handle();
        }

        $cached = $this->cache->get($key, self::MISS);
        if ($cached !== self::MISS) {
            $context->switchToHttp()->getResponse()->header('X-Cache', 'HIT');

            return $cached;
        }

        $result = $next->handle();
        if ($result instanceof ResponseInterface) {
            return $result;
        }
        $ttl = $this->ttlFor($context);
        $this->cache->set($key, $result, $ttl > 0 ? $ttl : $this->foreverTtl());
        $context->switchToHttp()->getResponse()->header('X-Cache', 'MISS');

        return $result;
    }

    /** Only GET requests, unless #[NoCache] is on the handler or its class. */
    protected function isRequestCacheable(ExecutionContext $context): bool
    {
        if ($context->switchToHttp()->getRequest()->method() !== 'GET') {
            return false;
        }

        return $this->reflector->getAllAndOverride(NoCache::class, [$context->getHandler(), $context->getClass()]) === null;
    }

    /** Cache key for this request; return null to skip caching. */
    protected function trackBy(ExecutionContext $context): ?string
    {
        $attr = $this->reflector->getAllAndOverride(CacheKey::class, [$context->getHandler(), $context->getClass()]);
        if ($attr instanceof CacheKey) {
            return $attr->key;
        }
        $request = $context->switchToHttp()->getRequest();

        return self::keyFor($request->path(), $request->query());
    }

    protected function ttlFor(ExecutionContext $context): int
    {
        $attr = $this->reflector->getAllAndOverride(CacheTTL::class, [$context->getHandler(), $context->getClass()]);

        return $attr instanceof CacheTTL ? $attr->ttl : (int) ($this->options['ttl'] ?? 0);
    }

    /**
     * TTL meaning "no expiry" for the store: 0 for the built-in stores (null would apply their default TTL);
     * null for any other PSR-16 cache, where 0 means "already expired" and null is the closest to forever.
     */
    private function foreverTtl(): ?int
    {
        return in_array(Stores\Psr16Support::class, class_uses($this->cache), true) ? 0 : null;
    }

    /** Deterministic PSR-16-safe key for a path + query (use it to invalidate a cached route by hand). */
    public static function keyFor(string $path, array $query = []): string
    {
        ksort($query);

        return 'http_' . sha1($path . '?' . http_build_query($query));
    }
}
