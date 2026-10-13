<?php

declare(strict_types=1);

namespace Nesto\Http;

use Psr\Http\Message\ResponseInterface;

/** Writes a PSR-7 response to the SAPI output (PHP-FPM / built-in server). */
final class ResponseEmitter
{
    public function emit(ResponseInterface $response): void
    {
        if (! headers_sent()) {
            http_response_code($response->getStatusCode());
            if (! $response->hasHeader('X-Powered-By')) {
                header_remove('X-Powered-By'); // PHP's own "PHP/8.x" (expose_php) when the app sends none
            }
            foreach ($response->getHeaders() as $name => $values) {
                foreach ($values as $i => $value) {
                    header("$name: $value", $i === 0);
                }
            }
        }
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        while (! $body->eof()) {
            echo $body->read(8192);
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }
}
