<?php

declare(strict_types=1);

namespace Nesto\Auth;

use Nesto\Http\Request;

/**
 * Token extractors for JwtStrategy, same names as passport-jwt. Each returns a Closure(Request): ?string.
 *
 *   new JwtStrategy($jwt, ['jwtFromRequest' => ExtractJwt::fromAuthHeaderAsBearerToken()]);
 *   ExtractJwt::fromHeader('x-access-token');
 *   ExtractJwt::fromQuery('token');
 *   ExtractJwt::fromCookie('jwt');
 *   ExtractJwt::fromExtractors([ExtractJwt::fromAuthHeaderAsBearerToken(), ExtractJwt::fromQuery('token')]);
 */
final class ExtractJwt
{
    /** `Authorization: Bearer <token>` */
    public static function fromAuthHeaderAsBearerToken(): \Closure
    {
        return self::fromAuthHeaderWithScheme('Bearer');
    }

    /** `Authorization: <Scheme> <token>` (scheme compared case-insensitively). */
    public static function fromAuthHeaderWithScheme(string $scheme): \Closure
    {
        $scheme = strtolower($scheme);

        return function (Request $request) use ($scheme): ?string {
            $header = $request->header('Authorization');
            if ($header === null) {
                return null;
            }
            $parts = preg_split('/\s+/', trim($header), 2) ?: [];
            if (count($parts) === 2 && strtolower($parts[0]) === $scheme && $parts[1] !== '') {
                return trim($parts[1]);
            }

            return null;
        };
    }

    /** Raw value of a header. */
    public static function fromHeader(string $name): \Closure
    {
        return function (Request $request) use ($name): ?string {
            $value = $request->header($name);

            return $value === null || $value === '' ? null : trim($value);
        };
    }

    /** `?token=...` */
    public static function fromQuery(string $name = 'token'): \Closure
    {
        return function (Request $request) use ($name): ?string {
            $value = $request->queryParam($name);

            return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
        };
    }

    /** A field of the parsed body. */
    public static function fromBodyField(string $name = 'token'): \Closure
    {
        return function (Request $request) use ($name): ?string {
            $value = data_get($request->body(), $name);

            return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
        };
    }

    /** A cookie. */
    public static function fromCookie(string $name = 'jwt'): \Closure
    {
        return function (Request $request) use ($name): ?string {
            $value = $request->cookie($name);

            return $value === null || $value === '' ? null : $value;
        };
    }

    /**
     * First extractor that yields a token wins.
     *
     * @param list<\Closure(Request): ?string> $extractors
     */
    public static function fromExtractors(array $extractors): \Closure
    {
        return function (Request $request) use ($extractors): ?string {
            foreach ($extractors as $extractor) {
                $token = $extractor($request);
                if ($token !== null) {
                    return $token;
                }
            }

            return null;
        };
    }
}
