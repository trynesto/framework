<?php

declare(strict_types=1);

namespace Nesto\Common\Exceptions;

use RuntimeException;

/**
 * Base HTTP exception, identical in spirit to Nest's:
 *   throw new HttpException('Forbidden', 403);
 *   throw new HttpException(['statusCode' => 403, 'message' => 'x', 'error' => 'Custom'], 403);
 */
class HttpException extends RuntimeException
{
    /** @var array<string, mixed> */
    protected array $response;

    /** @param string|array<string, mixed> $response */
    public function __construct(string|array $response, int $status = 500, ?\Throwable $previous = null)
    {
        $this->response = is_array($response) ? $response : $this->buildResponse($response, $status);
        $message = $this->response['message'] ?? null;
        parent::__construct(is_string($message) ? $message : 'Http Exception', $status, $previous);
    }

    public function getStatus(): int
    {
        return $this->getCode();
    }

    /** @return array<string, mixed> */
    public function getResponse(): array
    {
        return $this->response;
    }

    /** @return array<string, mixed> */
    protected function buildResponse(string $message, int $status): array
    {
        return [
            'statusCode' => $status,
            'message' => $message,
            'error' => static::defaultError() ?? self::reasonPhrase($status),
        ];
    }

    protected static function defaultError(): ?string
    {
        return null;
    }

    public static function reasonPhrase(int $status): string
    {
        return match ($status) {
            400 => 'Bad Request', 401 => 'Unauthorized', 402 => 'Payment Required', 403 => 'Forbidden',
            404 => 'Not Found', 405 => 'Method Not Allowed', 406 => 'Not Acceptable', 408 => 'Request Timeout',
            409 => 'Conflict', 410 => 'Gone', 412 => 'Precondition Failed', 413 => 'Payload Too Large',
            415 => 'Unsupported Media Type', 418 => "I'm a teapot", 422 => 'Unprocessable Entity',
            429 => 'Too Many Requests', 500 => 'Internal Server Error', 501 => 'Not Implemented',
            502 => 'Bad Gateway', 503 => 'Service Unavailable', 504 => 'Gateway Timeout',
            default => 'Error',
        };
    }
}
