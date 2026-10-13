<?php

declare(strict_types=1);

namespace Nesto\Tests\Validation\UseContainer;

use Nesto\Common\Attributes\Body;
use Nesto\Common\Attributes\Controller;
use Nesto\Common\Attributes\Injectable;
use Nesto\Common\Attributes\Module;
use Nesto\Common\Attributes\Post;
use Nesto\Validation\Attributes\IsEmail;
use Nesto\Validation\Attributes\Validate;
use Nesto\Validation\Contracts\ConstraintValidator;

#[Injectable]
final class EmailRegistry
{
    /** @var list<string> */
    public array $taken = ['taken@example.com'];
}

/** Needs DI: only works through Validator::useContainer(). */
#[Injectable]
final class IsUniqueEmail implements ConstraintValidator
{
    public function __construct(private readonly EmailRegistry $registry) {}

    public function validate(mixed $value, object $object, string $property): ?string
    {
        return in_array($value, $this->registry->taken, true) ? "$property is already taken" : null;
    }
}

final class SignUpDto
{
    #[IsEmail, Validate(IsUniqueEmail::class)]
    public string $email;
}

#[Controller('sign-up')]
final class SignUpController
{
    #[Post]
    public function create(#[Body] SignUpDto $dto): array
    {
        return ['email' => $dto->email];
    }
}

#[Module(controllers: [SignUpController::class], providers: [EmailRegistry::class, IsUniqueEmail::class])]
final class SignUpModule {}

namespace Nesto\Tests\Validation;

use Nesto\Core\NestoFactory;
use Nesto\Http\Request;
use Nesto\Tests\Validation\UseContainer\SignUpDto;
use Nesto\Tests\Validation\UseContainer\SignUpModule;
use Nesto\Validation\ValidationPipe;
use Nesto\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class UseContainerTest extends TestCase
{
    protected function tearDown(): void
    {
        Validator::useContainer(null);
    }

    public function test_constraint_validators_are_resolved_from_the_container(): void
    {
        $app = NestoFactory::create(SignUpModule::class)->useGlobalPipes(new ValidationPipe(whitelist: true));
        Validator::useContainer($app, ['fallbackOnErrors' => true]);

        $taken = $app->handle(Request::create('POST', '/sign-up', body: ['email' => 'taken@example.com']));
        self::assertSame(400, $taken->getStatusCode());
        self::assertSame(['email' => ['email is already taken']], json_decode((string) $taken->getBody(), true)['errors']);

        $ok = $app->handle(Request::create('POST', '/sign-up', body: ['email' => 'new@example.com']));
        self::assertSame(201, $ok->getStatusCode());
    }

    public function test_without_fallback_container_errors_surface(): void
    {
        $dto = new SignUpDto;
        $dto->email = 'x@example.com';
        Validator::useContainer(new class {
            public function get(string $id): mixed
            {
                throw new \RuntimeException("cannot build $id");
            }
        }, ['fallbackOnErrors' => false]);

        $this->expectExceptionMessage('cannot build');
        (new Validator)->validate($dto);
    }
}
