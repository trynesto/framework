<?php

declare(strict_types=1);

namespace Nesto\Auth\Strategies;

use Nesto\Auth\ExtractJwt;
use Nesto\Auth\PassportStrategy;
use Nesto\Http\Request;
use Nesto\Jwt\JwtService;

/**
 * passport-jwt equivalent. Extracts a token, verifies it with JwtService and hands the payload to validate().
 *
 *   final class AppJwtStrategy extends JwtStrategy {
 *       public function __construct(JwtService $jwt, private readonly UsersService $users) {
 *           parent::__construct($jwt, ['jwtFromRequest' => ExtractJwt::fromAuthHeaderAsBearerToken()]);
 *       }
 *       public function validate(array $payload): mixed {
 *           return $this->users->find($payload['sub']) ?? $this->fail();
 *       }
 *   }
 *   // register AppJwtStrategy as a provider, then #[UseGuards(new AuthGuard('jwt'))]
 *
 * Options: name (default 'jwt'), jwtFromRequest (Closure, default bearer header), verifyOptions (passed to
 * JwtService::verify(), e.g. issuer/audience), ignoreExpiration (bool).
 * The current request is available inside validate() as `$this->request`.
 * Without a subclass, validate() returns the raw payload.
 */
class JwtStrategy extends PassportStrategy
{
    protected \Closure $extractor;
    /** @var array<string, mixed> */
    protected array $verifyOptions;
    protected ?Request $request = null;

    /** @param array<string, mixed> $options */
    public function __construct(protected JwtService $jwt, array $options = [])
    {
        parent::__construct((string) ($options['name'] ?? 'jwt'));
        $this->extractor = $options['jwtFromRequest'] ?? ExtractJwt::fromAuthHeaderAsBearerToken();
        $this->verifyOptions = (array) ($options['verifyOptions'] ?? []);
        if (! empty($options['ignoreExpiration'])) {
            $this->verifyOptions['ignoreExpiration'] = true;
        }
    }

    public function authenticate(Request $request): mixed
    {
        $token = ($this->extractor)($request);
        if ($token === null) {
            $this->fail('No auth token');
        }
        $payload = $this->jwt->verify($token, $this->verifyOptions); // throws JwtException (401) when invalid
        $this->request = $request;
        try {
            $user = $this->validate($payload);
        } finally {
            $this->request = null;
        }
        if ($user === null || $user === false) {
            $this->fail();
        }

        return $user;
    }

    /**
     * Override to turn the verified payload into a user. Return null / throw to reject.
     *
     * @param array<string, mixed> $payload
     */
    public function validate(array $payload): mixed
    {
        return $payload;
    }
}
