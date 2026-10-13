<?php

declare(strict_types=1);

namespace Nesto\Tests\Transformer;

use Nesto\Common\Attributes\Controller;
use Nesto\Common\Attributes\Get;
use Nesto\Common\Attributes\Module;
use Nesto\Common\Attributes\UseInterceptors;
use Nesto\Core\NestoFactory;
use Nesto\Http\Request;
use Nesto\Transformer\Attributes\Exclude;
use Nesto\Transformer\Attributes\Expose;
use Nesto\Transformer\ClassSerializerInterceptor;
use Nesto\Transformer\SerializeOptions;
use PHPUnit\Framework\TestCase;

final class UserEntity
{
    public int $id = 1;
    public string $email = 'a@b.c';
    #[Exclude]
    public string $password = 'secret';
    #[Expose(groups: ['admin'])]
    public string $role = 'owner';
}

#[Controller('users')]
#[UseInterceptors(ClassSerializerInterceptor::class)]
final class UsersController
{
    #[Get]
    public function index(): array
    {
        return ['data' => [new UserEntity, new UserEntity], 'total' => 2];
    }

    #[Get('one')]
    public function one(): UserEntity
    {
        return new UserEntity;
    }

    #[Get('admin')]
    #[SerializeOptions(groups: ['admin'])]
    public function admin(): UserEntity
    {
        return new UserEntity;
    }

    #[Get('text')]
    public function text(): string
    {
        return 'plain';
    }
}

#[Controller('raw')]
final class RawUsersController
{
    #[Get]
    public function one(): UserEntity
    {
        return new UserEntity;
    }
}

#[Module(controllers: [UsersController::class, RawUsersController::class], providers: [ClassSerializerInterceptor::class])]
final class SerializerAppModule {}

final class ClassSerializerInterceptorTest extends TestCase
{
    private function json(string $path): array
    {
        $app = NestoFactory::create(SerializerAppModule::class);
        $response = $app->handle(Request::create('GET', $path));
        self::assertSame(200, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true);
    }

    public function testSerializesEntitiesInsidePaginatedArrays(): void
    {
        $body = $this->json('/users');
        self::assertSame(2, $body['total']);
        self::assertSame(['id' => 1, 'email' => 'a@b.c'], $body['data'][0]);
        self::assertArrayNotHasKey('password', $body['data'][1]);
    }

    public function testSerializesSingleEntity(): void
    {
        self::assertSame(['id' => 1, 'email' => 'a@b.c'], $this->json('/users/one'));
    }

    public function testSerializeOptionsAttributeAddsGroups(): void
    {
        self::assertSame(['id' => 1, 'email' => 'a@b.c', 'role' => 'owner'], $this->json('/users/admin'));
    }

    public function testScalarsPassThrough(): void
    {
        $app = NestoFactory::create(SerializerAppModule::class);
        $response = $app->handle(Request::create('GET', '/users/text'));
        self::assertSame('plain', (string) $response->getBody());
    }

    public function testConstructorOptionsApplyGlobally(): void
    {
        $app = NestoFactory::create(SerializerAppModule::class);
        $app->useGlobalInterceptors(new ClassSerializerInterceptor(['groups' => ['admin']]));
        $body = json_decode((string) $app->handle(Request::create('GET', '/raw'))->getBody(), true);
        self::assertSame(['id' => 1, 'email' => 'a@b.c', 'role' => 'owner'], $body);
    }

    public function testWithoutInterceptorCoreStillSerializesViaClassTransformer(): void
    {
        $app = NestoFactory::create(SerializerAppModule::class);
        $body = json_decode((string) $app->handle(Request::create('GET', '/raw'))->getBody(), true);
        self::assertSame(['id' => 1, 'email' => 'a@b.c'], $body, 'RouteHandler::toPlain() delegates to ClassTransformer');
    }
}
