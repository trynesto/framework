<?php

declare(strict_types=1);

namespace Nesto\Throttler;

use Nesto\Common\Attributes\Inject;
use Nesto\Common\Attributes\Optional;
use Nesto\Common\Contracts\Guard;
use Nesto\Core\ExecutionContext;
use Nesto\Core\Reflector;
use Nesto\Http\Request;
use Nesto\Throttler\Attributes\SkipThrottle;
use Nesto\Throttler\Attributes\Throttle;

/**
 * Rate-limiting guard (Nest's ThrottlerGuard). Counts hits per tracker (client IP by default) and
 * route; above the limit it throws ThrottlerException (429) with a Retry-After header. Every
 * allowed response gets X-RateLimit-Limit / X-RateLimit-Remaining / X-RateLimit-Reset.
 *
 *   #[Controller('auth')] #[UseGuards(ThrottlerGuard::class)]
 *   final class AuthController {
 *       #[Post('login')] #[Throttle(limit: 3, ttl: 60)]
 *       public function login(): array {}
 *       #[Get('me')] #[SkipThrottle]
 *       public function me(): array {}
 *   }
 *
 *   // globally: providers: [new Provider(Constants::APP_GUARD, useClass: ThrottlerGuard::class)]
 *
 * Subclass and override getTracker() to key by user id / API key instead of IP.
 */
class ThrottlerGuard implements Guard
{
    /** @param array{ttl?: int, limit?: int, ignoreUserAgents?: list<string>} $options */
    public function __construct(
        protected readonly ThrottlerStorage $storage,
        protected readonly Reflector $reflector,
        #[Inject(ThrottlerModule::OPTIONS)] #[Optional] protected readonly array $options = [],
    ) {
    }

    public function canActivate(ExecutionContext $context): bool
    {
        $handler = $context->getHandler();
        $class = $context->getClass();
        $request = $context->switchToHttp()->getRequest();

        $skip = $this->reflector->getAllAndOverride(SkipThrottle::class, [$handler, $class]);
        if ($skip instanceof SkipThrottle && $skip->skip) {
            return true;
        }
        if ($this->shouldIgnoreUserAgent($request)) {
            return true;
        }

        $throttle = $this->reflector->getAllAndOverride(Throttle::class, [$handler, $class]);
        $classThrottle = $this->reflector->get(Throttle::class, $class);
        $limit = $throttle?->limit ?? $classThrottle?->limit ?? (int) ($this->options['limit'] ?? 10);
        $ttl = $throttle?->ttl ?? $classThrottle?->ttl ?? (int) ($this->options['ttl'] ?? 60);

        return $this->handleRequest($context, $limit, $ttl);
    }

    protected function handleRequest(ExecutionContext $context, int $limit, int $ttl): bool
    {
        $request = $context->switchToHttp()->getRequest();
        $key = $this->generateKey($context, $this->getTracker($request));
        ['totalHits' => $hits, 'timeToExpire' => $expires] = $this->storage->increment($key, $ttl);

        $response = $context->switchToHttp()->getResponse();
        if ($hits > $limit) {
            $response->header('Retry-After', (string) $expires);
            $this->throwThrottlingException($context, $limit, $expires);
        }

        $response
            ->header('X-RateLimit-Limit', (string) $limit)
            ->header('X-RateLimit-Remaining', (string) max(0, $limit - $hits))
            ->header('X-RateLimit-Reset', (string) $expires);

        return true;
    }

    /** What identifies a client: the IP address by default. */
    protected function getTracker(Request $request): string
    {
        return $request->ip() ?? 'unknown';
    }

    /** Storage key: route (controller::handler) + tracker. */
    protected function generateKey(ExecutionContext $context, string $tracker): string
    {
        return sha1($context->getClassName() . '::' . $context->getHandlerName() . '|' . $tracker);
    }

    protected function shouldIgnoreUserAgent(Request $request): bool
    {
        $agent = $request->header('User-Agent', '') ?? '';
        foreach ($this->options['ignoreUserAgents'] ?? [] as $pattern) {
            if (@preg_match($pattern, $agent) === 1 || ($pattern !== '' && str_contains($agent, $pattern))) {
                return true;
            }
        }

        return false;
    }

    protected function throwThrottlingException(ExecutionContext $context, int $limit, int $retryAfter): never
    {
        throw new ThrottlerException('ThrottlerException: Too Many Requests', [
            'Retry-After' => (string) $retryAfter,
            'X-RateLimit-Limit' => (string) $limit,
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset' => (string) $retryAfter,
        ]);
    }
}
