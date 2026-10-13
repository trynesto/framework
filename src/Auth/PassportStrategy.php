<?php

declare(strict_types=1);

namespace Nesto\Auth;

use Nesto\Auth\Contracts\Strategy;
use Nesto\Common\Exceptions\UnauthorizedException;
use Nesto\Http\Request;

/**
 * Base class for strategies, mirroring `PassportStrategy(Strategy, 'name')` from @nestjs/passport.
 *
 * Subclasses implement authenticate() and usually expose a `validate(...)` method whose signature
 * depends on the strategy (JwtStrategy::validate(array $payload), LocalStrategy::validate($username, $password), ...).
 * The result of validate() is what ends up in `$request->user()` / `#[CurrentUser]`.
 *
 *   final class GithubStrategy extends PassportStrategy {
 *       public function __construct() { parent::__construct('github'); }
 *       public function authenticate(Request $request): mixed {
 *           $code = $request->queryParam('code') ?? $this->fail('Missing code');
 *           return $this->exchange($code);
 *       }
 *   }
 */
abstract class PassportStrategy implements Strategy
{
    public function __construct(protected string $name) {}

    public function name(): string
    {
        return $this->name;
    }

    abstract public function authenticate(Request $request): mixed;

    /** Convenience: abort authentication with a 401. */
    protected function fail(string $message = 'Unauthorized'): never
    {
        throw new UnauthorizedException($message);
    }
}
