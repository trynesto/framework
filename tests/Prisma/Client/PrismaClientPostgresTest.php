<?php

declare(strict_types=1);

namespace Nesto\Tests\Prisma\Client;

final class PrismaClientPostgresTest extends PrismaClientTestCase
{
    protected static function databaseUrl(): ?string
    {
        return self::urlFromEnv('NESTO_TEST_PGSQL_URL');
    }

    protected static function provider(): string
    {
        return 'postgresql';
    }

    public function testScalarLists(): void
    {
        $post = $this->prisma->post->create(['data' => ['title' => 'a', 'tags' => ['php', 'sql', 'a "quoted", value']]]);
        self::assertSame(['php', 'sql', 'a "quoted", value'], $post['tags']);
        $this->prisma->post->create(['data' => ['title' => 'b', 'tags' => ['set' => ['js']]]]);
        $empty = $this->prisma->post->create(['data' => ['title' => 'c']]);
        self::assertSame([], $empty['tags']);
        $titles = fn (array $filter) => self::pluck($this->prisma->post->findMany(['where' => ['tags' => $filter], 'orderBy' => ['id' => 'asc']]), 'title');
        self::assertSame(['a'], $titles(['has' => 'php']));
        self::assertSame(['a'], $titles(['hasEvery' => ['php', 'sql']]));
        self::assertSame(['a', 'b'], $titles(['hasSome' => ['js', 'sql']]));
        self::assertSame(['c'], $titles(['isEmpty' => true]));
        self::assertSame(['a', 'b'], $titles(['isEmpty' => false]));
        self::assertSame(['b'], $titles(['equals' => ['js']]));
        $updated = $this->prisma->post->update(['where' => ['id' => 2], 'data' => ['tags' => ['push' => 'ts']]]);
        self::assertSame(['js', 'ts'], $updated['tags']);
        $updated = $this->prisma->post->update(['where' => ['id' => 3], 'data' => ['tags' => ['push' => ['x', 'y']]]]);
        self::assertSame(['x', 'y'], $updated['tags']);
        $updated = $this->prisma->post->update(['where' => ['id' => 3], 'data' => ['tags' => ['set' => []]]]);
        self::assertSame([], $updated['tags']);
        $updated = $this->prisma->post->update(['where' => ['id' => 3], 'data' => ['tags' => ['z']]]);
        self::assertSame(['z'], $updated['tags']);
    }

    public function testTimestampsAndNativeTypesRoundTrip(): void
    {
        $user = $this->prisma->user->create(['data' => ['email' => 'big@example.com', 'big' => '9223372036854775807', 'balance' => '12345678901234567890.123456789']]);
        self::assertSame(PHP_INT_MAX, $user['big']);
        self::assertSame('12345678901234567890.123456789', $user['balance'], 'Decimal keeps every digit as a string');
    }
}
