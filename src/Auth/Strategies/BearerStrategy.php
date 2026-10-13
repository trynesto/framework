<?php

declare(strict_types=1);

namespace Nesto\Auth\Strategies;

use Nesto\Auth\PassportStrategy;
use Nesto\Http\Request;

/**
 * passport-http-bearer equivalent: hands the raw `Authorization: Bearer <token>` value to validate($token)
 * (opaque tokens, database sessions, personal access tokens...).
 *
 *   final class TokenStrategy extends BearerStrategy {
 *       public function __construct(private readonly Tokens $tokens) { parent::__construct(); }
 *       public function validate(string $token): mixed { return $this->tokens->userFor($token); }
 *   }
 *   #[UseGuards(new AuthGuard('bearer'))]
 *
 * Options: name (default 'bearer').
 */
class BearerStrategy extends PassportStrategy
{
    protected ?Request $request = null;

    /** @param array<string, mixed> $options */
    public function __construct(array $options = [])
    {
        parent::__construct((string) ($options['name'] ?? 'bearer'));
    }

    public function authenticate(Request $request): mixed
    {
        $token = $request->bearerToken();
        if ($token === null) {
            $this->fail('No bearer token');
        }
        $this->request = $request;
        try {
            $user = $this->validate($token);
        } finally {
            $this->request = null;
        }
        if ($user === null || $user === false) {
            $this->fail('Invalid token');
        }

        return $user;
    }

    /** Override: return the user for the token, null / throw otherwise. */
    public function validate(string $token): mixed
    {
        throw new \LogicException(static::class . ' must override validate(string $token).');
    }
}
