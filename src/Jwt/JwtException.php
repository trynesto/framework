<?php

declare(strict_types=1);

namespace Nesto\Jwt;

use Nesto\Common\Exceptions\UnauthorizedException;

/**
 * Raised by JwtService::verify() — a 401 so it can bubble straight out of a guard.
 *
 *   try { $jwt->verify($token); } catch (JwtException $e) { $e->reason(); } // 'expired', 'invalid_signature', ...
 */
class JwtException extends UnauthorizedException
{
    public const MALFORMED = 'malformed';
    public const INVALID_SIGNATURE = 'invalid_signature';
    public const EXPIRED = 'expired';
    public const NOT_ACTIVE = 'not_active';
    public const ISSUER = 'invalid_issuer';
    public const AUDIENCE = 'invalid_audience';
    public const SUBJECT = 'invalid_subject';
    public const ALGORITHM = 'invalid_algorithm';
    public const JWT_ID = 'invalid_jwt_id';

    public function __construct(string $message, private readonly string $reason = self::MALFORMED, ?\Throwable $previous = null)
    {
        parent::__construct($message, $previous);
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
