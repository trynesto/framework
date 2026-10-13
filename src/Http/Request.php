<?php

declare(strict_types=1);

namespace Nesto\Http;

use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Thin, friendly wrapper over a PSR-7 ServerRequest. The PSR object is always reachable via psr().
 * Attributes (route params, authenticated user, ...) live on the PSR request so PSR-15 middleware see them too.
 */
final class Request
{
    private ?array $parsedBody = null;
    private bool $bodyParsed = false;

    public function __construct(private ServerRequestInterface $psr) {}

    public static function fromGlobals(): self
    {
        $creator = new \Nyholm\Psr7Server\ServerRequestCreator(
            $f = new \Nyholm\Psr7\Factory\Psr17Factory, $f, $f, $f
        );
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
        $type = strtolower(trim(explode(';', $contentType, 2)[0]));
        if ($method === 'POST' || ! in_array($type, ['multipart/form-data', 'application/x-www-form-urlencoded'], true)) {
            return new self($creator->fromGlobals());
        }

        // PHP fills $_POST/$_FILES for POST only: parse PUT/PATCH/DELETE form bodies ourselves
        [$post, $files] = FormBodyParser::fromGlobals($contentType);
        $server = $_SERVER + ['REQUEST_METHOD' => 'GET'];
        $headers = function_exists('getallheaders') ? getallheaders() : \Nyholm\Psr7Server\ServerRequestCreator::getHeadersFromServer($server);

        return new self($creator->fromArrays($server, $headers, $_COOKIE, $_GET, $post, $files, fopen('php://input', 'r') ?: null));
    }

    /** @param array<string, mixed> $query @param array<string, mixed>|string|null $body */
    public static function create(string $method, string $uri, array $query = [], array|string|null $body = null, array $headers = [], array $server = []): self
    {
        $req = new ServerRequest($method, $uri, $headers, is_array($body) ? json_encode($body) : $body, '1.1', $server);
        if (is_array($body) && ! $req->hasHeader('Content-Type')) {
            $req = $req->withHeader('Content-Type', 'application/json');
        }
        parse_str((string) $req->getUri()->getQuery(), $fromUri);

        return new self($req->withQueryParams($query + $fromUri));
    }

    public function psr(): ServerRequestInterface
    {
        return $this->psr;
    }

    public function withPsr(ServerRequestInterface $psr): self
    {
        $clone = clone $this;
        $clone->psr = $psr;
        $clone->bodyParsed = false;

        return $clone;
    }

    // ---- basics ---------------------------------------------------------

    public function method(): string
    {
        return strtoupper($this->psr->getMethod());
    }

    public function path(): string
    {
        $path = $this->psr->getUri()->getPath();

        return '/' . trim($path, '/');
    }

    public function url(): string
    {
        return (string) $this->psr->getUri();
    }

    public function isMethod(string $method): bool
    {
        return $this->method() === strtoupper($method);
    }

    public function ip(): ?string
    {
        $server = $this->psr->getServerParams();
        $forwarded = $this->header('X-Forwarded-For');
        if ($forwarded) {
            return trim(explode(',', $forwarded)[0]);
        }

        return $server['REMOTE_ADDR'] ?? null;
    }

    // ---- headers --------------------------------------------------------

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->psr->hasHeader($name) ? $this->psr->getHeaderLine($name) : $default;
    }

    /** @return array<string, string> lower-cased header names */
    public function headers(): array
    {
        $out = [];
        foreach ($this->psr->getHeaders() as $name => $values) {
            $out[strtolower($name)] = implode(', ', $values);
        }

        return $out;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('Authorization', '');
        if ($auth !== null && str_starts_with(strtolower($auth), 'bearer ')) {
            return trim(substr($auth, 7)) ?: null;
        }

        return null;
    }

    public function isJson(): bool
    {
        return str_contains($this->header('Content-Type', ''), 'json');
    }

    public function wantsJson(): bool
    {
        return str_contains($this->header('Accept', ''), 'json') || $this->isJson();
    }

    // ---- input ----------------------------------------------------------

    /** @return array<string, mixed> */
    public function query(): array
    {
        return $this->psr->getQueryParams();
    }

    public function queryParam(string $key, mixed $default = null): mixed
    {
        return data_get($this->psr->getQueryParams(), $key, $default);
    }

    /** Parsed JSON / form body as an array. */
    public function body(): array
    {
        if (! $this->bodyParsed) {
            $this->bodyParsed = true;
            $parsed = $this->psr->getParsedBody();
            if (is_array($parsed) && $parsed !== []) {
                $this->parsedBody = $parsed;
            } else {
                $raw = (string) $this->psr->getBody();
                if ($raw !== '' && ($this->isJson() || str_starts_with(ltrim($raw), '{') || str_starts_with(ltrim($raw), '['))) {
                    $decoded = json_decode($raw, true);
                    $this->parsedBody = is_array($decoded) ? $decoded : [];
                } elseif ($raw !== '' && str_contains($this->header('Content-Type', ''), 'x-www-form-urlencoded')) {
                    parse_str($raw, $form);
                    $this->parsedBody = $form;
                } else {
                    $this->parsedBody = is_array($parsed) ? $parsed : [];
                }
            }
        }

        return $this->parsedBody ?? [];
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return data_get($this->body(), $key, data_get($this->query(), $key, $default));
    }

    /** body + query merged (body wins). */
    public function all(): array
    {
        return array_replace($this->query(), $this->body());
    }

    public function rawBody(): string
    {
        return (string) $this->psr->getBody();
    }

    /** @return array<string, \Psr\Http\Message\UploadedFileInterface> */
    public function files(): array
    {
        return $this->psr->getUploadedFiles();
    }

    /** One uploaded file by field name (`avatar`), or the first upload when $field is null. */
    public function file(?string $field = null): ?File
    {
        if ($field === null) {
            return $this->fileList()[0] ?? null;
        }
        $upload = data_get($this->psr->getUploadedFiles(), $field);
        if (is_array($upload)) {
            $upload = reset($upload) ?: null;
        }

        return $upload instanceof UploadedFileInterface && $upload->getError() !== UPLOAD_ERR_NO_FILE ? new File($upload, $field) : null;
    }

    /**
     * Every uploaded file under $field (`photos[]`), or all uploads of the request when $field is null.
     *
     * @return list<File>
     */
    public function fileList(?string $field = null): array
    {
        $tree = $field === null ? $this->psr->getUploadedFiles() : data_get($this->psr->getUploadedFiles(), $field);
        $files = [];
        $walk = static function (mixed $node, string $name) use (&$walk, &$files): void {
            if ($node instanceof UploadedFileInterface) {
                if ($node->getError() !== UPLOAD_ERR_NO_FILE) {
                    $files[] = new File($node, $name);
                }

                return;
            }
            foreach ((array) $node as $key => $child) {
                $walk($child, $name === '' ? (string) $key : $name);
            }
        };
        $walk($tree, (string) $field);

        return $files;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        return $this->psr->getCookieParams()[$name] ?? $default;
    }

    // ---- route params & attributes ------------------------------------

    /** @return array<string, string> */
    public function params(): array
    {
        return $this->psr->getAttribute('__route_params', []);
    }

    public function param(string $name, mixed $default = null): mixed
    {
        return $this->params()[$name] ?? $default;
    }

    public function attribute(string $name, mixed $default = null): mixed
    {
        return $this->psr->getAttribute($name, $default);
    }

    /** Mutates in place: used by middleware/guards to attach user, params, etc. */
    public function setAttribute(string $name, mixed $value): self
    {
        $this->psr = $this->psr->withAttribute($name, $value);

        return $this;
    }

    public function user(): mixed
    {
        return $this->psr->getAttribute('user');
    }

    public function setUser(mixed $user): self
    {
        return $this->setAttribute('user', $user);
    }
}
