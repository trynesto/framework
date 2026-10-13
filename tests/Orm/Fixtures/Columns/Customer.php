<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Columns;

use DateTimeImmutable;
use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\CreateDateColumn;
use Nesto\Orm\Attributes\Embedded;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\Generated;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

/** Column features that work on every database (the driver suite syncs, diffs and round-trips it). */
#[Entity(name: 'col_customers')]
final class Customer
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column(length: 60)]
    public string $name;

    #[Column(length: 120, transformer: [Trim::class, Lowercase::class])]
    public string $email;

    #[Column(length: 100, select: false, nullable: true)]
    public ?string $password = null;

    #[Column(length: 40, update: false, nullable: true)]
    public ?string $createdBy = null;

    #[Column(length: 40, insert: false, nullable: true)]
    public ?string $note = null;

    #[Column(length: 20, readonly: true, default: 'web')]
    public string $source = 'web';

    #[Column(type: 'int', transformer: Cents::class)]
    public float $credit = 0.0;

    #[Column(type: 'money', nullable: true)]
    public ?Money $balance = null;

    #[Column(type: 'simple-enum')]
    public Tier $tier = Tier::Free;

    #[Column(type: 'enum')]
    public Priority $priority = Priority::Low;

    #[Column(type: 'simple-array')]
    public array $roles = [];

    #[Column(type: 'simple-json', nullable: true)]
    public ?array $prefs = null;

    #[Column(type: 'json', nullable: true)]
    public ?array $meta = null;

    #[Column(type: 'int')]
    public int $quantity = 1;

    #[Column(type: 'int')]
    public int $unitPrice = 0;

    #[Column(type: 'int', generatedType: 'STORED', asExpression: 'quantity * unit_price')]
    public ?int $total = null;

    #[Column(type: 'uuid')]
    #[Generated('uuid')]
    public string $publicId;

    #[Embedded(Address::class)]
    public Address $address;

    #[Embedded(Address::class, prefix: 'bill')]
    public ?Address $billing = null;

    #[Embedded(Geo::class, prefix: false)]
    public Geo $spot;

    #[CreateDateColumn]
    public ?DateTimeImmutable $createdAt = null;
}
