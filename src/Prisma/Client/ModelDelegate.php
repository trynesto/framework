<?php

declare(strict_types=1);

namespace Nesto\Prisma\Client;

/**
 * The model API of the client (`$prisma->user`): Prisma's model queries with their arguments as PHP arrays.
 * Records are arrays keyed by field name (their shape follows select / include / omit).
 *
 *   $prisma->user->findUnique(['where' => ['email' => 'ada@example.com'], 'include' => ['posts' => true]]);
 *   $prisma->post->findMany(['where' => ['published' => true, 'title' => ['contains' => 'php']], 'orderBy' => ['id' => 'desc'], 'take' => 10]);
 *   $prisma->user->create(['data' => ['email' => 'a@b.c', 'posts' => ['create' => [['title' => 'Hello']]]]]);
 *   $prisma->post->update(['where' => ['id' => 1], 'data' => ['views' => ['increment' => 1]]]);
 *   $prisma->post->aggregate(['_avg' => ['views' => true], '_count' => true]);
 *
 * The generated client subclasses this per model (UserDelegate) with PHPDoc array shapes for the arguments
 * and results. Model extensions (`$prisma->extends(['model' => ['user' => ['signUp' => fn ...]]])`) are
 * callable as methods.
 */
class ModelDelegate
{
    public function __construct(protected readonly PrismaClient $client, public readonly string $name) {}

    /** @param array<string, mixed> $args @return array<string, mixed>|null */
    public function findUnique(array $args): ?array
    {
        return $this->client->dispatch($this->name, 'findUnique', $args);
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    public function findUniqueOrThrow(array $args): array
    {
        return $this->client->dispatch($this->name, 'findUniqueOrThrow', $args);
    }

    /** @param array<string, mixed> $args @return array<string, mixed>|null */
    public function findFirst(array $args = []): ?array
    {
        return $this->client->dispatch($this->name, 'findFirst', $args);
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    public function findFirstOrThrow(array $args = []): array
    {
        return $this->client->dispatch($this->name, 'findFirstOrThrow', $args);
    }

    /** @param array<string, mixed> $args @return list<array<string, mixed>> */
    public function findMany(array $args = []): array
    {
        return $this->client->dispatch($this->name, 'findMany', $args);
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    public function create(array $args): array
    {
        return $this->client->dispatch($this->name, 'create', $args);
    }

    /** @param array<string, mixed> $args @return array{count: int} */
    public function createMany(array $args): array
    {
        return $this->client->dispatch($this->name, 'createMany', $args);
    }

    /** PostgreSQL and SQLite. @param array<string, mixed> $args @return list<array<string, mixed>> */
    public function createManyAndReturn(array $args): array
    {
        return $this->client->dispatch($this->name, 'createManyAndReturn', $args);
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    public function update(array $args): array
    {
        return $this->client->dispatch($this->name, 'update', $args);
    }

    /** @param array<string, mixed> $args @return array{count: int} */
    public function updateMany(array $args): array
    {
        return $this->client->dispatch($this->name, 'updateMany', $args);
    }

    /** PostgreSQL and SQLite. @param array<string, mixed> $args @return list<array<string, mixed>> */
    public function updateManyAndReturn(array $args): array
    {
        return $this->client->dispatch($this->name, 'updateManyAndReturn', $args);
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    public function upsert(array $args): array
    {
        return $this->client->dispatch($this->name, 'upsert', $args);
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    public function delete(array $args): array
    {
        return $this->client->dispatch($this->name, 'delete', $args);
    }

    /** @param array<string, mixed> $args @return array{count: int} */
    public function deleteMany(array $args = []): array
    {
        return $this->client->dispatch($this->name, 'deleteMany', $args);
    }

    /** @param array<string, mixed> $args @return int|array<string, int> */
    public function count(array $args = []): int|array
    {
        return $this->client->dispatch($this->name, 'count', $args);
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    public function aggregate(array $args = []): array
    {
        return $this->client->dispatch($this->name, 'aggregate', $args);
    }

    /** @param array<string, mixed> $args @return list<array<string, mixed>> */
    public function groupBy(array $args): array
    {
        return $this->client->dispatch($this->name, 'groupBy', $args);
    }

    /** Model extension methods (`extends(['model' => ['user' => ['signUp' => fn (...) => ...]]])`). @param list<mixed> $arguments */
    public function __call(string $method, array $arguments): mixed
    {
        $extension = $this->client->modelExtension($this->name, $method);
        if ($extension === null) {
            throw new \BadMethodCallException(sprintf('Call to undefined method %s::%s() (model %s).', static::class, $method, $this->name));
        }
        $bound = @\Closure::bind($extension, $this, static::class);

        return ($bound ?? $extension)(...$arguments);
    }

    /** The client this delegate belongs to (Prisma.getExtensionContext(this).$parent). */
    public function client(): PrismaClient
    {
        return $this->client;
    }
}
