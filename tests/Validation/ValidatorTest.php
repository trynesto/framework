<?php

declare(strict_types=1);

namespace Nesto\Tests\Validation;

use Nesto\Transformer\Attributes\Type;
use Nesto\Transformer\ClassTransformer;
use Nesto\Validation\Attributes\ArrayNotEmpty;
use Nesto\Validation\Attributes\IsDefined;
use Nesto\Validation\Attributes\IsEmail;
use Nesto\Validation\Attributes\IsInt;
use Nesto\Validation\Attributes\IsNotEmpty;
use Nesto\Validation\Attributes\IsOptional;
use Nesto\Validation\Attributes\IsString;
use Nesto\Validation\Attributes\Max;
use Nesto\Validation\Attributes\Min;
use Nesto\Validation\Attributes\MinLength;
use Nesto\Validation\Attributes\Validate;
use Nesto\Validation\Attributes\ValidateIf;
use Nesto\Validation\Attributes\ValidateNested;
use Nesto\Validation\Contracts\ConstraintValidator;
use Nesto\Validation\ValidationError;
use Nesto\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class AddressDto
{
    #[IsString]
    #[IsNotEmpty]
    public string $city;

    #[IsOptional]
    #[IsString]
    public ?string $zip = null;
}

final class IsSlug implements ConstraintValidator
{
    public function validate(mixed $value, object $object, string $property): ?string
    {
        return is_string($value) && preg_match('/^[a-z0-9-]+$/', $value) ? null : "$property must be a slug";
    }
}

final class UserDto
{
    #[IsString]
    #[MinLength(2)]
    public string $name;

    #[IsEmail]
    public string $email;

    #[IsInt]
    #[Min(0)]
    #[Max(150)]
    public int $age;

    #[IsOptional]
    #[IsString]
    public ?string $bio = null;

    #[IsDefined]
    public mixed $anything;

    #[ValidateNested]
    public AddressDto $address;

    #[ValidateNested(each: true)]
    #[Type(AddressDto::class)]
    #[ArrayNotEmpty]
    public array $previous = [];

    #[IsString(each: true)]
    public array $tags = [];

    #[Validate(IsSlug::class)]
    public string $slug = 'ok';

    #[IsString(groups: ['create'])]
    public mixed $onlyOnCreate = 1;

    #[ValidateIf([self::class, 'hasCompany'])]
    #[IsString]
    #[MinLength(5)]
    public mixed $vat = null;

    public ?string $company = null;

    public static function hasCompany(self $dto, mixed $value): bool
    {
        return $dto->company !== null;
    }
}

final class ValidatorTest extends TestCase
{
    private function user(array $plain): UserDto
    {
        return ClassTransformer::plainToInstance(UserDto::class, $plain + [
            'name' => 'Tom', 'email' => 't@x.io', 'age' => 30, 'anything' => 0,
            'address' => ['city' => 'Baghdad'], 'previous' => [['city' => 'A']],
        ]);
    }

    public function testValidObjectHasNoErrors(): void
    {
        self::assertSame([], (new Validator)->validate($this->user([])));
    }

    public function testErrorsCarryConstraintNamesAndMessages(): void
    {
        $errors = (new Validator)->validate($this->user(['name' => 'T', 'email' => 'bad', 'age' => 200]));

        self::assertCount(3, $errors);
        self::assertContainsOnlyInstancesOf(ValidationError::class, $errors);
        self::assertSame('name', $errors[0]->property);
        self::assertSame('T', $errors[0]->value);
        self::assertSame(['minLength' => 'name must be longer than or equal to 2 characters'], $errors[0]->constraints);
        self::assertSame(['isEmail' => 'email must be an email'], $errors[1]->constraints);
        self::assertSame(['max' => 'age must not be greater than 150'], $errors[2]->constraints);
        self::assertSame([
            'name must be longer than or equal to 2 characters',
            'email must be an email',
            'age must not be greater than 150',
        ], Validator::flatten($errors));
    }

    public function testUninitializedPropertiesCountAsUndefined(): void
    {
        $dto = new UserDto;
        $messages = Validator::flatten((new Validator)->validate($dto));

        self::assertContains('name must be a string', $messages);
        self::assertContains('anything should not be null or undefined', $messages);
        self::assertContains('nested property address must be either object or array', $messages);
        self::assertNotContains('bio must be a string', $messages, 'IsOptional skips undefined');
        self::assertNotContains('vat must be a string', $messages, 'ValidateIf false skips');
    }

    public function testRawUnassignableValuesAreValidatedAsTheOriginal(): void
    {
        $dto = $this->user(['age' => 'abc', 'tags' => 'x']);
        $messages = Validator::flatten((new Validator)->validate($dto));

        self::assertContains('age must be an integer number', $messages);
        self::assertContains('age must not be less than 0', $messages);
        self::assertContains('each value in tags must be a string', $messages);
    }

    public function testNestedErrorsAreChildrenAndFlattenWithDots(): void
    {
        $dto = $this->user(['address' => ['city' => ''], 'previous' => [['city' => 'ok'], ['city' => []]]]);
        $errors = (new Validator)->validate($dto);

        self::assertCount(2, $errors);
        self::assertSame('address', $errors[0]->property);
        self::assertSame([], $errors[0]->constraints);
        self::assertSame('city', $errors[0]->children[0]->property);
        self::assertSame(['isNotEmpty' => 'city should not be empty'], $errors[0]->children[0]->constraints);
        self::assertSame('1', $errors[1]->children[0]->property);
        self::assertSame([
            'address.city should not be empty',
            'previous.1.city must be a string',
        ], Validator::flatten($errors));
    }

    public function testEach(): void
    {
        $ok = $this->user(['tags' => ['a', 'b']]);
        self::assertSame([], (new Validator)->validate($ok));

        $bad = $this->user(['tags' => ['a', 2]]);
        self::assertSame(['each value in tags must be a string'], Validator::flatten((new Validator)->validate($bad)));
    }

    public function testCustomValidatorAndValidateIf(): void
    {
        $dto = $this->user(['slug' => 'Not Slug', 'company' => 'ACME', 'vat' => 'abc']);
        $errors = (new Validator)->validate($dto);
        $byProp = [];
        foreach ($errors as $e) {
            $byProp[$e->property] = $e->constraints;
        }

        self::assertSame(['isSlug' => 'slug must be a slug'], $byProp['slug']);
        self::assertSame(['minLength' => 'vat must be longer than or equal to 5 characters'], $byProp['vat']);
    }

    public function testGroupsAndAlways(): void
    {
        $dto = $this->user(['onlyOnCreate' => 1]);
        self::assertSame([], (new Validator)->validate($dto), 'grouped constraint not run without groups');
        self::assertSame(['onlyOnCreate must be a string'], Validator::flatten((new Validator)->validate($dto, ['groups' => ['create']])));
        self::assertSame(['onlyOnCreate must be a string'], Validator::flatten((new Validator)->validate($dto, ['always' => true])));

        $bad = $this->user(['name' => 'T', 'onlyOnCreate' => 1]);
        self::assertSame(['onlyOnCreate must be a string'], Validator::flatten((new Validator)->validate($bad, ['groups' => ['create']])), 'ungrouped constraints are skipped when groups are requested');
    }

    public function testSkipMissingPropertiesStillRunsIsDefined(): void
    {
        $dto = new UserDto;
        $dto->bio = null;
        $dto->previous = [new AddressDto];
        $messages = Validator::flatten((new Validator)->validate($dto, ['skipMissingProperties' => true]));
        self::assertSame(['anything should not be null or undefined'], $messages);
    }

    public function testStopAtFirstError(): void
    {
        $dto = $this->user(['name' => []]);
        $errors = (new Validator)->validate($dto, ['stopAtFirstError' => true]);
        self::assertCount(1, $errors);
        self::assertSame(['isString' => 'name must be a string'], $errors[0]->constraints);

        $all = (new Validator)->validate($dto);
        self::assertCount(2, $all[0]->constraints);
    }

    public function testToArray(): void
    {
        $errors = (new Validator)->validate($this->user(['email' => 'nope']));
        self::assertSame(['property' => 'email', 'value' => 'nope', 'constraints' => ['isEmail' => 'email must be an email']], $errors[0]->toArray());
    }
}
