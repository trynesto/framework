<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Relations;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\MorphTo;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

/** commentable_type + commentable_id. */
#[Entity]
final class Comment
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $body;

    #[MorphTo]
    public Article|Video|null $commentable = null;
}
