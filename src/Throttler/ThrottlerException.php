<?php

declare(strict_types=1);

namespace Nesto\Throttler;

use Nesto\Common\Exceptions\TooManyRequestsException;

/**
 * 429 thrown by ThrottlerGuard; carries the rate-limit headers that ThrottlerExceptionFilter
 * copies onto the response (Retry-After, X-RateLimit-*).
 */
final class ThrottlerException extends TooManyRequestsException
{
    /** @param array<string, string> $headers */
    public function __construct(
        string|array $response = 'ThrottlerException: Too Many Requests',
        public readonly array $headers = [],
    ) {
        parent::__construct($response);
    }
}
