<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Drivers\Fixtures;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

/** The `gadgets` table one release later: synchronize() must ADD these columns to the existing table. */
#[Entity(name: 'gadgets')]
final class GadgetV2
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column(length: 120)]
    public string $name;

    #[Column(nullable: true)]
    public ?string $nickname = null;

    #[Column]
    public int $stock = 5;

    #[Column]
    public string $sku;

    #[Column(unique: true, nullable: true)]
    public ?string $barcode = null;
}
