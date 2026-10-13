<?php

declare(strict_types=1);

namespace Nesto\Auth;

use Nesto\Auth\Attributes\AllowAnonymous;
use Nesto\Auth\Attributes\IsPublic;
use Nesto\Auth\Contracts\Strategy;
use Nesto\Common\Attributes\Injectable;
use Nesto\Common\Contracts\Guard;
use Nesto\Common\Exceptions\UnauthorizedException;
use Nesto\Core\ExecutionContext;

/**
 * Nest's AuthGuard('strategy'). Runs one (or several, comma separated) strategies and stores the user on the request.
 *
 *   #[UseGuards(new AuthGuard('jwt'))]            // explicit strategy (attributes allow `new` with constant args)
 *   #[UseGuards(AuthGuard::class)]                // default strategy of AuthModule (or the only registered one)
 *   #[UseGuards(new AuthGuard('jwt,api-key'))]    // first strategy that authenticates wins
 *
 *   final class JwtAuthGuard extends AuthGuard { protected string $strategy = 'jwt'; }
 *   #[UseGuards(JwtAuthGuard::class)]
 *
 * Handlers / controllers marked #[IsPublic] or #[AllowAnonymous] (or #[SetMetadata('isPublic', true)]) are skipped:
 * the guard still tries to authenticate so #[CurrentUser] is populated when a valid token is present, but never fails.
 * On failure the strategy's UnauthorizedException (401) propagates — override handleRequest() to customise.
 */
#[Injectable]
class AuthGuard implements Guard
{
    public const IS_PUBLIC = 'isPublic';

    /** Strategy name(s); empty → AuthModule's default strategy. Subclasses may simply override this property. */
    protected string $strategy = '';

    public function __construct(?string $strategy = null, protected ?StrategyRegistry $registry = null)
    {
        if ($strategy !== null && $strategy !== '') {
            $this->strategy = $strategy;
        }
    }

    public function canActivate(ExecutionContext $context): bool
    {
        $request = $context->switchToHttp()->getRequest();
        $public = $this->isPublic($context);

        $lastError = null;
        foreach ($this->strategyNames() as $name) {
            try {
                $user = $this->registry()->get($name)->authenticate($request);
            } catch (UnauthorizedException $e) {
                $lastError = $e;
                continue;
            }
            if ($user !== null && $user !== false) {
                $request->setUser($this->handleRequest($user, $context));

                return true;
            }
        }

        if ($public) {
            return true;
        }
        if ($lastError !== null) {
            throw $lastError;
        }

        throw new UnauthorizedException;
    }

    /** Hook: transform the authenticated user before it is stored on the request. */
    protected function handleRequest(mixed $user, ExecutionContext $context): mixed
    {
        return $user;
    }

    /** @return list<string> */
    protected function strategyNames(): array
    {
        $names = array_values(array_filter(array_map('trim', explode(',', $this->strategy)), fn ($n) => $n !== ''));
        if ($names === []) {
            $default = $this->registry()->defaultStrategy();
            if ($default === null) {
                throw new \LogicException(
                    'AuthGuard: no strategy given and AuthModule has no default. Use new AuthGuard(\'jwt\') or new AuthModule(defaultStrategy: \'jwt\').'
                );
            }
            $names = [$default];
        }

        return $names;
    }

    protected function registry(): StrategyRegistry
    {
        return $this->registry ??= StrategyRegistry::current()
            ?? throw new \LogicException('AuthGuard: AuthModule is not imported (no StrategyRegistry available).');
    }

    protected function isPublic(ExecutionContext $context): bool
    {
        foreach ([$context->getHandler(), $context->getClass()] as $target) {
            if ($target->getAttributes(IsPublic::class) !== [] || $target->getAttributes(AllowAnonymous::class) !== []) {
                return true;
            }
            foreach ($target->getAttributes(\Nesto\Common\Attributes\SetMetadata::class) as $attr) {
                $meta = $attr->newInstance();
                if ($meta->key === self::IS_PUBLIC && $meta->value) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Direct access to a strategy (e.g. to call authenticate() manually). */
    public function strategy(string $name): Strategy
    {
        return $this->registry()->get($name);
    }
}
