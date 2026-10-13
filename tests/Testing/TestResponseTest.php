<?php

declare(strict_types=1);

namespace Nesto\Tests\Testing;

use Nesto\Common\Exceptions\ValidationException;
use Nesto\Http\Response;
use Nesto\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

final class TestResponseTest extends TestCase
{
    private function json(array $data, int $status = 200, array $headers = []): TestResponse
    {
        return new TestResponse(Response::json($data, $status, $headers));
    }

    public function testAccessors(): void
    {
        $res = $this->json(['data' => [['id' => 1, 'name' => 'Tom'], ['id' => 2, 'name' => 'Felix']], 'meta' => ['total' => 2]], 201, ['X-Id' => 'abc']);

        self::assertSame(201, $res->status());
        self::assertSame('Tom', $res->json('data.0.name'));
        self::assertSame(2, $res->json('meta.total'));
        self::assertIsArray($res->json());
        self::assertSame('abc', $res->header('X-Id'));
        self::assertNull($res->header('Nope'));
        self::assertArrayHasKey('x-id', $res->headers());
        self::assertStringContainsString('"Tom"', $res->text());
        self::assertTrue($res->isJson());
    }

    public function testStatusAssertionsPassAndFail(): void
    {
        $this->json([], 200)->assertOk()->expect(200)->assertStatus(200)->assertSuccessful();
        $this->json([], 201)->assertCreated();
        (new TestResponse(Response::noContent()))->assertNoContent();
        $this->json([], 400)->assertBadRequest();
        $this->json([], 401)->assertUnauthorized();
        $this->json([], 403)->assertForbidden();
        $this->json([], 404)->assertNotFound();
        (new TestResponse(Response::redirect('/x')))->assertRedirect('/x');

        $this->expectException(AssertionFailedError::class);
        $this->json([], 500)->assertOk();
    }

    public function testJsonAssertions(): void
    {
        $res = $this->json([
            'data' => [['id' => 1, 'name' => 'Tom', 'tags' => ['a']], ['id' => 2, 'name' => 'Felix', 'tags' => []]],
            'meta' => ['total' => 2, 'page' => 1],
        ]);

        $res->assertJson(['meta' => ['total' => 2]])
            ->assertJson(['data' => [1 => ['name' => 'Felix']]])
            ->assertJsonPath('data.0.name', 'Tom')
            ->assertJsonPath('meta.total', fn ($v) => $v === 2)
            ->assertJsonCount(2, 'data')
            ->assertJsonCount(2)
            ->assertJsonStructure(['data' => ['*' => ['id', 'name', 'tags']], 'meta' => ['total', 'page']])
            ->assertJsonFragment(['name' => 'Felix'])
            ->assertJsonMissingPath('meta.nope')
            ->assertSee('Felix')
            ->assertDontSee('Garfield')
            ->assertHeader('Content-Type', 'application/json; charset=utf-8')
            ->assertHeaderMissing('X-Nope');

        $this->json(['b' => 1, 'a' => [2, 1]])->assertExactJson(['a' => [2, 1], 'b' => 1]);
    }

    public function testJsonSubsetFailsOnMissingKey(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->json(['a' => 1])->assertJson(['b' => 1]);
    }

    public function testJsonSubsetFailsOnWrongNestedValue(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->json(['a' => ['b' => 1]])->assertJson(['a' => ['b' => 2]]);
    }

    public function testJsonStructureFailsOnMissingWildcardKey(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->json(['data' => [['id' => 1], ['name' => 'x']]])->assertJsonStructure(['data' => ['*' => ['id']]]);
    }

    public function testJsonCountFails(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->json(['data' => [1, 2, 3]])->assertJsonCount(2, 'data');
    }

    private function validation(array $errors, int $status = 400): TestResponse
    {
        return new TestResponse(Response::json((new ValidationException($errors, $status))->getResponse(), $status));
    }

    public function testValidationErrorsHelperReadsTheErrorsObject(): void
    {
        $res = $this->validation(['name' => ['name must be a string'], 'email' => ['email must be an email', 'email is already registered']]);

        $res->assertJsonValidationErrors('name')
            ->assertJsonValidationErrors(['name', 'email'])
            ->assertJsonValidationErrors(['email' => 'already registered', 'name'])
            ->assertJsonValidationErrors(['email' => ['must be an email', 'already registered']])
            ->assertJsonValidationErrors('email', 400)
            ->assertJsonMissingValidationErrors('password')
            ->assertJsonMissingValidationErrors(['password', 'age']);

        // ValidationPipe(errorHttpStatusCode: 422)
        $this->validation(['name' => 'name must be a string'], 422)->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function testValidationErrorsHelperFailsOnMissingField(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->validation(['name' => 'name must be a string'])->assertJsonValidationErrors(['name', 'email']);
    }

    public function testValidationErrorsHelperFailsOnMissingMessage(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->validation(['name' => 'name must be a string'])->assertJsonValidationErrors(['name' => 'too short']);
    }

    public function testValidationErrorsHelperFailsOnAnotherStatus(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->json(['statusCode' => 401, 'message' => 'Unauthorized', 'errors' => ['name' => ['x']]], 401)->assertJsonValidationErrors('name');
    }

    public function testValidationErrorsHelperFailsOnAnExplicitStatusMismatch(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->validation(['name' => 'name must be a string'], 422)->assertJsonValidationErrors('name', 400);
    }

    public function testMissingValidationErrorsFailsWhenTheFieldHasErrors(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->validation(['name' => 'name must be a string'])->assertJsonMissingValidationErrors('name');
    }

    public function testDumpWritesToStderrAndChains(): void
    {
        $res = $this->json(['ok' => true]);
        $stream = fopen('php://memory', 'w+');
        self::assertSame($res, $res->dump($stream));
        rewind($stream);
        $written = stream_get_contents($stream);
        self::assertStringContainsString('HTTP 200', $written);
        self::assertStringContainsString('"ok": true', $written);
    }
}
