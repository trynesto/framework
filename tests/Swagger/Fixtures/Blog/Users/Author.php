<?php

declare(strict_types=1);

namespace Nesto\Tests\Swagger\Fixtures\Blog\Users;

use Nesto\Transformer\Attributes\Exclude;
use Nesto\Transformer\Attributes\Expose;

final class Author
{
    public int $id;

    #[Expose(name: 'display_name')]
    public string $displayName;

    /** Only serialized with the "owner" group. */
    #[Expose(groups: ['owner'])]
    public string $email;

    #[Exclude]
    public string $password;

    #[Exclude(toPlainOnly: true)]
    public string $pin;

    #[Exclude(toClassOnly: true)]
    public int $postCount = 0;
}
