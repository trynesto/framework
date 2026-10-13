<?php

declare(strict_types=1);

namespace Nesto\Auth\Strategies;

use Nesto\Auth\PassportStrategy;
use Nesto\Http\Request;

/**
 * passport-local equivalent: reads credentials from the request body and calls validate($username, $password).
 *
 *   final class AppLocalStrategy extends LocalStrategy {
 *       public function __construct(private readonly AuthService $auth) {
 *           parent::__construct(['usernameField' => 'email']);
 *       }
 *       public function validate(string $username, string $password): mixed {
 *           return $this->auth->validateUser($username, $password) ?? $this->fail('Invalid credentials');
 *       }
 *   }
 *   #[Post('login')] #[UseGuards(new AuthGuard('local'))] public function login(#[CurrentUser] $user) { ... }
 *
 * Options: name (default 'local'), usernameField (default 'username'), passwordField (default 'password').
 * Fields are looked up in the body first, then in the query string. The current request is `$this->request`.
 */
class LocalStrategy extends PassportStrategy
{
    protected string $usernameField;
    protected string $passwordField;
    protected ?Request $request = null;

    /** @param array<string, mixed> $options */
    public function __construct(array $options = [])
    {
        parent::__construct((string) ($options['name'] ?? 'local'));
        $this->usernameField = (string) ($options['usernameField'] ?? 'username');
        $this->passwordField = (string) ($options['passwordField'] ?? 'password');
    }

    public function authenticate(Request $request): mixed
    {
        $username = $request->input($this->usernameField);
        $password = $request->input($this->passwordField);
        if (! is_scalar($username) || ! is_scalar($password) || (string) $username === '' || (string) $password === '') {
            $this->fail('Missing credentials');
        }
        $this->request = $request;
        try {
            $user = $this->validate((string) $username, (string) $password);
        } finally {
            $this->request = null;
        }
        if ($user === null || $user === false) {
            $this->fail('Invalid credentials');
        }

        return $user;
    }

    /** Override: return the user for valid credentials, null / throw otherwise. */
    public function validate(string $username, string $password): mixed
    {
        throw new \LogicException(static::class . ' must override validate(string $username, string $password).');
    }
}
