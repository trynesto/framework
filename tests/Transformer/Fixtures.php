<?php

declare(strict_types=1);

namespace Nesto\Tests\Transformer;

use DateTimeImmutable;
use JsonSerializable;
use Nesto\Transformer\Attributes\Exclude;
use Nesto\Transformer\Attributes\Expose;
use Nesto\Transformer\Attributes\Transform;
use Nesto\Transformer\Attributes\Type;

enum Color: string
{
    case Red = 'red';
    case Blue = 'blue';
}

enum Size
{
    case Small;
    case Large;
}

final class AddressDto
{
    public string $city;
    public ?string $zip = null;
}

final class Upper
{
    public static function apply(mixed $value): mixed
    {
        return is_string($value) ? strtoupper($value) : $value;
    }
}

final class Cents
{
    public function __invoke(mixed $value, object $source, string $key): string
    {
        return number_format($value / 100, 2);
    }
}

final class CatDto
{
    public function __construct(
        public readonly string $name,
        public int $age = 1,
    ) {}

    public Color $color = Color::Red;
    public ?Size $size = null;
    public ?AddressDto $address = null;
    /** @var list<AddressDto> */
    #[Type(AddressDto::class)]
    public array $previous = [];
    #[Expose(name: 'born_at')]
    public DateTimeImmutable $bornAt;
    #[Exclude]
    public string $secret = 'keep';
    #[Exclude(toPlainOnly: true)]
    public string $password = '';
    #[Expose(groups: ['admin'])]
    public string $note = 'n/a';
    public bool $active = false;
    public ?float $weight = null;
    #[Transform([Upper::class, 'apply'], toClassOnly: true)]
    public string $nick = '';
    #[Transform(Cents::class, toPlainOnly: true)]
    public int $priceCents = 0;
    public string $uninitialized;
    public array $tags = [];
}

final class ExposedOnly
{
    #[Expose]
    public string $id = '';
    public string $internal = 'x';
}

final class SnakeDto
{
    public string $firstName = '';
    public ?string $lastName = null;
}

final class Money implements JsonSerializable
{
    public function __construct(private int $amount) {}

    public function jsonSerialize(): array
    {
        return ['amount' => $this->amount, 'currency' => 'USD'];
    }
}

final class Wallet
{
    public Money $money;
    /** @var array<string, Money> */
    public array $pockets = [];
}

final class UnionDto
{
    public int|string $id;
}
