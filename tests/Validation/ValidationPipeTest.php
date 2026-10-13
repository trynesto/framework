<?php

declare(strict_types=1);

namespace Nesto\Tests\Validation;

use Nesto\Common\ArgumentMetadata;
use Nesto\Common\Exceptions\UnprocessableEntityException;
use Nesto\Common\Exceptions\ValidationException;
use Nesto\Transformer\Attributes\Type;
use Nesto\Validation\Attributes\IsInt;
use Nesto\Validation\Attributes\IsOptional;
use Nesto\Validation\Attributes\IsString;
use Nesto\Validation\Attributes\Min;
use Nesto\Validation\Attributes\ValidateNested;
use Nesto\Validation\ValidationPipe;
use PHPUnit\Framework\TestCase;

final class LineDto
{
    #[IsString]
    public string $sku;
}

final class OrderDto
{
    #[IsString]
    public string $customer;

    #[IsOptional]
    #[IsInt]
    #[Min(1)]
    public ?int $qty = null;

    #[IsOptional]
    #[ValidateNested]
    public ?LineDto $line = null;

    #[ValidateNested(each: true)]
    #[Type(LineDto::class)]
    public array $lines = [];
}

final class ValidationPipeTest extends TestCase
{
    private static function body(?string $metatype = OrderDto::class): ArgumentMetadata
    {
        return new ArgumentMetadata('body', $metatype);
    }

    public function testOptionsFromArrayAndNamedArguments(): void
    {
        $a = new ValidationPipe(['whitelist' => true, 'transform' => false, 'groups' => ['x']]);
        self::assertTrue($a->whitelist);
        self::assertFalse($a->transform);
        self::assertSame(['x'], $a->groups);

        $b = new ValidationPipe(['whitelist' => true], whitelist: false, forbidNonWhitelisted: true);
        self::assertFalse($b->whitelist, 'named argument wins over the array');
        self::assertTrue($b->forbidNonWhitelisted);
        self::assertTrue($b->transform, 'transform defaults to true');
    }

    public function testHydratesAndReturnsDto(): void
    {
        $dto = (new ValidationPipe)->transform(['customer' => 'Ann', 'qty' => '2'], self::body());
        self::assertInstanceOf(OrderDto::class, $dto);
        self::assertSame(2, $dto->qty);
    }

    public function testThrowsValidationExceptionWithErrorsPerField(): void
    {
        try {
            (new ValidationPipe)->transform(['qty' => 0, 'lines' => [['sku' => 1.5]]], self::body());
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(400, $e->getStatus());
            self::assertSame([
                'customer must be a string',
                'qty must not be less than 1',
                'lines.0.sku must be a string',
            ], $e->messages);
            self::assertSame([
                'statusCode' => 400,
                'message' => 'customer must be a string (and 2 more errors)',
                'errors' => [
                    'customer' => ['customer must be a string'],
                    'qty' => ['qty must not be less than 1'],
                    'lines.0.sku' => ['lines.0.sku must be a string'],
                ],
                'error' => 'Bad Request',
            ], $e->getResponse());
        }
    }

    public function testWhitelistStripsUnknownKeysAndForbidThrows(): void
    {
        $pipe = new ValidationPipe(whitelist: true, transform: false);
        $value = $pipe->transform(['customer' => 'Ann', 'extra' => 1, 'line' => ['sku' => 'a', 'oops' => 2]], self::body());
        self::assertSame(['customer' => 'Ann', 'line' => ['sku' => 'a', 'oops' => 2]], $value, 'transform=false returns the (top-level stripped) array');

        $dto = (new ValidationPipe(whitelist: true))->transform(['customer' => 'Ann', 'line' => ['sku' => 'a', 'oops' => 2]], self::body());
        self::assertInstanceOf(LineDto::class, $dto->line);
        self::assertFalse(property_exists($dto->line, 'oops'));

        try {
            (new ValidationPipe(whitelist: true, forbidNonWhitelisted: true))
                ->transform(['customer' => 'Ann', 'extra' => 1, 'line' => ['sku' => 'a', 'oops' => 2], 'lines' => [['sku' => 'b', 'bad' => 1]]], self::body());
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame([
                'property extra should not exist',
                'property line.oops should not exist',
                'property lines.0.bad should not exist',
            ], $e->messages);
        }
    }

    public function testExistingInstanceIsStillValidated(): void
    {
        $dto = new OrderDto;
        $this->expectException(ValidationException::class);
        (new ValidationPipe)->transform($dto, self::body());
    }

    public function testPrimitivesAreCastForParamAndQuery(): void
    {
        $pipe = new ValidationPipe;
        self::assertSame(5, $pipe->transform('5', new ArgumentMetadata('param', 'int', 'id')));
        self::assertSame(1.5, $pipe->transform('1.5', new ArgumentMetadata('query', 'float', 'p')));
        self::assertTrue($pipe->transform('true', new ArgumentMetadata('query', 'bool', 'b')));
        self::assertSame('x', $pipe->transform('x', new ArgumentMetadata('query', 'string', 's')));
        self::assertSame('5', $pipe->transform('5', new ArgumentMetadata('body', 'int')), 'body primitives are untouched (like Nest)');
        self::assertSame('5', (new ValidationPipe(transform: false))->transform('5', new ArgumentMetadata('param', 'int', 'id')));
        self::assertSame(['a' => 1], $pipe->transform(['a' => 1], new ArgumentMetadata('body', 'array')));
        self::assertSame('x', $pipe->transform('x', new ArgumentMetadata('body', null)));
    }

    public function testInvalidPrimitiveThrows400(): void
    {
        try {
            (new ValidationPipe)->transform('abc', new ArgumentMetadata('param', 'int', 'id'));
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(400, $e->getStatus());
            self::assertSame(['id' => ['id must be an integer']], $e->errors);
        }
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('b must be a boolean value');
        (new ValidationPipe)->transform('maybe', new ArgumentMetadata('query', 'bool', 'b'));
    }

    public function testNonValidatableMetatypesPassThrough(): void
    {
        $pipe = new ValidationPipe;
        $date = new \DateTimeImmutable;
        self::assertSame($date, $pipe->transform($date, self::body(\DateTimeImmutable::class)));
        self::assertSame('active', $pipe->transform('active', self::body(Status::class)));
        self::assertNull($pipe->transform(null, self::body()));
        self::assertSame('scalar', $pipe->transform('scalar', self::body()));
    }

    public function testCustomDecoratorsSkippedUnlessEnabled(): void
    {
        $custom = new ArgumentMetadata('custom', OrderDto::class);
        self::assertSame([], (new ValidationPipe)->transform([], $custom));
        $this->expectException(ValidationException::class);
        (new ValidationPipe(validateCustomDecorators: true))->transform([], $custom);
    }

    public function testDisableErrorMessagesAndExceptionFactory(): void
    {
        try {
            (new ValidationPipe(disableErrorMessages: true))->transform([], self::body());
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame([], $e->messages);
        }

        $pipe = new ValidationPipe(exceptionFactory: fn (array $errors) => new UnprocessableEntityException($errors[0]->property . ' invalid'));
        try {
            $pipe->transform([], self::body());
            self::fail('expected UnprocessableEntityException');
        } catch (UnprocessableEntityException $e) {
            self::assertSame('customer invalid', $e->getMessage());
            self::assertSame(422, $e->getStatus());
        }
    }

    public function testSkipMissingAndStopAtFirstError(): void
    {
        self::assertInstanceOf(OrderDto::class, (new ValidationPipe(skipMissingProperties: true))->transform([], self::body()));

        try {
            (new ValidationPipe(stopAtFirstError: true))->transform(['customer' => 'a', 'qty' => 'x'], self::body());
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(['qty must be an integer number'], $e->messages);
        }
    }
}
