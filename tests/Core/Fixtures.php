<?php

declare(strict_types=1);

namespace Nesto\Tests\Core;

use Nesto\Common\Attributes\Body;
use Nesto\Common\Attributes\Catches;
use Nesto\Common\Attributes\Controller;
use Nesto\Common\Attributes\Delete;
use Nesto\Common\Attributes\Get;
use Nesto\Common\Attributes\GlobalModule;
use Nesto\Common\Attributes\Header;
use Nesto\Common\Attributes\Headers;
use Nesto\Common\Attributes\HttpCode;
use Nesto\Common\Attributes\Inject;
use Nesto\Common\Attributes\Injectable;
use Nesto\Common\Attributes\Module;
use Nesto\Common\Attributes\Param;
use Nesto\Common\Attributes\Post;
use Nesto\Common\Attributes\Put;
use Nesto\Common\Attributes\Query;
use Nesto\Common\Attributes\Redirect;
use Nesto\Common\Attributes\Req;
use Nesto\Common\Attributes\Res;
use Nesto\Common\Attributes\SetMetadata;
use Nesto\Common\Attributes\UseFilters;
use Nesto\Common\Attributes\UseGuards;
use Nesto\Common\Attributes\UseInterceptors;
use Nesto\Common\Attributes\UsePipes;
use Nesto\Common\Attributes\Version;
use Nesto\Common\ArgumentMetadata;
use Nesto\Common\Contracts\CallHandler;
use Nesto\Common\Contracts\DynamicModuleFactory;
use Nesto\Common\Contracts\ExceptionFilter;
use Nesto\Common\Contracts\Guard;
use Nesto\Common\Contracts\Interceptor;
use Nesto\Common\Contracts\Middleware;
use Nesto\Common\Contracts\NestoModule;
use Nesto\Common\Contracts\OnApplicationBootstrap;
use Nesto\Common\Contracts\OnModuleDestroy;
use Nesto\Common\Contracts\OnModuleInit;
use Nesto\Common\Contracts\ParamResolver;
use Nesto\Common\Contracts\Pipe;
use Nesto\Common\DynamicModule;
use Nesto\Common\Exceptions\HttpException;
use Nesto\Common\Exceptions\NotFoundException;
use Nesto\Common\Pipes\ParseIntPipe;
use Nesto\Common\Provider;
use Nesto\Common\Scope;
use Nesto\Core\ArgumentsHost;
use Nesto\Core\Constants;
use Nesto\Core\ExecutionContext;
use Nesto\Core\MiddlewareConsumer;
use Nesto\Core\Reflector;
use Nesto\Http\Request;
use Nesto\Http\Response;
use Psr\Http\Message\ResponseInterface;

// ---------------------------------------------------------------- providers

#[Injectable]
final class CatsService implements OnModuleInit, OnApplicationBootstrap, OnModuleDestroy
{
    public static array $hooks = [];
    public array $cats = [];

    public function __construct(#[Inject('APP_NAME')] public readonly string $appName) {}

    public function onModuleInit(): void
    {
        self::$hooks[] = 'init';
        $this->cats = [['id' => 1, 'name' => 'Tom']];
    }

    public function onApplicationBootstrap(): void
    {
        self::$hooks[] = 'bootstrap';
    }

    public function onModuleDestroy(): void
    {
        self::$hooks[] = 'destroy';
    }
}

#[Injectable(scope: Scope::REQUEST)]
final class RequestCounter
{
    public static int $created = 0;
    public int $id;

    public function __construct(public readonly Request $request)
    {
        $this->id = ++self::$created;
    }
}

#[Injectable(scope: Scope::TRANSIENT)]
final class TransientThing
{
    public static int $created = 0;

    public function __construct()
    {
        self::$created++;
    }
}

#[Injectable]
final class UsesRequestScoped
{
    public function __construct(public readonly RequestCounter $counter) {}
}

final class PdoFactory
{
    public static function make(string $name): \ArrayObject
    {
        return new \ArrayObject(['dsn' => "sqlite:$name"]);
    }
}

#[Injectable]
final class InvokableFactory
{
    public function __construct(private readonly CatsService $cats) {}

    public function __invoke(): \ArrayObject
    {
        return new \ArrayObject(['from' => $this->cats->appName]);
    }
}

// ---------------------------------------------------------------- enhancers

final class RolesGuard implements Guard
{
    public function __construct(private readonly Reflector $reflector) {}

    public function canActivate(ExecutionContext $ctx): bool
    {
        $roles = $this->reflector->metadata('roles', $ctx->getHandler(), $ctx->getClass());
        if (! $roles) {
            return true;
        }

        return in_array($ctx->switchToHttp()->getRequest()->header('X-Role'), $roles, true);
    }
}

final class WrapInterceptor implements Interceptor
{
    public static array $log = [];

    public function intercept(ExecutionContext $ctx, CallHandler $next): mixed
    {
        self::$log[] = 'before:' . $ctx->getHandlerName();
        $result = $next->handle();
        self::$log[] = 'after:' . $ctx->getHandlerName();

        return ['data' => $result, 'wrapped' => true];
    }
}

final class UpperPipe implements Pipe
{
    public function transform(mixed $value, ArgumentMetadata $metadata): mixed
    {
        return is_string($value) ? strtoupper($value) : $value;
    }
}

#[Catches(NotFoundException::class)]
final class NotFoundFilter implements ExceptionFilter
{
    public function catch(\Throwable $exception, ArgumentsHost $host): ResponseInterface
    {
        return Response::json(['custom' => true, 'path' => $host->switchToHttp()->getRequest()->path()], 404);
    }
}

final class LoggerMiddleware implements Middleware
{
    public static array $seen = [];

    public function use(Request $request, \Closure $next): ResponseInterface
    {
        self::$seen[] = $request->path();
        $request->setAttribute('mw', 'yes');

        return $next($request)->withHeader('X-Middleware', 'logger');
    }
}

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class CurrentUser implements ParamResolver
{
    public function resolve(ExecutionContext $context): mixed
    {
        return $context->switchToHttp()->getRequest()->header('X-User', 'anonymous');
    }
}

final class CreateCatDto
{
    public string $name;
    public ?int $age = null;
}

enum Breed: string
{
    case SIAMESE = 'siamese';
    case PERSIAN = 'persian';
}

// ---------------------------------------------------------------- controllers

#[Controller('cats')]
#[UseGuards(RolesGuard::class)]
final class CatsController
{
    public function __construct(private readonly CatsService $cats, private readonly UsesRequestScoped $scoped) {}

    #[Get]
    #[UseInterceptors(WrapInterceptor::class)]
    public function index(#[Query('limit')] int $limit = 10, #[Query('q')] ?string $q = null): array
    {
        return ['limit' => $limit, 'q' => $q, 'app' => $this->cats->appName, 'cats' => $this->cats->cats];
    }

    #[Get('scoped')]
    public function scoped(RequestCounter $direct): array
    {
        return ['viaService' => $this->scoped->counter->id, 'direct' => $direct->id, 'same' => $this->scoped->counter === $direct];
    }

    #[Get('breed/{breed}')]
    public function breed(#[Param('breed')] Breed $breed): array
    {
        return ['breed' => $breed->value];
    }

    #[Get('me')]
    public function me(#[CurrentUser] string $user, #[Req] Request $request, #[Headers('x-user')] ?string $header): array
    {
        return ['user' => $user, 'mw' => $request->attribute('mw'), 'header' => $header];
    }

    #[Get('res')]
    public function res(#[Res] Response $res): array
    {
        $res->status(218)->header('X-Custom', 'yes');

        return ['ok' => true];
    }

    #[Get('text')]
    #[Header('X-Kind', 'text')]
    public function text(): string
    {
        return 'plain';
    }

    #[Get('nothing')]
    #[HttpCode(204)]
    public function nothing(): void {}

    #[Get('go')]
    #[Redirect('https://example.com', 301)]
    public function go(): void {}

    #[Get('boom')]
    #[UseFilters(NotFoundFilter::class)]
    public function boom(): never
    {
        throw new NotFoundException('nope');
    }

    #[Get('crash')]
    public function crash(): never
    {
        throw new \RuntimeException('secret');
    }

    #[Get('custom-error')]
    public function customError(): never
    {
        throw new HttpException(['statusCode' => 418, 'message' => 'teapot', 'error' => 'Custom'], 418);
    }

    #[Get('{id}')]
    public function show(#[Param('id', ParseIntPipe::class)] int $id): array
    {
        return ['id' => $id];
    }

    #[Post]
    #[SetMetadata('roles', ['admin'])]
    public function store(#[Body] CreateCatDto $dto): CreateCatDto
    {
        return $dto;
    }

    #[Put('{id}')]
    #[UsePipes(UpperPipe::class)]
    public function update(#[Param('id')] int $id, #[Body('name')] string $name): array
    {
        return ['id' => $id, 'name' => $name];
    }

    #[Delete('{id}')]
    public function destroy(#[Param('id')] int $id): array
    {
        return ['deleted' => $id];
    }
}

#[Controller('v')]
#[Version('1')]
final class VersionedController
{
    #[Get('ping')]
    public function v1(): array
    {
        return ['v' => 1];
    }

    #[Get('ping')]
    #[Version('2')]
    public function v2(): array
    {
        return ['v' => 2];
    }
}

// ---------------------------------------------------------------- modules

#[GlobalModule]
#[Module(providers: [new Provider('APP_NAME', useValue: 'Nesto')], exports: ['APP_NAME'])]
final class ConfigModule {}

#[Module(
    controllers: [CatsController::class],
    providers: [
        CatsService::class,
        RequestCounter::class,
        UsesRequestScoped::class,
        TransientThing::class,
        new Provider('DB', useFactory: [PdoFactory::class, 'make'], inject: ['APP_NAME']),
        new Provider('INVOKED', useFactory: InvokableFactory::class),
        new Provider('ALIAS', useExisting: CatsService::class),
    ],
    exports: [CatsService::class],
)]
final class CatsModule implements NestoModule
{
    public function configure(MiddlewareConsumer $consumer): void
    {
        $consumer->apply(LoggerMiddleware::class)->exclude('cats/text')->forRoutes(CatsController::class);
    }
}

#[Module(controllers: [VersionedController::class])]
final class VersionedModule {}

final class OptionsModule implements DynamicModuleFactory
{
    public function __construct(public readonly string $greeting = 'hi') {}

    public function toDynamicModule(): DynamicModule
    {
        return new DynamicModule(self::class, providers: [new Provider('GREETING', useValue: $this->greeting)], exports: ['GREETING'], global: true);
    }

    public static function forRoot(string $greeting): DynamicModule
    {
        return (new self($greeting))->toDynamicModule();
    }
}

#[Injectable]
final class Greeter
{
    public function __construct(#[Inject('GREETING')] public readonly string $greeting) {}
}

#[Module(imports: [ConfigModule::class, CatsModule::class, new OptionsModule('hello'), VersionedModule::class], providers: [Greeter::class])]
final class AppModule {}

#[Module(imports: [CatsModule::class], providers: [Greeter::class])]
final class BrokenAppModule {}
