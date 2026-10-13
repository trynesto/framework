<?php

declare(strict_types=1);

namespace Nesto\Tests\Transformer;

use DateTime;
use DateTimeImmutable;
use Nesto\Transformer\ClassTransformer;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixtures.php';

final class ClassTransformerTest extends TestCase
{
    public function testHydratesScalarsWithQueryStringCasting(): void
    {
        $cat = ClassTransformer::plainToInstance(CatDto::class, [
            'name' => 'Tom', 'age' => '3', 'active' => 'true', 'weight' => '4.5', 'tags' => ['a', 'b'],
        ]);

        self::assertSame('Tom', $cat->name);
        self::assertSame(3, $cat->age);
        self::assertTrue($cat->active);
        self::assertSame(4.5, $cat->weight);
        self::assertSame(['a', 'b'], $cat->tags);
    }

    public function testPromotedReadonlyAndDefaults(): void
    {
        $cat = ClassTransformer::plainToInstance(CatDto::class, ['name' => 'Tom']);
        self::assertSame(1, $cat->age);
        self::assertSame(Color::Red, $cat->color);

        $noName = ClassTransformer::plainToInstance(CatDto::class, ['age' => 7]);
        self::assertFalse((new \ReflectionProperty($noName, 'name'))->isInitialized($noName));
        self::assertSame(7, $noName->age);
    }

    public function testEnumsAndDates(): void
    {
        $cat = ClassTransformer::plainToInstance(CatDto::class, [
            'name' => 'x', 'color' => 'blue', 'size' => 'Large', 'born_at' => '2020-01-02T03:04:05+00:00',
        ]);
        self::assertSame(Color::Blue, $cat->color);
        self::assertSame(Size::Large, $cat->size);
        self::assertInstanceOf(DateTimeImmutable::class, $cat->bornAt);
        self::assertSame('2020-01-02T03:04:05+00:00', $cat->bornAt->format(DATE_ATOM));

        $fromTs = ClassTransformer::plainToInstance(CatDto::class, ['name' => 'x', 'born_at' => 0]);
        self::assertSame('1970-01-01', $fromTs->bornAt->format('Y-m-d'));
    }

    public function testNestedObjectsAndTypedArrays(): void
    {
        $cat = ClassTransformer::plainToInstance(CatDto::class, [
            'name' => 'x',
            'address' => ['city' => 'Baghdad', 'zip' => '10001'],
            'previous' => [['city' => 'A'], ['city' => 'B']],
        ]);
        self::assertInstanceOf(AddressDto::class, $cat->address);
        self::assertSame('Baghdad', $cat->address->city);
        self::assertCount(2, $cat->previous);
        self::assertContainsOnlyInstancesOf(AddressDto::class, $cat->previous);
        self::assertSame('B', $cat->previous[1]->city);
    }

    public function testExcludeExposeAndTransformOnHydration(): void
    {
        $cat = ClassTransformer::plainToInstance(CatDto::class, [
            'name' => 'x', 'secret' => 'hacked', 'password' => 'pw', 'nick' => 'tom', 'note' => 'ignored',
        ]);
        self::assertSame('keep', $cat->secret, '#[Exclude] is never hydrated');
        self::assertSame('pw', $cat->password, '#[Exclude(toPlainOnly)] is hydrated');
        self::assertSame('TOM', $cat->nick, '#[Transform] applied');
        self::assertSame('n/a', $cat->note, 'grouped property not hydrated without groups');

        $admin = ClassTransformer::plainToInstance(CatDto::class, ['name' => 'x', 'note' => 'seen'], ['groups' => ['admin']]);
        self::assertSame('seen', $admin->note);
    }

    public function testUnknownKeysNeverCreateDynamicProperties(): void
    {
        $cat = ClassTransformer::plainToInstance(CatDto::class, ['name' => 'x', 'extra' => 1]);
        self::assertFalse(property_exists($cat, 'extra'));
        self::assertSame(['extra'], ClassTransformer::unknownKeys(CatDto::class, ['name' => 'x', 'born_at' => 'y', 'extra' => 1]));
    }

    public function testUnassignableValuesAreKeptAsRaw(): void
    {
        $cat = ClassTransformer::plainToInstance(CatDto::class, ['name' => 'x', 'age' => 'abc', 'tags' => 'nope', 'color' => 'green', 'password' => 5, 'nick' => 7]);
        self::assertSame(['age' => 'abc', 'color' => 'green', 'password' => 5, 'nick' => 7, 'tags' => 'nope'], ClassTransformer::rawValues($cat));
        self::assertSame('', $cat->password, 'numbers are not silently turned into strings');
        self::assertSame('', $cat->nick, 'a #[Transform] result that does not fit the property type is raw too');
        self::assertSame(1, $cat->age, 'constructor default used when the value is unassignable');
        self::assertSame([], $cat->tags);
    }

    public function testNullIntoNonNullableIsRaw(): void
    {
        $dto = ClassTransformer::plainToInstance(AddressDto::class, ['city' => null, 'zip' => null]);
        self::assertFalse((new \ReflectionProperty($dto, 'city'))->isInitialized($dto));
        self::assertNull($dto->zip);
        self::assertSame(['city' => null], ClassTransformer::rawValues($dto));
    }

    public function testExcludeExtraneousValues(): void
    {
        $dto = ClassTransformer::plainToInstance(ExposedOnly::class, ['id' => '1', 'internal' => 'changed'], ['excludeExtraneousValues' => true]);
        self::assertSame('1', $dto->id);
        self::assertSame('x', $dto->internal);
        self::assertSame(['id' => '1'], ClassTransformer::instanceToPlain($dto, ['excludeExtraneousValues' => true]));
        self::assertSame(['internal'], ClassTransformer::unknownKeys(ExposedOnly::class, ['id' => 1, 'internal' => 2], ['excludeExtraneousValues' => true]));
    }

    public function testSnakeNamingStrategyAndLenientSnakeKeys(): void
    {
        $dto = ClassTransformer::plainToInstance(SnakeDto::class, ['first_name' => 'A', 'last_name' => 'B'], ['namingStrategy' => 'snake']);
        self::assertSame('A', $dto->firstName);
        self::assertSame(['first_name' => 'A', 'last_name' => 'B'], ClassTransformer::instanceToPlain($dto, ['namingStrategy' => 'snake']));

        $lenient = ClassTransformer::plainToInstance(SnakeDto::class, ['first_name' => 'C']);
        self::assertSame('C', $lenient->firstName);
        self::assertSame(['firstName' => 'C', 'lastName' => null], ClassTransformer::instanceToPlain($lenient));
    }

    public function testInstanceToPlain(): void
    {
        $cat = ClassTransformer::plainToInstance(CatDto::class, [
            'name' => 'Tom', 'color' => 'blue', 'size' => 'Small', 'born_at' => '2021-05-06T00:00:00+00:00',
            'address' => ['city' => 'X'], 'previous' => [['city' => 'Y']], 'password' => 'pw', 'priceCents' => 1999,
        ]);
        $plain = ClassTransformer::instanceToPlain($cat);

        self::assertSame('Tom', $plain['name']);
        self::assertSame('blue', $plain['color']);
        self::assertSame('Small', $plain['size']);
        self::assertSame('2021-05-06T00:00:00+00:00', $plain['born_at']);
        self::assertSame(['city' => 'X', 'zip' => null], $plain['address']);
        self::assertSame([['city' => 'Y', 'zip' => null]], $plain['previous']);
        self::assertSame('19.99', $plain['priceCents']);
        self::assertArrayNotHasKey('secret', $plain);
        self::assertArrayNotHasKey('password', $plain);
        self::assertArrayNotHasKey('note', $plain, 'grouped property hidden without groups');
        self::assertArrayNotHasKey('uninitialized', $plain, 'uninitialized typed property skipped');
        self::assertArrayNotHasKey('bornAt', $plain);

        self::assertSame('n/a', ClassTransformer::instanceToPlain($cat, ['groups' => ['admin']])['note']);
    }

    public function testInstanceToPlainHandlesJsonSerializableTraversableAndLists(): void
    {
        $wallet = new Wallet;
        $wallet->money = new Money(5);
        $wallet->pockets = ['left' => new Money(1)];
        self::assertSame(
            ['money' => ['amount' => 5, 'currency' => 'USD'], 'pockets' => ['left' => ['amount' => 1, 'currency' => 'USD']]],
            ClassTransformer::instanceToPlain($wallet)
        );
        self::assertSame(['amount' => 2, 'currency' => 'USD'], ClassTransformer::instanceToPlain(new Money(2)));
        self::assertSame([['a' => 1]], ClassTransformer::toPlain([(object) ['a' => 1]]));
        self::assertSame([1, 2], ClassTransformer::instanceToPlain(new \ArrayIterator([1, 2])));
        self::assertSame('1970-01-01T00:00:00+00:00', ClassTransformer::toPlain(new DateTime('@0')));
    }

    public function testPlainToInstances(): void
    {
        $list = ClassTransformer::plainToInstances(AddressDto::class, [['city' => 'A'], ['city' => 'B']]);
        self::assertCount(2, $list);
        self::assertSame('A', $list[0]->city);
    }

    public function testUnionTypes(): void
    {
        self::assertSame('5', ClassTransformer::plainToInstance(UnionDto::class, ['id' => '5'])->id, 'already satisfies string: kept');
        self::assertSame(5, ClassTransformer::plainToInstance(UnionDto::class, ['id' => 5])->id);
        self::assertSame(5, ClassTransformer::plainToInstance(UnionDto::class, ['id' => 5.0])->id, 'cast to the first member that accepts it');
    }

    public function testExistingInstancesPassThrough(): void
    {
        $addr = new AddressDto;
        $addr->city = 'Z';
        $cat = ClassTransformer::plainToInstance(CatDto::class, ['name' => 'x', 'address' => $addr, 'color' => Color::Blue]);
        self::assertSame($addr, $cat->address);
        self::assertSame(Color::Blue, $cat->color);
    }

    public function testRejectsNonClasses(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ClassTransformer::plainToInstance(Color::class, []);
    }
}
