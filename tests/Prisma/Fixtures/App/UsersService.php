<?php

declare(strict_types=1);

namespace Nesto\Tests\Prisma\Fixtures\App;

use Nesto\Common\Attributes\Injectable;
use Nesto\Prisma\Client\PrismaClient;

/** Uses the Prisma client through DI (PrismaModule is global). */
#[Injectable]
final class UsersService
{
    public function __construct(public readonly PrismaClient $prisma) {}

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function create(array $data): array
    {
        return $this->prisma->user->create(['data' => $data, 'select' => ['id' => true, 'email' => true]]);
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->prisma->user->findMany(['orderBy' => ['id' => 'asc'], 'select' => ['id' => true, 'email' => true, '_count' => ['select' => ['posts' => true]]]]);
    }

    /** @param array<string, mixed> $post @return array<string, mixed> */
    public function addPost(int $userId, array $post): array
    {
        return $this->prisma->transaction(fn (PrismaClient $tx) => $tx->post->create(['data' => $post + ['author' => ['connect' => ['id' => $userId]]]]));
    }
}
