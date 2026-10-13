<?php

declare(strict_types=1);

namespace Nesto\Auth\Strategies;

use Nesto\Auth\PassportStrategy;
use Nesto\Http\Request;

/**
 * Header (or query) API-key strategy: reads the key and calls validate($key).
 *
 *   final class AppApiKeyStrategy extends ApiKeyStrategy {
 *       public function __construct(private readonly ApiKeys $keys) { parent::__construct(['header' => 'x-api-key']); }
 *       public function validate(string $key): mixed { return $this->keys->ownerOf($key); }
 *   }
 *   #[UseGuards(new AuthGuard('api-key'))]
 *
 * Options: name (default 'api-key'), header (default 'x-api-key'), query (optional query-string fallback, e.g. 'api_key').
 */
class ApiKeyStrategy extends PassportStrategy
{
    protected string $header;
    protected ?string $query;
    protected ?Request $request = null;

    /** @param array<string, mixed> $options */
    public function __construct(array $options = [])
    {
        parent::__construct((string) ($options['name'] ?? 'api-key'));
        $this->header = (string) ($options['header'] ?? 'x-api-key');
        $this->query = isset($options['query']) ? (string) $options['query'] : null;
    }

    public function authenticate(Request $request): mixed
    {
        $key = $request->header($this->header);
        if (($key === null || $key === '') && $this->query !== null) {
            $q = $request->queryParam($this->query);
            $key = is_scalar($q) ? (string) $q : null;
        }
        if ($key === null || trim($key) === '') {
            $this->fail('Missing API key');
        }
        $this->request = $request;
        try {
            $user = $this->validate(trim($key));
        } finally {
            $this->request = null;
        }
        if ($user === null || $user === false) {
            $this->fail('Invalid API key');
        }

        return $user;
    }

    /** Override: return the principal that owns the key, null / throw otherwise. */
    public function validate(string $key): mixed
    {
        throw new \LogicException(static::class . ' must override validate(string $key).');
    }
}
