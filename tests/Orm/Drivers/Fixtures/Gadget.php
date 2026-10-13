<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Drivers\Fixtures;

use DateTimeImmutable;
use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\Index;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

/** One column of every supported type (the driver suite checks DDL and round trips with it). */
#[Entity(name: 'gadgets')]
#[Index(columns: ['name', 'kind'])]
final class Gadget
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column(length: 120)]
    public string $name;

    #[Column(type: 'text', nullable: true)]
    public ?string $description = null;

    #[Column]
    public int $quantity = 0;

    #[Column(type: 'bigint')]
    public int $serial = 0;

    #[Column]
    public bool $active = true;

    #[Column]
    public float $weight = 0.0;

    #[Column(type: 'decimal', precision: 10, scale: 2)]
    public string $price = '0.00';

    #[Column(type: 'json', nullable: true)]
    public ?array $specs = null;

    #[Column(type: 'json')]
    public array $labels = [];

    #[Column(type: 'date', nullable: true)]
    public ?DateTimeImmutable $releasedOn = null;

    #[Column(nullable: true)]
    public ?DateTimeImmutable $lastSeenAt = null;

    #[Column(type: 'uuid', nullable: true)]
    public ?string $externalId = null;

    #[Column(type: 'enum')]
    public GadgetKind $kind = GadgetKind::Tool;

    #[Column(nullable: true)]
    public ?int $rating = null;
}
