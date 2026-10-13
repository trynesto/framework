<?php

declare(strict_types=1);

namespace Nesto\Http;

use Nyholm\Psr7\Response as PsrResponse;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;

/**
 * Response helpers + a small mutable builder for #[Res] injection.
 *
 *   return Response::json(['ok' => true], 201);
 *   return Response::text('pong');
 *   return Response::noContent();
 */
final class Response
{
    private int $status = 200;
    /** @var array<string, string> */
    private array $headers = [];
    private ?ResponseInterface $sent = null;

    public static function json(mixed $data, int $status = 200, array $headers = []): ResponseInterface
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return new PsrResponse($status, ['Content-Type' => 'application/json; charset=utf-8'] + $headers, $body);
    }

    public static function text(string $text, int $status = 200, array $headers = []): ResponseInterface
    {
        return new PsrResponse($status, ['Content-Type' => 'text/plain; charset=utf-8'] + $headers, $text);
    }

    public static function html(string $html, int $status = 200, array $headers = []): ResponseInterface
    {
        return new PsrResponse($status, ['Content-Type' => 'text/html; charset=utf-8'] + $headers, $html);
    }

    public static function noContent(): ResponseInterface
    {
        return new PsrResponse(204);
    }

    public static function redirect(string $url, int $status = 302): ResponseInterface
    {
        return new PsrResponse($status, ['Location' => $url]);
    }

    public static function stream($resource, string $contentType = 'application/octet-stream', int $status = 200): ResponseInterface
    {
        return new PsrResponse($status, ['Content-Type' => $contentType], Stream::create($resource));
    }

    public static function file(string $path, ?string $downloadName = null): ResponseInterface
    {
        $headers = ['Content-Type' => mime_content_type($path) ?: 'application/octet-stream'];
        if ($downloadName) {
            $headers['Content-Disposition'] = 'attachment; filename="' . addslashes($downloadName) . '"';
        }

        return new PsrResponse(200, $headers, Stream::create(fopen($path, 'rb')));
    }

    // ---- mutable builder (for #[Res] Response $res) ----------------------

    public function status(int $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /** Finalise the response from the builder's state. */
    public function send(mixed $body = null): ResponseInterface
    {
        $this->sent = $body instanceof ResponseInterface ? $body : self::json($body, $this->status, $this->headers);

        return $this->sent;
    }

    public function sent(): ?ResponseInterface
    {
        return $this->sent;
    }

    /** Apply builder status/headers on a PSR response produced elsewhere. */
    public function decorate(ResponseInterface $response): ResponseInterface
    {
        if ($this->status !== 200 && $response->getStatusCode() === 200) {
            $response = $response->withStatus($this->status);
        }
        foreach ($this->headers as $k => $v) {
            $response = $response->withHeader($k, $v);
        }

        return $response;
    }
}
