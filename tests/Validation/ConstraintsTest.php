<?php

declare(strict_types=1);

namespace Nesto\Tests\Validation;

use Nesto\Validation\Attributes as V;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;
use Nesto\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

enum Status: string
{
    case Active = 'active';
    case Banned = 'banned';
}

enum Flag
{
    case On;
    case Off;
}

/** Table-driven check of every built-in constraint: valid values pass, invalid values produce the class-validator message. */
final class ConstraintsTest extends TestCase
{
    private function check(Constraint $c, mixed $value): ?string
    {
        $ctx = new ValidationContext(new \stdClass, 'field', [], new Validator);

        return $c->validate($value, $ctx);
    }

    /** @return iterable<string, array{Constraint, list<mixed>, list<mixed>, string}> */
    public static function constraints(): iterable
    {
        yield 'IsNotEmpty' => [new V\IsNotEmpty, ['a', 0, false, []], ['', null], 'field should not be empty'];
        yield 'IsEmpty' => [new V\IsEmpty, ['', null], ['a', 0], 'field must be empty'];
        yield 'IsString' => [new V\IsString, ['a', ''], [1, null, []], 'field must be a string'];
        yield 'IsInt' => [new V\IsInt, [1, -5, 0], ['1', 1.5, null], 'field must be an integer number'];
        yield 'IsNumber' => [new V\IsNumber, [1, 1.5, -2.0], ['1', NAN, INF, null], 'field must be a number conforming to the specified constraints'];
        yield 'IsNumber(maxDecimalPlaces)' => [new V\IsNumber(maxDecimalPlaces: 2), [1.25, 3], [1.256], 'field must be a number conforming to the specified constraints'];
        yield 'IsBoolean' => [new V\IsBoolean, [true, false], ['true', 1, null], 'field must be a boolean value'];
        yield 'IsArray' => [new V\IsArray, [[], [1]], ['a', null, new \stdClass], 'field must be an array'];
        yield 'IsObject' => [new V\IsObject, [new \stdClass, ['a' => 1], []], ['a', [1, 2], null], 'field must be an object'];
        yield 'IsEmail' => [new V\IsEmail, ['a@b.co', 'first.last+tag@example.org'], ['a@b', 'nope', 1, null], 'field must be an email'];
        yield 'IsUrl' => [new V\IsUrl, ['https://example.com/x?y=1', 'http://sub.example.co.uk'], ['example.com', 'ftp://x.com', 'http://localhost', 'not a url', null], 'field must be a URL address'];
        yield 'IsUrl(loose)' => [new V\IsUrl(requireTld: false, requireProtocol: false, protocols: ['http', 'https', 'ftp']), ['example.com', 'http://localhost', 'ftp://x'], ['', 'a b'], 'field must be a URL address'];
        yield 'IsUuid' => [new V\IsUuid, ['550e8400-e29b-41d4-a716-446655440000'], ['550e8400', null], 'field must be a UUID'];
        yield 'IsUuid(4)' => [new V\IsUuid(version: 4), ['550e8400-e29b-41d4-a716-446655440000'], ['550e8400-e29b-11d4-a716-446655440000'], 'field must be a UUID'];
        yield 'IsDate' => [new V\IsDate, [new \DateTimeImmutable, new \DateTime], ['2020-01-01', null], 'field must be a Date instance'];
        yield 'IsDateString' => [new V\IsDateString, ['2020-01-31', '2020-01-31T10:00:00Z', '2020-01-31 10:00:00'], ['31/01/2020', '2020-13-45', 'x', 5, null], 'field must be a valid ISO 8601 date string'];
        yield 'IsEnum(backed)' => [new V\IsEnum(Status::class), [Status::Active, 'active', 'banned'], ['deleted', Flag::On, null], 'field must be one of the following values: active, banned'];
        yield 'IsEnum(unit)' => [new V\IsEnum(Flag::class), [Flag::On, 'Off'], ['on', null], 'field must be one of the following values: On, Off'];
        yield 'IsIn' => [new V\IsIn(['a', 1]), ['a', 1], ['1', 'b', null], 'field must be one of the following values: a, 1'];
        yield 'IsNotIn' => [new V\IsNotIn(['root']), ['user', null], ['root'], 'field should not be one of the following values: root'];
        yield 'Equals' => [new V\Equals(true), [true], [1, 'true', null], 'field must be equal to true'];
        yield 'NotEquals' => [new V\NotEquals('x'), ['y', null], ['x'], 'field should not be equal to x'];
        yield 'Min' => [new V\Min(0), [0, 1, 0.5], [-1, '5', null], 'field must not be less than 0'];
        yield 'Max' => [new V\Max(10), [10, -3], [11, '1', null], 'field must not be greater than 10'];
        yield 'IsPositive' => [new V\IsPositive, [1, 0.1], [0, -1, '1', null], 'field must be a positive number'];
        yield 'IsNegative' => [new V\IsNegative, [-1, -0.1], [0, 1, null], 'field must be a negative number'];
        yield 'Length(min)' => [new V\Length(2, 4), ['ab', 'abcd', 'عربي'], ['a', '', 5, null], 'field must be longer than or equal to 2 characters'];
        yield 'Length(max)' => [new V\Length(2, 4), ['ab'], ['abcde'], 'field must be shorter than or equal to 4 characters'];
        yield 'MinLength' => [new V\MinLength(3), ['abc'], ['ab', null], 'field must be longer than or equal to 3 characters'];
        yield 'MaxLength' => [new V\MaxLength(3), ['abc', ''], ['abcd', null], 'field must be shorter than or equal to 3 characters'];
        yield 'Matches' => [new V\Matches('/^[a-z]+$/'), ['abc'], ['ABC', 'a1', null], 'field must match /^[a-z]+$/ regular expression'];
        yield 'ArrayMinSize' => [new V\ArrayMinSize(1), [[1], [1, 2]], [[], 'a', null], 'field must contain at least 1 elements'];
        yield 'ArrayMaxSize' => [new V\ArrayMaxSize(2), [[], [1, 2]], [[1, 2, 3], null], 'field must contain no more than 2 elements'];
        yield 'ArrayUnique' => [new V\ArrayUnique, [[1, 2, '1'], []], [[1, 1], ['a', 'a'], null], "All field's elements must be unique"];
        yield 'ArrayNotEmpty' => [new V\ArrayNotEmpty, [[0]], [[], null, 'a'], 'field should not be empty'];
        yield 'IsAlpha' => [new V\IsAlpha, ['abcDEF'], ['abc1', 'a b', '', null], 'field must contain only letters (a-zA-Z)'];
        yield 'IsAlphanumeric' => [new V\IsAlphanumeric, ['abc123'], ['a-b', '', null], 'field must contain only letters and numbers'];
        yield 'IsNumberString' => [new V\IsNumberString, ['42', '-3.5', '1e3'], ['abc', 42, null], 'field must be a number string'];
        yield 'IsPhoneNumber' => [new V\IsPhoneNumber, ['+9647701234567', '07701234567', '+1 (555) 123-4567'], ['123', 'phone', '+0123456789', null], 'field must be a valid phone number'];
        yield 'IsJSON' => [new V\IsJSON, ['{"a":1}', '[]', 'null'], ['{a:1}', '', null], 'field must be a json string'];
        yield 'IsIP' => [new V\IsIP, ['127.0.0.1', '::1'], ['999.1.1.1', 'x', null], 'field must be an ip address'];
        yield 'IsIP(4)' => [new V\IsIP(version: 4), ['10.0.0.1'], ['::1'], 'field must be an ip address'];
        yield 'IsIP(6)' => [new V\IsIP(version: '6'), ['::1'], ['10.0.0.1'], 'field must be an ip address'];
        yield 'IsPort' => [new V\IsPort, [0, 80, '8080', 65535], [65536, -1, '80a', null], 'field must be a port'];
        yield 'IsLatitude' => [new V\IsLatitude, [0, 90, -90, '45.5'], [91, '-91', 'x', null], 'field must be a latitude string or number'];
        yield 'IsLongitude' => [new V\IsLongitude, [180, -180, '12.3'], [181, 'x', null], 'field must be a longitude string or number'];
        yield 'Contains' => [new V\Contains('@'), ['a@b'], ['ab', null], 'field must contain a @ string'];
        yield 'NotContains' => [new V\NotContains('<'), ['ab'], ['<b>', null], 'field should not contain a < string'];
        yield 'StartsWith' => [new V\StartsWith('sk_'), ['sk_1'], ['pk_1', null], 'field must start with sk_'];
        yield 'EndsWith' => [new V\EndsWith('.pdf'), ['a.pdf'], ['a.doc', null], 'field must end with .pdf'];
        yield 'IsLowercase' => [new V\IsLowercase, ['abc', 'abc1'], ['Abc', null], 'field must be a lowercase string'];
        yield 'IsUppercase' => [new V\IsUppercase, ['ABC'], ['Abc', null], 'field must be uppercase'];
        yield 'IsDivisibleBy' => [new V\IsDivisibleBy(5), [0, 10, 15.0], [7, '10', null], 'field must be divisible by 5'];
        yield 'ValidateNested' => [new V\ValidateNested, [new \stdClass, [new \stdClass], []], ['x', [1], null], 'nested property field must be either object or array'];
    }

    /**
     * @param list<mixed> $valid
     * @param list<mixed> $invalid
     */
    #[DataProvider('constraints')]
    public function testConstraint(Constraint $c, array $valid, array $invalid, string $message): void
    {
        foreach ($valid as $v) {
            self::assertNull($this->check($c, $v), sprintf('%s should accept %s', $c::class, var_export($v, true)));
        }
        foreach ($invalid as $v) {
            self::assertSame($message, $this->check($c, $v), sprintf('%s should reject %s', $c::class, var_export($v, true)));
        }
    }

    public function testIsDefinedUsesContextDefinedFlag(): void
    {
        $validator = new Validator;
        $c = new V\IsDefined;
        self::assertNull($c->validate('x', new ValidationContext(new \stdClass, 'f', [], $validator, defined: true)));
        self::assertSame('f should not be null or undefined', $c->validate(null, new ValidationContext(new \stdClass, 'f', [], $validator, defined: true)));
        self::assertSame('f should not be null or undefined', $c->validate(null, new ValidationContext(new \stdClass, 'f', [], $validator, defined: false)));
    }

    public function testCustomMessageWithPlaceholders(): void
    {
        $c = new V\MinLength(3, message: '$property ($value) needs $constraint1 chars in $target');
        self::assertSame('field (ab) needs 3 chars in stdClass', $this->check($c, 'ab'));
        self::assertSame('minLength', $c->name());
        self::assertSame('isNotEmpty', (new V\IsNotEmpty)->name());
    }
}
