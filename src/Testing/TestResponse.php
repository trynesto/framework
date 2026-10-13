<?php

declare(strict_types=1);

namespace Nesto\Testing;

use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;

/**
 * Wraps a PSR-7 response with fluent assertions (supertest / Laravel TestResponse style).
 *
 *   $res = $module->request()->get('/cats')->send();
 *   $res->assertOk()
 *       ->assertJsonCount(2, 'data')
 *       ->assertJsonPath('data.0.name', 'Tom')
 *       ->assertJsonStructure(['data' => ['*' => ['id', 'name']]])
 *       ->assertHeader('Content-Type', 'application/json; charset=utf-8');
 *   $res->json('data.0.id');   // 1
 */
final class TestResponse
{
    private ?string $text = null;
    private mixed $decoded = null;
    private bool $decodedOnce = false;

    public function __construct(private readonly ResponseInterface $response) {}

    // ---- accessors ---------------------------------------------------------------

    public function psr(): ResponseInterface
    {
        return $this->response;
    }

    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    public function text(): string
    {
        if ($this->text === null) {
            $body = $this->response->getBody();
            if ($body->isSeekable()) {
                $body->rewind();
            }
            $this->text = (string) $body;
        }

        return $this->text;
    }

    /** Decoded JSON body, optionally a dot-path into it ("data.0.name"). */
    public function json(?string $path = null): mixed
    {
        if (! $this->decodedOnce) {
            $this->decodedOnce = true;
            $this->decoded = json_decode($this->text(), true);
        }

        return $path === null ? $this->decoded : data_get($this->decoded, $path);
    }

    public function header(string $name): ?string
    {
        return $this->response->hasHeader($name) ? $this->response->getHeaderLine($name) : null;
    }

    /** @return array<string, string> lower-cased names */
    public function headers(): array
    {
        $out = [];
        foreach ($this->response->getHeaders() as $name => $values) {
            $out[strtolower($name)] = implode(', ', $values);
        }

        return $out;
    }

    public function isJson(): bool
    {
        return str_contains($this->header('Content-Type') ?? '', 'json');
    }

    // ---- status assertions -------------------------------------------------------

    public function expect(int $status): self
    {
        return $this->assertStatus($status);
    }

    public function assertStatus(int $status): self
    {
        Assert::assertSame($status, $this->status(), sprintf(
            "Expected response status %d but received %d.\nBody: %s",
            $status, $this->status(), $this->snippet()
        ));

        return $this;
    }

    public function assertOk(): self
    {
        return $this->assertStatus(200);
    }

    public function assertCreated(): self
    {
        return $this->assertStatus(201);
    }

    public function assertNoContent(): self
    {
        return $this->assertStatus(204);
    }

    public function assertBadRequest(): self
    {
        return $this->assertStatus(400);
    }

    public function assertUnauthorized(): self
    {
        return $this->assertStatus(401);
    }

    public function assertUnprocessable(): self
    {
        return $this->assertStatus(422);
    }

    public function assertForbidden(): self
    {
        return $this->assertStatus(403);
    }

    public function assertNotFound(): self
    {
        return $this->assertStatus(404);
    }

    public function assertSuccessful(): self
    {
        Assert::assertTrue($this->status() >= 200 && $this->status() < 300, sprintf('Expected a 2xx status, got %d. Body: %s', $this->status(), $this->snippet()));

        return $this;
    }

    public function assertRedirect(?string $location = null): self
    {
        Assert::assertTrue($this->status() >= 300 && $this->status() < 400, sprintf('Expected a 3xx status, got %d.', $this->status()));
        if ($location !== null) {
            Assert::assertSame($location, $this->header('Location'), 'Unexpected redirect Location header.');
        }

        return $this;
    }

    // ---- body assertions -------------------------------------------------------------

    /** Every key/value in $subset must be present (recursively) in the JSON body. */
    public function assertJson(array $subset): self
    {
        $actual = $this->json();
        Assert::assertIsArray($actual, 'Response body is not valid JSON: ' . $this->snippet());
        $this->assertSubset($subset, $actual, '');

        return $this;
    }

    public function assertExactJson(array $expected): self
    {
        Assert::assertSame($this->normalize($expected), $this->normalize($this->json()), 'JSON body does not match exactly. Body: ' . $this->snippet());

        return $this;
    }

    public function assertJsonPath(string $path, mixed $expected): self
    {
        $actual = $this->json($path);
        if ($expected instanceof \Closure) {
            Assert::assertTrue((bool) $expected($actual), "Closure assertion failed for JSON path [$path].");
        } else {
            Assert::assertSame($expected, $actual, sprintf('Unexpected value at JSON path [%s]. Body: %s', $path, $this->snippet()));
        }

        return $this;
    }

    public function assertJsonMissingPath(string $path): self
    {
        Assert::assertNull($this->json($path), "JSON path [$path] should be missing but is present.");

        return $this;
    }

    public function assertJsonCount(int $count, ?string $path = null): self
    {
        $value = $this->json($path);
        Assert::assertIsArray($value, sprintf('JSON%s is not an array. Body: %s', $path ? " path [$path]" : ' body', $this->snippet()));
        Assert::assertCount($count, $value, sprintf('Expected %d item(s) at JSON%s, found %d.', $count, $path ? " path [$path]" : ' body', count($value)));

        return $this;
    }

    /** Laravel-style structure check: ['data' => ['*' => ['id', 'name']], 'meta'] */
    public function assertJsonStructure(array $structure, mixed $data = null): self
    {
        $data ??= $this->json();
        Assert::assertIsArray($data, 'Response body is not valid JSON: ' . $this->snippet());
        foreach ($structure as $key => $value) {
            if (is_array($value)) {
                if ($key === '*') {
                    Assert::assertIsArray($data, 'Expected a list of items for wildcard "*".');
                    foreach ($data as $item) {
                        $this->assertJsonStructure($value, $item);
                    }
                } else {
                    Assert::assertArrayHasKey($key, $data, "Missing key [$key] in JSON structure. Body: " . $this->snippet());
                    $this->assertJsonStructure($value, $data[$key]);
                }
            } else {
                Assert::assertArrayHasKey($value, $data, "Missing key [$value] in JSON structure. Body: " . $this->snippet());
            }
        }

        return $this;
    }

    public function assertJsonFragment(array $fragment): self
    {
        $haystack = json_encode($this->json(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach ($fragment as $key => $value) {
            $needle = substr((string) json_encode([$key => $value], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1);
            Assert::assertStringContainsString($needle, (string) $haystack, "Fragment $needle not found in JSON: " . $this->snippet());
        }

        return $this;
    }

    public function assertSee(string $text): self
    {
        Assert::assertStringContainsString($text, $this->text(), "Text \"$text\" not found in response body: " . $this->snippet());

        return $this;
    }

    public function assertDontSee(string $text): self
    {
        Assert::assertStringNotContainsString($text, $this->text(), "Text \"$text\" unexpectedly found in response body.");

        return $this;
    }

    public function assertHeader(string $name, ?string $value = null): self
    {
        Assert::assertTrue($this->response->hasHeader($name), "Header [$name] is missing.");
        if ($value !== null) {
            Assert::assertSame($value, $this->header($name), "Header [$name] has an unexpected value.");
        }

        return $this;
    }

    public function assertHeaderMissing(string $name): self
    {
        Assert::assertFalse($this->response->hasHeader($name), "Header [$name] should not be present.");

        return $this;
    }

    /**
     * The response is a validation failure (400, or 422 with `ValidationPipe(errorHttpStatusCode: 422)`) with errors
     * for these fields; a string value must be part of one of the field's messages:
     *
     *   ->assertJsonValidationErrors('email')
     *   ->assertJsonValidationErrors(['name', 'email' => 'already registered'], 422)
     *
     * @param string|array<int|string, string|list<string>> $errors
     * @param int|null $status exact status; null accepts 400 and 422
     */
    public function assertJsonValidationErrors(string|array $errors, ?int $status = null): self
    {
        $actual = $this->validationErrors($status);
        foreach ((array) $errors as $key => $value) {
            $field = is_int($key) ? (string) $value : $key;
            Assert::assertArrayHasKey($field, $actual, "No validation errors for [$field]. Body: " . $this->snippet());
            if (is_int($key)) {
                continue;
            }
            $messages = implode("\n", array_map(fn ($m) => is_string($m) ? $m : (string) json_encode($m), (array) $actual[$field]));
            foreach ((array) $value as $needle) {
                Assert::assertStringContainsString((string) $needle, $messages, "No validation message for [$field] contains \"$needle\". Body: " . $this->snippet());
            }
        }

        return $this;
    }

    /**
     * None of these fields has a validation error (no fields: the response has no validation errors at all).
     *
     * @param string|list<string> $fields
     */
    public function assertJsonMissingValidationErrors(string|array $fields = []): self
    {
        $actual = $this->json('errors');
        $actual = is_array($actual) ? $actual : [];
        if ((array) $fields === []) {
            Assert::assertSame([], $actual, 'Unexpected validation errors. Body: ' . $this->snippet());
        }
        foreach ((array) $fields as $field) {
            Assert::assertArrayNotHasKey($field, $actual, "Unexpected validation errors for [$field]. Body: " . $this->snippet());
        }

        return $this;
    }

    /** @return array<string, mixed> the `errors` object of a validation response */
    private function validationErrors(?int $status): array
    {
        if ($status !== null) {
            $this->assertStatus($status);
        } else {
            Assert::assertContains($this->status(), [400, 422], sprintf('Expected a validation failure (400 or 422) but received %d. Body: %s', $this->status(), $this->snippet()));
        }
        $errors = $this->json('errors');
        Assert::assertIsArray($errors, 'Expected a validation response with an "errors" object (field => messages). Body: ' . $this->snippet());

        return $errors;
    }

    // ---- debugging -----------------------------------------------------------------------

    /**
     * Print status, headers and body (pretty JSON when possible) to STDERR (or $stream) and continue.
     *
     * @param resource|null $stream
     */
    public function dump($stream = null): self
    {
        $body = $this->isJson() && $this->json() !== null
            ? json_encode($this->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : $this->text();
        $headers = '';
        foreach ($this->headers() as $k => $v) {
            $headers .= "  $k: $v\n";
        }
        fwrite($stream ?? (defined('STDERR') ? STDERR : fopen('php://stderr', 'w')), sprintf("HTTP %d\n%s\n%s\n", $this->status(), $headers, $body));

        return $this;
    }

    public function dd(): never
    {
        $this->dump();
        exit(1);
    }

    // ---- internals -------------------------------------------------------------------------

    private function assertSubset(array $subset, array $actual, string $prefix): void
    {
        foreach ($subset as $key => $value) {
            $path = $prefix === '' ? (string) $key : "$prefix.$key";
            Assert::assertArrayHasKey($key, $actual, "Missing key [$path] in JSON. Body: " . $this->snippet());
            if (is_array($value) && is_array($actual[$key])) {
                $this->assertSubset($value, $actual[$key], $path);
            } else {
                Assert::assertSame($value, $actual[$key], "Unexpected value at [$path]. Body: " . $this->snippet());
            }
        }
    }

    private function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map($this->normalize(...), $value);
            }
            ksort($value);

            return array_map($this->normalize(...), $value);
        }

        return $value;
    }

    private function snippet(): string
    {
        $text = $this->text();

        return strlen($text) > 500 ? substr($text, 0, 500) . '…' : $text;
    }
}
