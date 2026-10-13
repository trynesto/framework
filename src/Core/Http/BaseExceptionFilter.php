<?php

declare(strict_types=1);

namespace Nesto\Core\Http;

use Nesto\Common\Contracts\ExceptionFilter;
use Nesto\Common\Exceptions\HttpException;
use Nesto\Core\ArgumentsHost;
use Nesto\Http\Response;
use Nesto\Logger\Logger;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * The fallback filter, same output as Nest:
 *   HttpException → its response body + status
 *   anything else → { statusCode: 500, message: 'Internal server error' } (+ exception details when debug),
 *                   logged with its stack trace like Nest's ExceptionsHandler (framework Logger, context
 *                   "ExceptionsHandler"; Logger::overrideLogger() sends it to your own PSR-3 logger)
 */
class BaseExceptionFilter implements ExceptionFilter
{
    /** @param bool|null $debug null = follow the APP_DEBUG environment variable, read when an error is rendered */
    public function __construct(
        protected readonly ?bool $debug = false,
        protected readonly ?LoggerInterface $logger = null,
    ) {}

    public function catch(\Throwable $exception, ArgumentsHost $host): ResponseInterface
    {
        if ($exception instanceof HttpException) {
            return Response::json($exception->getResponse(), $exception->getStatus());
        }

        ($this->logger ?? new Logger('ExceptionsHandler'))->error($exception->getMessage(), ['exception' => $exception]);

        $body = ['statusCode' => 500, 'message' => 'Internal server error'];
        if ($this->debug ?? (bool) env('APP_DEBUG', false)) {
            $body['exception'] = $exception::class;
            $body['message'] = $exception->getMessage();
            $body['file'] = $exception->getFile() . ':' . $exception->getLine();
            $body['trace'] = array_slice(explode("\n", $exception->getTraceAsString()), 0, 15);
        }

        return Response::json($body, 500);
    }
}
