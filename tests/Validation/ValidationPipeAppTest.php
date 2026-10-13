<?php

declare(strict_types=1);

namespace Nesto\Tests\Validation;

use Nesto\Common\Attributes\Body;
use Nesto\Common\Attributes\Controller;
use Nesto\Common\Attributes\Get;
use Nesto\Common\Attributes\Module;
use Nesto\Common\Attributes\Param;
use Nesto\Common\Attributes\Post;
use Nesto\Common\Attributes\Query;
use Nesto\Core\NestoApplication;
use Nesto\Core\NestoFactory;
use Nesto\Http\Request;
use Nesto\Validation\Attributes\IsBoolean;
use Nesto\Validation\Attributes\IsInt;
use Nesto\Validation\Attributes\IsOptional;
use Nesto\Validation\Attributes\IsString;
use Nesto\Validation\Attributes\Max;
use Nesto\Validation\Attributes\Min;
use Nesto\Validation\Attributes\MinLength;
use Nesto\Validation\ValidationPipe;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class CreateCatDto
{
    #[IsString]
    #[MinLength(2)]
    public string $name;

    #[IsInt]
    #[Min(0)]
    #[Max(30)]
    public int $age;

    #[IsOptional]
    #[IsBoolean]
    public bool $indoor = false;
}

final class ListCatsQuery
{
    #[IsOptional]
    #[IsInt]
    #[Min(1)]
    public int $page = 1;
}

#[Controller('cats')]
final class CatsController
{
    #[Post]
    public function store(#[Body] CreateCatDto $dto): array
    {
        return ['dto' => $dto::class, 'name' => $dto->name, 'age' => $dto->age, 'indoor' => $dto->indoor];
    }

    #[Get]
    public function index(#[Query] ListCatsQuery $query): array
    {
        return ['page' => $query->page];
    }

    #[Get(':id')]
    public function show(#[Param('id')] int $id): array
    {
        return ['id' => $id];
    }
}

#[Module(controllers: [CatsController::class])]
final class CatsAppModule {}

final class ValidationPipeAppTest extends TestCase
{
    private NestoApplication $app;

    protected function setUp(): void
    {
        $this->app = NestoFactory::create(CatsAppModule::class);
        $this->app->useGlobalPipes(new ValidationPipe(whitelist: true, transform: true));
    }

    private function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true);
    }

    public function testValidBodyIsHydratedAndReturns201(): void
    {
        $response = $this->app->handle(Request::create('POST', '/cats', body: ['name' => 'Tom', 'age' => 3, 'indoor' => true, 'extra' => 'stripped']));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['dto' => CreateCatDto::class, 'name' => 'Tom', 'age' => 3, 'indoor' => true], $this->json($response));
    }

    public function testInvalidBodyReturns400WithErrorsPerField(): void
    {
        $response = $this->app->handle(Request::create('POST', '/cats', body: ['name' => 'T', 'age' => 'old']));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([
            'statusCode' => 400,
            'message' => 'name must be longer than or equal to 2 characters (and 3 more errors)',
            'errors' => [
                'name' => ['name must be longer than or equal to 2 characters'],
                'age' => ['age must be an integer number', 'age must not be less than 0', 'age must not be greater than 30'],
            ],
            'error' => 'Bad Request',
        ], $this->json($response));
    }

    public function testEmptyBodyListsEveryMissingProperty(): void
    {
        $response = $this->app->handle(Request::create('POST', '/cats', body: []));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([
            'name' => ['name must be a string', 'name must be longer than or equal to 2 characters'],
            'age' => ['age must be an integer number', 'age must not be less than 0', 'age must not be greater than 30'],
        ], $this->json($response)['errors']);
    }

    public function testForbidNonWhitelisted(): void
    {
        $app = NestoFactory::create(CatsAppModule::class);
        $app->useGlobalPipes(new ValidationPipe(whitelist: true, forbidNonWhitelisted: true));
        $response = $app->handle(Request::create('POST', '/cats', body: ['name' => 'Tom', 'age' => 1, 'extra' => 1]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['extra' => ['property extra should not exist']], $this->json($response)['errors']);
    }

    public function testIntParamIsCastAndRejected(): void
    {
        self::assertSame(['id' => 42], $this->json($this->app->handle(Request::create('GET', '/cats/42'))));

        $response = $this->app->handle(Request::create('GET', '/cats/abc'));
        self::assertSame(400, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame(400, $body['statusCode']);
        self::assertSame('id must be an integer', $body['message']);
        self::assertSame(['id' => ['id must be an integer']], $body['errors']);
        self::assertSame('Bad Request', $body['error']);
    }

    public function testQueryDtoCastsStringsAndValidates(): void
    {
        self::assertSame(['page' => 3], $this->json($this->app->handle(Request::create('GET', '/cats', query: ['page' => '3']))));
        self::assertSame(['page' => 1], $this->json($this->app->handle(Request::create('GET', '/cats'))));

        $response = $this->app->handle(Request::create('GET', '/cats', query: ['page' => '0']));
        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['page' => ['page must not be less than 1']], $this->json($response)['errors']);
    }
}
