<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures;

use DateTimeImmutable;
use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\CreateDateColumn;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

#[Entity]
final class Cat
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $name;

    #[Column]
    public int $age = 1;

    #[CreateDateColumn]
    public ?DateTimeImmutable $createdAt = null;
}
