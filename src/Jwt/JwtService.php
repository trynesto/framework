<?php

declare(strict_types=1);

namespace Nesto\Jwt;

/**
 * Nest's JwtService (@nestjs/jwt) without any dependency: HS256/384/512 via hash_hmac, RS256/384/512 via OpenSSL.
 *
 *   $jwt = new JwtService(['secret' => 'change-me', 'signOptions' => ['expiresIn' => '1h']]);
 *   $token   = $jwt->sign(['sub' => 42, 'roles' => ['admin']]);
 *   $payload = $jwt->verify($token);                       // ['sub' => 42, 'roles' => [...], 'iat' => ..., 'exp' => ...]
 *   $payload = $jwt->decode($token);                       // NO verification
 *   $jwt->sign($payload, ['expiresIn' => '7d', 'issuer' => 'nesto', 'audience' => 'app']);
 *   $jwt->verify($token, ['issuer' => 'nesto', 'audience' => 'app', 'clockTolerance' => 5]);
 *
 * RSA:
 *   new JwtService(['algorithm' => 'RS256', 'privateKey' => $pem, 'publicKey' => $pem]);
 *
 * Options accepted by the constructor: secret, publicKey, privateKey, algorithm (default HS256),
 * signOptions (expiresIn, notBefore, issuer, audience, subject, jwtid, algorithm, noTimestamp, header, keyid),
 * verifyOptions (algorithms, issuer, audience, subject, jwtid, clockTolerance, ignoreExpiration, ignoreNotBefore, maxAge).
 * `expiresIn` / `notBefore` / `maxAge` accept seconds (int) or strings such as '15s', '30m', '1h', '7d', '2w', '1y'.
 *
 * Every verification failure throws JwtException (a 401 UnauthorizedException).
 */
class JwtService
{
    public const ALGORITHMS = [
        'HS256' => ['hmac', 'sha256'],
        'HS384' => ['hmac', 'sha384'],
        'HS512' => ['hmac', 'sha512'],
        'RS256' => ['rsa', OPENSSL_ALGO_SHA256],
        'RS384' => ['rsa', OPENSSL_ALGO_SHA384],
        'RS512' => ['rsa', OPENSSL_ALGO_SHA512],
    ];

    private ?string $secret;
    private ?string $publicKey;
    private ?string $privateKey;
    private string $algorithm;
    /** @var array<string, mixed> */
    private array $signOptions;
    /** @var array<string, mixed> */
    private array $verifyOptions;

    /** @param array<string, mixed> $options */
    public function __construct(array $options = [])
    {
        $this->secret = isset($options['secret']) ? (string) $options['secret'] : null;
        $this->publicKey = isset($options['publicKey']) ? (string) $options['publicKey'] : null;
        $this->privateKey = isset($options['privateKey']) ? (string) $options['privateKey'] : null;
        $this->signOptions = (array) ($options['signOptions'] ?? []);
        $this->verifyOptions = (array) ($options['verifyOptions'] ?? []);
        $this->algorithm = strtoupper((string) ($options['algorithm'] ?? $this->signOptions['algorithm'] ?? ($this->privateKey || $this->publicKey ? 'RS256' : 'HS256')));
        $this->assertAlgorithm($this->algorithm);
    }

    /** @return array<string, mixed> */
    public function options(): array
    {
        return [
            'secret' => $this->secret,
            'publicKey' => $this->publicKey,
            'privateKey' => $this->privateKey,
            'algorithm' => $this->algorithm,
            'signOptions' => $this->signOptions,
            'verifyOptions' => $this->verifyOptions,
        ];
    }

    // ---- sign -----------------------------------------------------------------

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options expiresIn, notBefore, issuer, audience, subject, jwtid, algorithm, secret, privateKey, noTimestamp, header, keyid
     */
    public function sign(array $payload, array $options = []): string
    {
        $options = array_replace($this->signOptions, $options);
        $algorithm = strtoupper((string) ($options['algorithm'] ?? $this->algorithm));
        $this->assertAlgorithm($algorithm);
        $now = $this->now();

        if (! isset($payload['iat']) && empty($options['noTimestamp'])) {
            $payload['iat'] = $now;
        }
        if (isset($options['expiresIn']) && ! isset($payload['exp'])) {
            $payload['exp'] = ($payload['iat'] ?? $now) + self::parseDuration($options['expiresIn']);
        }
        if (isset($options['notBefore']) && ! isset($payload['nbf'])) {
            $payload['nbf'] = ($payload['iat'] ?? $now) + self::parseDuration($options['notBefore']);
        }
        foreach (['issuer' => 'iss', 'audience' => 'aud', 'subject' => 'sub', 'jwtid' => 'jti'] as $option => $claim) {
            if (isset($options[$option]) && ! isset($payload[$claim])) {
                $payload[$claim] = $options[$option];
            }
        }

        $header = ['alg' => $algorithm, 'typ' => 'JWT'] + (array) ($options['header'] ?? []);
        if (isset($options['keyid'])) {
            $header['kid'] = $options['keyid'];
        }

        $signingInput = self::base64UrlEncode(self::json($header)) . '.' . self::base64UrlEncode(self::json($payload));
        $signature = $this->signInput($signingInput, $algorithm, $options);

        return $signingInput . '.' . self::base64UrlEncode($signature);
    }

    // ---- verify ---------------------------------------------------------------

    /**
     * Verify the signature and the standard claims, returning the payload.
     *
     * @param array<string, mixed> $options algorithms, secret, publicKey, issuer, audience, subject, jwtid, clockTolerance, ignoreExpiration, ignoreNotBefore, maxAge, complete
     * @return array<string, mixed>
     * @throws JwtException
     */
    public function verify(string $token, array $options = []): array
    {
        $options = array_replace($this->verifyOptions, $options);
        [$header, $payload, $signature, $signingInput] = $this->split($token);

        $alg = strtoupper((string) ($header['alg'] ?? ''));
        $allowed = array_map('strtoupper', (array) ($options['algorithms'] ?? [$options['algorithm'] ?? $this->algorithm]));
        if ($alg === '' || $alg === 'NONE' || ! isset(self::ALGORITHMS[$alg]) || ! in_array($alg, $allowed, true)) {
            throw new JwtException('invalid algorithm', JwtException::ALGORITHM);
        }

        if (! $this->verifySignature($signingInput, $signature, $alg, $options)) {
            throw new JwtException('invalid signature', JwtException::INVALID_SIGNATURE);
        }

        $now = $this->now();
        $tolerance = (int) ($options['clockTolerance'] ?? 0);

        if (isset($payload['nbf']) && empty($options['ignoreNotBefore'])) {
            if (! is_numeric($payload['nbf'])) {
                throw new JwtException('invalid nbf value', JwtException::MALFORMED);
            }
            if ((int) $payload['nbf'] > $now + $tolerance) {
                throw new JwtException('jwt not active', JwtException::NOT_ACTIVE);
            }
        }
        if (isset($payload['exp']) && empty($options['ignoreExpiration'])) {
            if (! is_numeric($payload['exp'])) {
                throw new JwtException('invalid exp value', JwtException::MALFORMED);
            }
            if ($now - $tolerance >= (int) $payload['exp']) {
                throw new JwtException('jwt expired', JwtException::EXPIRED);
            }
        }
        if (isset($options['maxAge'])) {
            if (! isset($payload['iat']) || ! is_numeric($payload['iat'])) {
                throw new JwtException('iat required when maxAge is specified', JwtException::MALFORMED);
            }
            if ($now - $tolerance >= (int) $payload['iat'] + self::parseDuration($options['maxAge'])) {
                throw new JwtException('maxAge exceeded', JwtException::EXPIRED);
            }
        }
        if (isset($options['issuer'])) {
            $issuers = (array) $options['issuer'];
            if (! isset($payload['iss']) || ! in_array($payload['iss'], $issuers, true)) {
                throw new JwtException('jwt issuer invalid', JwtException::ISSUER);
            }
        }
        if (isset($options['audience'])) {
            $expected = (array) $options['audience'];
            $actual = isset($payload['aud']) ? (array) $payload['aud'] : [];
            if (array_intersect($expected, $actual) === []) {
                throw new JwtException('jwt audience invalid', JwtException::AUDIENCE);
            }
        }
        if (isset($options['subject']) && (($payload['sub'] ?? null) !== $options['subject'])) {
            throw new JwtException('jwt subject invalid', JwtException::SUBJECT);
        }
        if (isset($options['jwtid']) && (($payload['jti'] ?? null) !== $options['jwtid'])) {
            throw new JwtException('jwt id invalid', JwtException::JWT_ID);
        }

        return ! empty($options['complete']) ? ['header' => $header, 'payload' => $payload, 'signature' => $signature] : $payload;
    }

    /**
     * Decode without verifying anything (never trust the result for authorization).
     *
     * @param array{complete?: bool} $options
     * @return array<string, mixed> payload, or ['header' => ..., 'payload' => ..., 'signature' => ...] with complete: true
     */
    public function decode(string $token, array $options = []): array
    {
        [$header, $payload, $signature] = $this->split($token);

        return ! empty($options['complete']) ? ['header' => $header, 'payload' => $payload, 'signature' => $signature] : $payload;
    }

    // ---- helpers ------------------------------------------------------------------

    /**
     * '15s' → 15, '30m' → 1800, '1h' → 3600, '7d' → 604800, '2w', '1y', 3600 → 3600, '1h30m' → 5400.
     */
    public static function parseDuration(int|float|string $value): int
    {
        if (is_int($value) || is_float($value)) {
            return (int) $value;
        }
        $value = strtolower(trim($value));
        if ($value === '') {
            throw new \InvalidArgumentException('Empty duration.');
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        $units = [
            'ms' => 0.001, 'msec' => 0.001, 'msecs' => 0.001, 'millisecond' => 0.001, 'milliseconds' => 0.001,
            's' => 1, 'sec' => 1, 'secs' => 1, 'second' => 1, 'seconds' => 1,
            'm' => 60, 'min' => 60, 'mins' => 60, 'minute' => 60, 'minutes' => 60,
            'h' => 3600, 'hr' => 3600, 'hrs' => 3600, 'hour' => 3600, 'hours' => 3600,
            'd' => 86400, 'day' => 86400, 'days' => 86400,
            'w' => 604800, 'week' => 604800, 'weeks' => 604800,
            'y' => 31557600, 'yr' => 31557600, 'yrs' => 31557600, 'year' => 31557600, 'years' => 31557600,
        ];
        if (! preg_match_all('/(-?\d+(?:\.\d+)?)\s*([a-z]+)/', $value, $matches, PREG_SET_ORDER) || preg_replace('/(-?\d+(?:\.\d+)?)\s*([a-z]+)\s*/', '', $value) !== '') {
            throw new \InvalidArgumentException("Invalid duration \"$value\" (use seconds or e.g. '30m', '1h', '7d').");
        }
        $seconds = 0.0;
        foreach ($matches as $m) {
            if (! isset($units[$m[2]])) {
                throw new \InvalidArgumentException("Unknown duration unit \"{$m[2]}\" in \"$value\".");
            }
            $seconds += (float) $m[1] * $units[$m[2]];
        }

        return (int) round($seconds);
    }

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): string|false
    {
        $remainder = strlen($data) % 4;
        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($data, '-_', '+/'), true);
    }

    /** Overridable clock (tests). */
    protected function now(): int
    {
        return time();
    }

    // ---- internals -----------------------------------------------------------------

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string, 3: string} */
    private function split(string $token): array
    {
        $parts = explode('.', trim($token));
        if (count($parts) !== 3 || $parts[0] === '' || $parts[1] === '') {
            throw new JwtException('jwt malformed', JwtException::MALFORMED);
        }
        [$h, $p, $s] = $parts;
        $headerJson = self::base64UrlDecode($h);
        $payloadJson = self::base64UrlDecode($p);
        $signature = self::base64UrlDecode($s);
        if ($headerJson === false || $payloadJson === false || $signature === false) {
            throw new JwtException('jwt malformed', JwtException::MALFORMED);
        }
        $header = json_decode($headerJson, true);
        $payload = json_decode($payloadJson, true);
        if (! is_array($header) || ! is_array($payload)) {
            throw new JwtException('jwt malformed', JwtException::MALFORMED);
        }

        return [$header, $payload, $signature, $h . '.' . $p];
    }

    /** @param array<string, mixed> $options */
    private function signInput(string $input, string $algorithm, array $options): string
    {
        [$kind, $hash] = self::ALGORITHMS[$algorithm];
        if ($kind === 'hmac') {
            $secret = (string) ($options['secret'] ?? $this->secret ?? '');
            if ($secret === '') {
                throw new JwtException('secretOrPrivateKey must have a value', JwtException::MALFORMED);
            }

            return hash_hmac($hash, $input, $secret, true);
        }

        $pem = (string) ($options['privateKey'] ?? $this->privateKey ?? '');
        if ($pem === '') {
            throw new JwtException('secretOrPrivateKey must have a value', JwtException::MALFORMED);
        }
        $key = openssl_pkey_get_private($pem, (string) ($options['passphrase'] ?? ''));
        if ($key === false) {
            throw new JwtException('invalid private key: ' . (openssl_error_string() ?: 'unreadable PEM'), JwtException::MALFORMED);
        }
        $signature = '';
        if (! openssl_sign($input, $signature, $key, $hash)) {
            throw new JwtException('unable to sign: ' . (openssl_error_string() ?: 'openssl_sign failed'), JwtException::MALFORMED);
        }

        return $signature;
    }

    /** @param array<string, mixed> $options */
    private function verifySignature(string $input, string $signature, string $algorithm, array $options): bool
    {
        [$kind, $hash] = self::ALGORITHMS[$algorithm];
        if ($kind === 'hmac') {
            $secret = (string) ($options['secret'] ?? $this->secret ?? '');
            if ($secret === '') {
                throw new JwtException('secret or public key must be provided', JwtException::MALFORMED);
            }

            return hash_equals(hash_hmac($hash, $input, $secret, true), $signature);
        }

        $pem = (string) ($options['publicKey'] ?? $this->publicKey ?? $options['privateKey'] ?? $this->privateKey ?? '');
        if ($pem === '') {
            throw new JwtException('secret or public key must be provided', JwtException::MALFORMED);
        }
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            // a private key PEM can also verify (its public part is derived)
            $private = openssl_pkey_get_private($pem);
            if ($private === false) {
                throw new JwtException('invalid public key', JwtException::MALFORMED);
            }
            $details = openssl_pkey_get_details($private);
            $key = $details !== false ? openssl_pkey_get_public($details['key']) : false;
            if ($key === false) {
                throw new JwtException('invalid public key', JwtException::MALFORMED);
            }
        }

        return openssl_verify($input, $signature, $key, $hash) === 1;
    }

    private function assertAlgorithm(string $algorithm): void
    {
        if (! isset(self::ALGORITHMS[$algorithm])) {
            throw new \InvalidArgumentException("Unsupported JWT algorithm \"$algorithm\". Use one of " . implode(', ', array_keys(self::ALGORITHMS)) . '.');
        }
    }

    private static function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
