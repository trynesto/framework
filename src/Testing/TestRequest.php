<?php

declare(strict_types=1);

namespace Nesto\Testing;

use Nesto\Core\NestoApplication;
use Nesto\Http\Request;

/**
 * Supertest-like request builder. Terminal calls are send() / expect(); any TestResponse
 * assertion can also be called directly and the request is sent implicitly.
 *
 *   $module->request()->get('/cats')->withQuery(['limit' => 2])->expect(200)->assertJsonCount(2);
 *   $module->request()->post('/cats', ['name' => 'Tom'])->withBearerToken($jwt)->send()->assertCreated();
 *   $module->request()->delete('/cats/1')->assertNoContent();
 *
 * @method TestResponse assertStatus(int $status)
 * @method TestResponse assertOk()
 * @method TestResponse assertCreated()
 * @method TestResponse assertNoContent()
 * @method TestResponse assertNotFound()
 * @method TestResponse assertUnauthorized()
 * @method TestResponse assertForbidden()
 * @method TestResponse assertBadRequest()
 * @method TestResponse assertUnprocessable()
 * @method TestResponse assertJson(array $subset)
 * @method TestResponse assertJsonPath(string $path, mixed $expected)
 * @method TestResponse assertJsonCount(int $count, ?string $path = null)
 * @method TestResponse assertJsonStructure(array $structure)
 * @method TestResponse assertJsonValidationErrors(string|array $errors, ?int $status = null)
 * @method TestResponse assertJsonMissingValidationErrors(string|array $fields = [])
 * @method TestResponse assertHeader(string $name, ?string $value = null)
 * @method TestResponse assertSee(string $text)
 */
final class TestRequest
{
    private string $method = 'GET';
    private string $uri = '/';
    /** @var array<string, mixed> */
    private array $query = [];
    /** @var array<string, mixed>|string|null */
    private array|string|null $body = null;
    /** @var array<string, string> */
    private array $headers = [];
    /** @var array<string, mixed> */
    private array $server = ['REMOTE_ADDR' => '127.0.0.1'];
    /** @var array<string, mixed> multipart files, keyed like the form (`photos` => [file, file]) */
    private array $files = [];
    /** @var array<string, mixed> multipart text fields */
    private array $fields = [];

    public function __construct(private readonly NestoApplication $app) {}

    // ---- verbs ---------------------------------------------------------------

    public function get(string $uri): self
    {
        return $this->to('GET', $uri);
    }

    public function head(string $uri): self
    {
        return $this->to('HEAD', $uri);
    }

    public function options(string $uri): self
    {
        return $this->to('OPTIONS', $uri);
    }

    /** @param array<string, mixed>|string|null $body */
    public function post(string $uri, array|string|null $body = null): self
    {
        return $this->to('POST', $uri, $body);
    }

    /** @param array<string, mixed>|string|null $body */
    public function put(string $uri, array|string|null $body = null): self
    {
        return $this->to('PUT', $uri, $body);
    }

    /** @param array<string, mixed>|string|null $body */
    public function patch(string $uri, array|string|null $body = null): self
    {
        return $this->to('PATCH', $uri, $body);
    }

    /** @param array<string, mixed>|string|null $body */
    public function delete(string $uri, array|string|null $body = null): self
    {
        return $this->to('DELETE', $uri, $body);
    }

    /** @param array<string, mixed>|string|null $body */
    public function to(string $method, string $uri, array|string|null $body = null): self
    {
        $this->method = strtoupper($method);
        $this->uri = $uri;
        if ($body !== null) {
            $this->body = $body;
        }

        return $this;
    }

    // ---- modifiers ----------------------------------------------------------

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->headers[$name] = $value;
        }

        return $this;
    }

    public function withBearerToken(string $token): self
    {
        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }

    /** @param array<string, mixed> $query */
    public function withQuery(array $query): self
    {
        $this->query = $query + $this->query;

        return $this;
    }

    /** @param array<string, mixed>|string $body */
    public function withBody(array|string $body): self
    {
        $this->body = $body;

        return $this;
    }

    /** @param array<string, mixed> $json */
    public function withJson(array $json): self
    {
        $this->body = $json;

        return $this->withHeader('Content-Type', 'application/json');
    }

    /** Send the body as application/x-www-form-urlencoded. @param array<string, mixed> $form */
    public function withForm(array $form): self
    {
        $this->body = http_build_query($form);

        return $this->withHeader('Content-Type', 'application/x-www-form-urlencoded');
    }

    /**
     * Attach a file to a multipart request (supertest's .attach()). $file is a path or raw contents;
     * call it several times with the same field for `photos[]`.
     *
     *   ->post('/users/avatar')->attach('avatar', __DIR__ . '/fixtures/me.jpg')
     *   ->attach('doc', 'plain text', 'notes.txt', 'text/plain')
     */
    public function attach(string $field, string $file, ?string $filename = null, ?string $mimeType = null): self
    {
        $isPath = strlen($file) < PHP_MAXPATHLEN && ! str_contains($file, "\0") && is_file($file);
        $contents = $isPath ? (string) file_get_contents($file) : $file;
        $factory = new \Nyholm\Psr7\Factory\Psr17Factory;
        $upload = $factory->createUploadedFile(
            $factory->createStream($contents),
            strlen($contents),
            UPLOAD_ERR_OK,
            $filename ?? ($isPath ? basename($file) : $field),
            $mimeType ?? ($isPath ? (mime_content_type($file) ?: 'application/octet-stream') : 'application/octet-stream'),
        );
        // "photos[]" and "doc[main]" nest like PHP's $_FILES; a repeated plain name becomes a list too
        preg_match_all('/\[([^\]]*)\]/', $field, $m);
        $ref = &$this->files[strstr($field, '[', true) ?: $field];
        if ($m[1] === [] && $ref !== null) {
            $ref = [...(is_array($ref) ? $ref : [$ref]), $upload];
        } else {
            foreach ($m[1] as $key) {
                $ref = is_array($ref) ? $ref : [];
                if ($key === '') {
                    $ref[] = null;
                    $key = array_key_last($ref);
                }
                $ref = &$ref[$key];
            }
            $ref = $upload;
        }
        unset($ref);

        return $this->withHeader('Content-Type', 'multipart/form-data; boundary=nesto-test');
    }

    /** A text field of a multipart request (supertest's .field()); "tags[]" / "meta[key]" nest like PHP's $_POST. */
    public function field(string $name, string|int|float|bool $value): self
    {
        parse_str(rawurlencode($name) . '=' . rawurlencode(is_bool($value) ? ($value ? '1' : '0') : (string) $value), $parsed);
        $this->fields = array_merge_recursive($this->fields, $parsed);

        return $this->withHeader('Content-Type', 'multipart/form-data; boundary=nesto-test');
    }

    public function accept(string $mime): self
    {
        return $this->withHeader('Accept', $mime);
    }

    public function acceptJson(): self
    {
        return $this->accept('application/json');
    }

    /** @param array<string, mixed> $server */
    public function withServer(array $server): self
    {
        $this->server = $server + $this->server;

        return $this;
    }

    public function withIp(string $ip): self
    {
        $this->server['REMOTE_ADDR'] = $ip;

        return $this;
    }

    // ---- terminal -----------------------------------------------------------

    public function toRequest(): Request
    {
        $request = Request::create($this->method, $this->uri, $this->query, $this->body, $this->headers, $this->server);
        if ($this->files === [] && $this->fields === []) {
            return $request;
        }

        return new Request($request->psr()->withUploadedFiles($this->files)->withParsedBody($this->fields));
    }

    public function send(): TestResponse
    {
        return new TestResponse($this->app->handle($this->toRequest()));
    }

    /** Send and assert the status code in one go. */
    public function expect(int $status): TestResponse
    {
        return $this->send()->expect($status);
    }

    /** Forward any TestResponse method (assertOk(), json(), ...) by sending the request first. */
    public function __call(string $name, array $arguments): mixed
    {
        if (! method_exists(TestResponse::class, $name)) {
            throw new \BadMethodCallException(sprintf('Method %s::%s() does not exist.', self::class, $name));
        }

        return $this->send()->{$name}(...$arguments);
    }
}
