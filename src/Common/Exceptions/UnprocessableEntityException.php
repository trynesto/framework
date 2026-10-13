<?php

declare(strict_types=1);

namespace Nesto\Common\Exceptions;

class UnprocessableEntityException extends HttpException
{
    public const STATUS = 422;

    /** @param string|array<string, mixed> $response */
    public function __construct(string|array $response = '', ?\Throwable $previous = null)
    {
        parent::__construct($response === '' ? self::reasonPhrase(self::STATUS) : $response, self::STATUS, $previous);
    }
}
