<?php

declare(strict_types=1);

namespace Nesto\Tests\Testing\Fixtures;

use Nesto\Common\Attributes\Body;
use Nesto\Common\Attributes\Controller;
use Nesto\Common\Attributes\Delete;
use Nesto\Common\Attributes\Get;
use Nesto\Common\Attributes\HttpCode;
use Nesto\Common\Attributes\Inject;
use Nesto\Common\Attributes\Injectable;
use Nesto\Common\Attributes\Module;
use Nesto\Common\Attributes\Param;
use Nesto\Common\Attributes\Post;
use Nesto\Common\Attributes\UseGuards;
use Nesto\Common\Contracts\Guard;
use Nesto\Common\Exceptions\NotFoundException;
use Nesto\Common\Provider;
use Nesto\Common\Scope;
use Nesto\Core\Constants;
use Nesto\Core\ExecutionContext;
use Nesto\Http\Response;

#[Injectable]
class CatsService
{
    /** @var list<array{id: int, name: string}> */
    private array $cats = [['id' => 1, 'name' => 'Tom'], ['id' => 2, 'name' => 'Felix']];

    public function findAll(): array
    {
        return $this->cats;
    }

    public function create(array $data): array
    {
        $cat = ['id' => count($this->cats) + 1, 'name' => $data['name'] ?? 'unnamed'];
        $this->cats[] = $cat;

        return $cat;
    }

    public function findOne(int $id): array
    {
        foreach ($this->cats as $cat) {
            if ($cat['id'] === $id) {
                return $cat;
            }
        }
        throw new NotFoundException("Cat #$id not found");
    }

    public function answer(): string
    {
        return 'real';
    }
}

final class FakeCatsService extends CatsService
{
    public function answer(): string
    {
        return 'fake';
    }

    public function findAll(): array
    {
        return [['id' => 99, 'name' => 'Fake']];
    }
}

#[Injectable]
final class DenyGuard implements Guard
{
    public function canActivate(ExecutionContext $context): bool
    {
        return false;
    }
}

final class AllowGuard implements Guard
{
    public function canActivate(ExecutionContext $context): bool
    {
        return true;
    }
}

#[Controller('cats')]
final class CatsController
{
    public function __construct(private readonly CatsService $cats, #[Inject('GREETING')] private readonly string $greeting = 'hi') {}

    #[Get]
    public function index(): array
    {
        return ['data' => $this->cats->findAll(), 'meta' => ['total' => count($this->cats->findAll())]];
    }

    #[Get('answer')]
    public function answer(): string
    {
        return $this->cats->answer();
    }

    #[Get('greeting')]
    public function greeting(): array
    {
        return ['greeting' => $this->greeting];
    }

    #[Get('secret')]
    #[UseGuards(DenyGuard::class)]
    public function secret(): array
    {
        return ['secret' => true];
    }

    #[Get('{id}')]
    public function show(#[Param('id')] int $id): array
    {
        return $this->cats->findOne($id);
    }

    #[Post]
    public function store(#[Body] array $body): array
    {
        return $this->cats->create($body);
    }

    #[Delete('{id}')]
    #[HttpCode(204)]
    public function destroy(#[Param('id')] int $id): void
    {
    }

    #[Get('custom')]
    public function custom(): \Psr\Http\Message\ResponseInterface
    {
        return Response::text('plain text here', 200, ['X-Custom' => 'yes']);
    }
}

#[Module(controllers: [CatsController::class], providers: [CatsService::class, new Provider('GREETING', useValue: 'hello')], exports: [CatsService::class])]
final class CatsModule
{
}

/** A module whose guard is registered through APP_GUARD (DI-enabled global guard). */
#[Module(controllers: [CatsController::class], providers: [CatsService::class, new Provider('GREETING', useValue: 'hello'), new Provider(Constants::APP_GUARD, useClass: DenyGuard::class)])]
final class GlobalGuardModule
{
}

/** A request-scoped provider, to prove overrides beat non-singleton definitions. */
#[Injectable(Scope::REQUEST)]
class RequestCounter
{
    public function value(): int
    {
        return 1;
    }
}

#[Controller('counter')]
final class CounterController
{
    public function __construct(private readonly RequestCounter $counter) {}

    #[Get]
    public function get(): array
    {
        return ['value' => $this->counter->value()];
    }
}

#[Module(controllers: [CounterController::class], providers: [new Provider(RequestCounter::class, useClass: RequestCounter::class, scope: Scope::REQUEST)])]
final class CounterModule
{
}
