<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Drivers\Fixtures\Schema\V2;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

/** New in version 2 (referenced by sd_books.publisher_id). */
#[Entity(name: 'sd_publishers')]
final class SdPublisher
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $name;
}
