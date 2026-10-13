<?php

declare(strict_types=1);

namespace Nesto\Tests\Prisma\Client;

use DateTimeImmutable;
use Nesto\Prisma\Client\Errors\ForeignKeyConstraintError;
use Nesto\Prisma\Client\Errors\PrismaClientKnownRequestError;
use Nesto\Prisma\Client\Errors\PrismaClientValidationError;
use Nesto\Prisma\Client\Errors\RawQueryError;
use Nesto\Prisma\Client\Errors\RecordNotFoundError;
use Nesto\Prisma\Client\Errors\RequiredRelationViolationError;
use Nesto\Prisma\Client\Errors\TransactionError;
use Nesto\Prisma\Client\Errors\UniqueConstraintError;
use Nesto\Prisma\Client\Prisma;
use Nesto\Prisma\Client\PrismaClient;
use Nesto\Prisma\Migrate\DatabaseReset;
use Nesto\Prisma\Migrate\DatabaseUrl;
use Nesto\Prisma\Testing\PrismaTestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Prisma Client queries against a real database. SQLite always runs; PostgreSQL / MySQL / MariaDB run when
 * NESTO_TEST_PGSQL_URL / NESTO_TEST_MYSQL_URL / NESTO_TEST_MARIADB_URL point at a throwaway database
 * (name containing "test"). The schema is pushed once per class, tables are emptied before each test.
 */
abstract class PrismaClientTestCase extends TestCase
{
    /** @var array<class-string, list<string>> */
    private static array $dirs = [];
    /** @var array<class-string, PrismaClient> */
    private static array $clients = [];

    protected PrismaClient $prisma;

    abstract protected static function databaseUrl(): ?string;

    /** "postgresql" | "mysql" | "sqlite" */
    abstract protected static function provider(): string;

    protected static function urlFromEnv(string $var): ?string
    {
        $url = getenv($var);
        if ($url === false || trim($url) === '') {
            return null;
        }
        if (! str_contains(strtolower((string) parse_url($url, PHP_URL_PATH)), 'test')) {
            throw new \RuntimeException("$var must point at a throwaway database whose name contains \"test\".");
        }

        return trim($url);
    }

    protected static function isPostgres(): bool
    {
        return static::provider() === 'postgresql';
    }

    protected static function hasEnums(): bool
    {
        return static::provider() !== 'sqlite';
    }

    public static function schemaSource(string $url, string $extraDatasource = ''): string
    {
        $enum = static::hasEnums();
        $role = $enum ? 'Role      @default(USER)' : 'String    @default("USER")';
        $tags = static::isPostgres() ? "  tags       String[]\n" : '';

        return sprintf("datasource db {\n  provider = \"%s\"\n  url      = \"%s\"\n%s}\n\n", static::provider(), $url, $extraDatasource) . <<<PRISMA
            model User {
              id        Int       @id @default(autoincrement())
              email     String    @unique
              name      String?
              role      {$role}
              age       Int?
              balance   Decimal   @default(0)
              big       BigInt    @default(0)
              avatar    Bytes?
              settings  Json?
              createdAt DateTime  @default(now())
              updatedAt DateTime  @updatedAt
              posts     Post[]
              profile   Profile?
              comments  Comment[]

              @@map("users")
            }

            model Profile {
              id     String @id @default(uuid())
              bio    String @default("")
              user   User   @relation(fields: [userId], references: [id], onDelete: Cascade)
              userId Int    @unique
            }

            model Post {
              id         Int        @id @default(autoincrement())
              title      String
              views      Int        @default(0)
              rating     Float?
              published  Boolean    @default(false)
              meta       Json?
            {$tags}  author     User?      @relation(fields: [authorId], references: [id])
              authorId   Int?       @map("author_id")
              categories Category[]
              comments   Comment[]
              createdAt  DateTime   @default(now())

              @@unique([authorId, title])
            }

            model Category {
              id    Int    @id @default(autoincrement())
              name  String @unique
              posts Post[]
            }

            model Comment {
              id       String @id @default(cuid())
              body     String
              post     Post   @relation(fields: [postId], references: [id], onDelete: Cascade)
              postId   Int
              author   User?  @relation(fields: [authorId], references: [id], onDelete: SetNull)
              authorId Int?
            }

            model Membership {
              orgId     Int
              userEmail String
              level     Int    @default(1)

              @@id([orgId, userEmail])
            }

            model Employee {
              id        Int        @id @default(autoincrement())
              name      String
              managerId Int?
              manager   Employee?  @relation("Management", fields: [managerId], references: [id])
              reports   Employee[] @relation("Management")
              friends   Employee[] @relation("Friends")
              friendOf  Employee[] @relation("Friends")
            }

            PRISMA . ($enum ? "\nenum Role {\n  USER\n  ADMIN\n  MODERATOR @map(\"mod\")\n}\n" : '');
    }

    protected function setUp(): void
    {
        $url = static::databaseUrl();
        if ($url === null) {
            $this->markTestSkipped(static::class . ': database URL not configured.');
        }
        if (! isset(self::$clients[static::class])) {
            $dir = sys_get_temp_dir() . '/nesto_prisma_client_' . bin2hex(random_bytes(5));
            mkdir($dir . '/prisma', 0777, true);
            self::$dirs[static::class][] = $dir;
            if (static::provider() !== 'sqlite') {
                DatabaseReset::reset((new DatabaseUrl($url, static::provider()))->connectOrCreate());
            }
            file_put_contents($dir . '/prisma/schema.prisma', static::schemaSource($url));
            PrismaTestDatabase::push($dir . '/prisma/schema.prisma');
            self::$clients[static::class] = new PrismaClient(['schema' => $dir . '/prisma/schema.prisma']);
        }
        $this->prisma = self::$clients[static::class];
        PrismaTestDatabase::truncate($this->prisma);
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$clients[static::class])) {
            $connection = self::$clients[static::class]->connection();
            if (static::provider() !== 'sqlite') {
                DatabaseReset::reset($connection);
            }
            self::$clients[static::class]->disconnect();
            unset(self::$clients[static::class]);
        }
        foreach (self::$dirs[static::class] ?? [] as $dir) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
        unset(self::$dirs[static::class]);
    }

    // ---- fixtures --------------------------------------------------------------------------------

    /** ada (2 posts, 1 published), bob (1 post), cyd (no posts); categories php, js. */
    protected function seed(): void
    {
        $this->prisma->category->createMany(['data' => [['name' => 'php'], ['name' => 'js']]]);
        $this->prisma->user->create(['data' => [
            'email' => 'ada@example.com', 'name' => 'Ada', 'age' => 36,
            'posts' => ['create' => [
                ['title' => 'Hello PHP', 'views' => 10, 'rating' => 4.5, 'published' => true, 'categories' => ['connect' => [['name' => 'php']]]],
                ['title' => 'Draft', 'views' => 2],
            ]],
            'profile' => ['create' => ['bio' => 'Mathematician']],
        ]]);
        $this->prisma->user->create(['data' => [
            'email' => 'bob@example.com', 'name' => 'Bob', 'age' => 25,
            'posts' => ['create' => [['title' => 'JS tips', 'views' => 7, 'published' => true, 'categories' => ['connect' => [['name' => 'js'], ['name' => 'php']]]]]],
        ]]);
        $this->prisma->user->create(['data' => ['email' => 'cyd@example.com', 'age' => null]]);
    }

    /** @param list<array<string, mixed>> $records @return list<mixed> */
    protected static function pluck(array $records, string $field): array
    {
        return array_map(fn (array $r) => $r[$field], $records);
    }

    // ---- create / find ---------------------------------------------------------------------------

    public function testCreateFillsDefaultsAndMapsTypes(): void
    {
        $before = new DateTimeImmutable('-1 minute');
        $user = $this->prisma->user->create(['data' => [
            'email' => 'ada@example.com',
            'balance' => '1234.50',
            'big' => PHP_INT_MAX,
            'avatar' => "\x00\xffPNG\x00",
            'settings' => ['theme' => 'dark', 'sizes' => [1, 2.5], 'on' => true],
        ]]);
        self::assertSame(1, $user['id']);
        self::assertNull($user['name']);
        self::assertSame('USER', $user['role']);
        self::assertSame('1234.5', $user['balance']);
        self::assertSame(PHP_INT_MAX, $user['big']);
        self::assertSame("\x00\xffPNG\x00", $user['avatar']);
        self::assertEquals(['theme' => 'dark', 'sizes' => [1, 2.5], 'on' => true], $user['settings'], 'jsonb may reorder keys');
        self::assertSame([1, 2.5], $user['settings']['sizes']);
        self::assertInstanceOf(DateTimeImmutable::class, $user['createdAt']);
        self::assertSame('UTC', $user['createdAt']->getTimezone()->getName());
        self::assertGreaterThan($before, $user['createdAt']);
        self::assertInstanceOf(DateTimeImmutable::class, $user['updatedAt']);
        self::assertSame(['id', 'email', 'name', 'role', 'age', 'balance', 'big', 'avatar', 'settings', 'createdAt', 'updatedAt'], array_keys($user));

        $profile = $this->prisma->profile->create(['data' => ['user' => ['connect' => ['id' => 1]]]]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $profile['id']);
        self::assertSame('', $profile['bio']);

        $post = $this->prisma->post->create(['data' => ['title' => 'T', 'authorId' => 1]]);
        $comment = $this->prisma->comment->create(['data' => ['body' => 'nice', 'post' => ['connect' => ['id' => $post['id']]]]]);
        self::assertMatchesRegularExpression('/^c[0-9a-z]{24}$/', $comment['id']);

        $when = new DateTimeImmutable('2024-05-06 07:08:09.123', new \DateTimeZone('Europe/Berlin'));
        $updated = $this->prisma->user->update(['where' => ['id' => 1], 'data' => ['createdAt' => $when]]);
        self::assertSame('2024-05-06T05:08:09.123+00:00', $updated['createdAt']->format('Y-m-d\TH:i:s.vP'));
        self::assertGreaterThanOrEqual($user['updatedAt'], $updated['updatedAt']);

        $found = $this->prisma->user->findUnique(['where' => ['email' => 'ada@example.com']]);
        self::assertSame($updated['createdAt']->format('U.v'), $found['createdAt']->format('U.v'));
        self::assertNull($this->prisma->user->findUnique(['where' => ['email' => 'nobody@example.com']]));
    }

    public function testEnumsWithMappedValues(): void
    {
        if (! static::hasEnums()) {
            $user = $this->prisma->user->create(['data' => ['email' => 'm@example.com', 'role' => 'mod']]);
            self::assertSame('mod', $user['role']);

            return;
        }
        $user = $this->prisma->user->create(['data' => ['email' => 'm@example.com', 'role' => 'MODERATOR']]);
        self::assertSame('MODERATOR', $user['role']);
        $stored = $this->prisma->queryRawUnsafe('SELECT ' . $this->q('role') . ' AS r FROM ' . $this->q('users'));
        self::assertSame('mod', $stored[0]['r']);
        self::assertCount(1, $this->prisma->user->findMany(['where' => ['role' => ['in' => ['MODERATOR', 'ADMIN']]]]));
        $this->expectException(PrismaClientValidationError::class);
        $this->prisma->user->create(['data' => ['email' => 'x@example.com', 'role' => 'ROOT']]);
    }

    public function testScalarFilters(): void
    {
        $this->seed();
        $emails = fn (array $where) => self::pluck($this->prisma->user->findMany(['where' => $where, 'orderBy' => ['id' => 'asc']]), 'email');
        self::assertSame(['ada@example.com'], $emails(['name' => 'Ada']));
        self::assertSame(['ada@example.com'], $emails(['name' => ['equals' => 'Ada']]));
        self::assertSame(['bob@example.com'], $emails(['name' => ['not' => 'Ada']]), 'not excludes NULL like SQL');
        self::assertSame(['cyd@example.com'], $emails(['name' => null]));
        self::assertSame(['ada@example.com', 'bob@example.com'], $emails(['name' => ['not' => null]]));
        self::assertSame(['ada@example.com', 'cyd@example.com'], $emails(['email' => ['in' => ['ada@example.com', 'cyd@example.com', 'zed@example.com']]]));
        self::assertSame(['bob@example.com', 'cyd@example.com'], $emails(['email' => ['notIn' => ['ada@example.com']]]));
        self::assertSame([], $emails(['email' => ['in' => []]]));
        self::assertSame(['ada@example.com'], $emails(['age' => ['gt' => 30]]));
        self::assertSame(['ada@example.com', 'bob@example.com'], $emails(['age' => ['gte' => 25, 'lte' => 36]]));
        self::assertSame(['bob@example.com'], $emails(['age' => ['lt' => 30]]));
        self::assertSame(['bob@example.com'], $emails(['email' => ['startsWith' => 'bo']]));
        self::assertSame(['ada@example.com', 'bob@example.com', 'cyd@example.com'], $emails(['email' => ['endsWith' => '@example.com']]));
        self::assertSame(['cyd@example.com'], $emails(['email' => ['contains' => 'yd']]));
        self::assertSame(['ada@example.com'], $emails(['name' => ['contains' => 'DA', 'mode' => 'insensitive']]));
        self::assertSame(['ada@example.com'], $emails(['name' => ['equals' => 'ADA', 'mode' => 'insensitive']]));
        self::assertSame([], $emails(['email' => ['contains' => '%']]), 'LIKE wildcards are escaped');
        self::assertSame([], $emails(['email' => ['contains' => '_']]));
        self::assertSame(['bob@example.com'], $emails(['name' => ['not' => ['contains' => 'Ad']]]));
        self::assertSame(['ada@example.com', 'bob@example.com'], $emails(['OR' => [['name' => 'Ada'], ['age' => 25]]]));
        self::assertSame(['ada@example.com'], $emails(['AND' => [['age' => ['gt' => 20]], ['name' => ['startsWith' => 'A']]]]));
        self::assertSame(['bob@example.com', 'cyd@example.com'], $emails(['NOT' => ['name' => 'Ada']]), 'NOT keeps NULL rows (like Prisma)');
        self::assertSame(['cyd@example.com'], $emails(['NOT' => [['name' => 'Ada'], ['name' => 'Bob']]]));
        self::assertSame([], $emails(['OR' => []]));
        self::assertSame(['ada@example.com'], $emails(['createdAt' => ['lte' => new DateTimeImmutable('+1 minute')], 'id' => 1]));
        self::assertSame(['ada@example.com', 'bob@example.com'], $emails(['balance' => ['equals' => '0'], 'name' => ['not' => null]]));
    }

    public function testRelationFilters(): void
    {
        $this->seed();
        $users = fn (array $where) => self::pluck($this->prisma->user->findMany(['where' => $where, 'orderBy' => ['id' => 'asc']]), 'email');
        self::assertSame(['ada@example.com', 'bob@example.com'], $users(['posts' => ['some' => ['published' => true]]]));
        self::assertSame(['ada@example.com'], $users(['posts' => ['some' => ['published' => false]]]));
        self::assertSame(['bob@example.com', 'cyd@example.com'], $users(['posts' => ['every' => ['published' => true]]]), 'every is true without related rows');
        self::assertSame(['cyd@example.com'], $users(['posts' => ['none' => []]]));
        self::assertSame(['bob@example.com', 'cyd@example.com'], $users(['posts' => ['none' => ['title' => 'Draft']]]));
        self::assertSame(['ada@example.com'], $users(['profile' => ['is' => ['bio' => ['contains' => 'Math']]]]));
        self::assertSame(['bob@example.com', 'cyd@example.com'], $users(['profile' => null]));
        self::assertSame(['ada@example.com'], $users(['profile' => ['isNot' => null]]));
        self::assertSame(['bob@example.com'], $users(['posts' => ['some' => ['categories' => ['some' => ['name' => 'js']]]]]));

        $posts = fn (array $where) => self::pluck($this->prisma->post->findMany(['where' => $where, 'orderBy' => ['id' => 'asc']]), 'title');
        self::assertSame(['Hello PHP', 'Draft'], $posts(['author' => ['email' => 'ada@example.com']]));
        self::assertSame(['Hello PHP', 'Draft'], $posts(['author' => ['is' => ['name' => 'Ada']]]));
        self::assertSame(['JS tips'], $posts(['author' => ['isNot' => ['name' => 'Ada']]]));
        self::assertSame(['Hello PHP', 'JS tips'], $posts(['categories' => ['some' => ['name' => 'php']]]));
        self::assertSame(['JS tips'], $posts(['categories' => ['some' => ['name' => 'php']], 'AND' => ['categories' => ['some' => ['name' => 'js']]]]));
        self::assertSame(['Draft'], $posts(['categories' => ['none' => []]]));
        $this->prisma->post->create(['data' => ['title' => 'Orphan']]);
        self::assertSame(['Orphan'], $posts(['author' => null]));
        self::assertSame(['Orphan'], $posts(['authorId' => null]));
    }

    public function testUniqueWhereWithCompoundKeys(): void
    {
        $this->prisma->membership->createMany(['data' => [
            ['orgId' => 1, 'userEmail' => 'a@x.io', 'level' => 3],
            ['orgId' => 2, 'userEmail' => 'a@x.io'],
        ]]);
        $m = $this->prisma->membership->findUnique(['where' => ['orgId_userEmail' => ['orgId' => 1, 'userEmail' => 'a@x.io']]]);
        self::assertSame(['orgId' => 1, 'userEmail' => 'a@x.io', 'level' => 3], $m);
        $updated = $this->prisma->membership->update(['where' => ['orgId_userEmail' => ['orgId' => 2, 'userEmail' => 'a@x.io']], 'data' => ['level' => ['increment' => 4]]]);
        self::assertSame(5, $updated['level']);
        $this->prisma->membership->delete(['where' => ['orgId_userEmail' => ['orgId' => 1, 'userEmail' => 'a@x.io']]]);
        self::assertSame(1, $this->prisma->membership->count());

        $this->seed();
        $post = $this->prisma->post->findUnique(['where' => ['authorId_title' => ['authorId' => 1, 'title' => 'Draft']], 'select' => ['views' => true]]);
        self::assertSame(['views' => 2], $post);
        // extra non-unique filters next to the unique criterion (Prisma 5)
        self::assertNull($this->prisma->post->findUnique(['where' => ['id' => 1, 'published' => false]]));

        try {
            $this->prisma->user->findUnique(['where' => ['name' => 'Ada']]);
            self::fail('findUnique without a unique criterion must be refused');
        } catch (PrismaClientValidationError $e) {
            self::assertStringContainsString('UserWhereUniqueInput', $e->getMessage());
        }
    }

    public function testSelectIncludeOmitAndNestedReads(): void
    {
        $this->seed();
        $ada = $this->prisma->user->findUnique([
            'where' => ['email' => 'ada@example.com'],
            'select' => [
                'name' => true,
                'profile' => ['select' => ['bio' => true]],
                'posts' => ['where' => ['views' => ['gt' => 1]], 'orderBy' => ['views' => 'asc'], 'select' => ['title' => true, 'categories' => ['select' => ['name' => true]]]],
                '_count' => ['select' => ['posts' => true, 'comments' => true]],
            ],
        ]);
        self::assertSame([
            'name' => 'Ada',
            'profile' => ['bio' => 'Mathematician'],
            'posts' => [['title' => 'Draft', 'categories' => []], ['title' => 'Hello PHP', 'categories' => [['name' => 'php']]]],
            '_count' => ['posts' => 2, 'comments' => 0],
        ], $ada);

        $users = $this->prisma->user->findMany([
            'orderBy' => ['id' => 'asc'],
            'omit' => ['avatar' => true, 'settings' => true, 'createdAt' => true, 'updatedAt' => true, 'balance' => true, 'big' => true, 'role' => true],
            'include' => ['posts' => ['take' => 1, 'orderBy' => ['views' => 'desc'], 'select' => ['title' => true]], '_count' => true],
        ]);
        self::assertSame([
            ['id' => 1, 'email' => 'ada@example.com', 'name' => 'Ada', 'age' => 36, 'posts' => [['title' => 'Hello PHP']], '_count' => ['posts' => 2, 'comments' => 0]],
            ['id' => 2, 'email' => 'bob@example.com', 'name' => 'Bob', 'age' => 25, 'posts' => [['title' => 'JS tips']], '_count' => ['posts' => 1, 'comments' => 0]],
            ['id' => 3, 'email' => 'cyd@example.com', 'name' => null, 'age' => null, 'posts' => [], '_count' => ['posts' => 0, 'comments' => 0]],
        ], $users);

        // skip + take per parent, negative take
        $skipped = $this->prisma->user->findMany(['orderBy' => ['id' => 'asc'], 'select' => ['email' => true, 'posts' => ['orderBy' => ['id' => 'asc'], 'skip' => 1, 'select' => ['title' => true]]]]);
        self::assertSame([['title' => 'Draft']], $skipped[0]['posts']);
        self::assertSame([], $skipped[1]['posts']);
        $last = $this->prisma->user->findFirst(['where' => ['id' => 1], 'select' => ['posts' => ['orderBy' => ['id' => 'asc'], 'take' => -1, 'select' => ['title' => true]]]]);
        self::assertSame(['posts' => [['title' => 'Draft']]], $last);

        // to-one in both directions, many-to-many with nested filters and _count with where
        $posts = $this->prisma->post->findMany([
            'orderBy' => ['id' => 'asc'],
            'select' => ['title' => true, 'author' => ['select' => ['name' => true, 'profile' => true]], 'categories' => ['where' => ['name' => 'js']], '_count' => ['select' => ['categories' => ['where' => ['name' => ['startsWith' => 'p']]]]]],
        ]);
        self::assertSame('Ada', $posts[0]['author']['name']);
        self::assertSame('Mathematician', $posts[0]['author']['profile']['bio']);
        self::assertNull($posts[2]['author']['profile']);
        self::assertSame([], $posts[0]['categories']);
        self::assertSame([['id' => 2, 'name' => 'js']], $posts[2]['categories']);
        self::assertSame([1, 0, 1], array_map(fn ($p) => $p['_count']['categories'], $posts));

        try {
            $this->prisma->user->findMany(['select' => ['id' => true], 'include' => ['posts' => true]]);
            self::fail('select + include must be refused');
        } catch (PrismaClientValidationError $e) {
            self::assertStringContainsString('either use `include` or `select`', $e->getMessage());
        }
        $this->expectException(PrismaClientValidationError::class);
        $this->prisma->user->findMany(['select' => ['password' => true]]);
    }

    public function testOrderBy(): void
    {
        $this->seed();
        $titles = fn (mixed $orderBy) => self::pluck($this->prisma->post->findMany(['orderBy' => $orderBy]), 'title');
        self::assertSame(['Draft', 'Hello PHP', 'JS tips'], $titles(['title' => 'asc']));
        self::assertSame(['Hello PHP', 'JS tips', 'Draft'], $titles(['views' => 'desc']));
        self::assertSame(['JS tips', 'Hello PHP', 'Draft'], $titles([['published' => 'desc'], ['author' => ['name' => 'desc']], ['id' => 'asc']]));
        self::assertSame(['JS tips', 'Hello PHP', 'Draft'], $titles([['categories' => ['_count' => 'desc']], ['id' => 'asc']]));
        self::assertSame(['Draft', 'JS tips', 'Hello PHP'], $titles([['rating' => ['sort' => 'asc', 'nulls' => 'first']], ['id' => 'asc']]));
        self::assertSame(['Hello PHP', 'Draft', 'JS tips'], $titles([['rating' => ['sort' => 'asc', 'nulls' => 'last']], ['id' => 'asc']]));
        $users = self::pluck($this->prisma->user->findMany(['orderBy' => [['posts' => ['_count' => 'desc']], ['id' => 'asc']]]), 'email');
        self::assertSame(['ada@example.com', 'bob@example.com', 'cyd@example.com'], $users);
        $byProfile = self::pluck($this->prisma->user->findMany(['orderBy' => [['profile' => ['bio' => ['sort' => 'asc', 'nulls' => 'last']]], ['id' => 'desc']]]), 'email');
        self::assertSame(['ada@example.com', 'cyd@example.com', 'bob@example.com'], $byProfile);
    }

    public function testPaginationCursorDistinct(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->prisma->post->create(['data' => ['title' => 'P' . $i, 'views' => intdiv($i, 2)]]);
        }
        $ids = fn (array $args) => self::pluck($this->prisma->post->findMany($args + ['select' => ['id' => true]]), 'id');
        self::assertSame([3, 4], $ids(['orderBy' => ['id' => 'asc'], 'skip' => 2, 'take' => 2]));
        self::assertSame([5, 6], $ids(['orderBy' => ['id' => 'asc'], 'take' => -2]));
        self::assertSame([3, 4, 5, 6], $ids(['orderBy' => ['id' => 'asc'], 'skip' => 2]));
        self::assertSame([4, 5], $ids(['orderBy' => ['id' => 'asc'], 'cursor' => ['id' => 3], 'skip' => 1, 'take' => 2]));
        self::assertSame([3, 4], $ids(['orderBy' => ['id' => 'asc'], 'cursor' => ['id' => 3], 'take' => 2]));
        self::assertSame([2, 3], $ids(['orderBy' => ['id' => 'asc'], 'cursor' => ['id' => 3], 'take' => -2]));
        self::assertSame([6, 4, 5], $ids(['orderBy' => [['views' => 'desc'], ['id' => 'asc']], 'cursor' => ['id' => 6], 'take' => 3]));
        self::assertSame([4, 5, 2], $ids(['orderBy' => ['views' => 'desc'], 'cursor' => ['id' => 4], 'take' => 3]), 'the identity breaks ties');
        self::assertSame([], $ids(['cursor' => ['id' => 99], 'take' => 2]));
        $distinct = $this->prisma->post->findMany(['distinct' => ['views'], 'orderBy' => ['id' => 'asc'], 'select' => ['views' => true]]);
        self::assertSame([0, 1, 2, 3], self::pluck($distinct, 'views'));
        self::assertSame([1, 2], self::pluck($this->prisma->post->findMany(['distinct' => 'views', 'orderBy' => ['id' => 'asc'], 'skip' => 1, 'take' => 2]), 'views'));
        $first = $this->prisma->post->findFirst(['orderBy' => ['id' => 'desc']]);
        self::assertSame('P6', $first['title']);
        self::assertSame('P5', $this->prisma->post->findFirst(['where' => ['views' => 2], 'orderBy' => ['id' => 'desc']])['title']);
    }

    // ---- writes ----------------------------------------------------------------------------------

    public function testNestedCreates(): void
    {
        $this->prisma->category->create(['data' => ['name' => 'php']]);
        $user = $this->prisma->user->create([
            'data' => [
                'email' => 'ada@example.com',
                'profile' => ['create' => ['bio' => 'hi']],
                'posts' => [
                    'create' => [
                        ['title' => 'A', 'categories' => ['connectOrCreate' => [['where' => ['name' => 'php'], 'create' => ['name' => 'php']], ['where' => ['name' => 'go'], 'create' => ['name' => 'go']]]]],
                        ['title' => 'B', 'comments' => ['create' => [['body' => 'first']]]],
                    ],
                    'createMany' => ['data' => [['title' => 'C'], ['title' => 'D', 'views' => 3]]],
                ],
            ],
            'include' => ['profile' => true, 'posts' => ['orderBy' => ['title' => 'asc'], 'include' => ['categories' => ['orderBy' => ['name' => 'asc']], 'comments' => true]]],
        ]);
        self::assertSame('hi', $user['profile']['bio']);
        self::assertSame(['A', 'B', 'C', 'D'], self::pluck($user['posts'], 'title'));
        self::assertSame(['go', 'php'], self::pluck($user['posts'][0]['categories'], 'name'));
        self::assertSame('first', $user['posts'][1]['comments'][0]['body']);
        self::assertSame(2, $this->prisma->category->count());

        // owner side: create / connect / connectOrCreate the record holding the referenced key
        $post = $this->prisma->post->create(['data' => ['title' => 'E', 'author' => ['create' => ['email' => 'bob@example.com']]], 'include' => ['author' => true]]);
        self::assertSame('bob@example.com', $post['author']['email']);
        $post = $this->prisma->post->create(['data' => ['title' => 'F', 'author' => ['connect' => ['email' => 'ada@example.com']]], 'select' => ['authorId' => true]]);
        self::assertSame(['authorId' => 1], $post);
        $post = $this->prisma->post->create(['data' => ['title' => 'G', 'author' => ['connectOrCreate' => ['where' => ['email' => 'cyd@example.com'], 'create' => ['email' => 'cyd@example.com', 'name' => 'Cyd']]]], 'select' => ['author' => ['select' => ['name' => true]]]]);
        self::assertSame(['author' => ['name' => 'Cyd']], $post);
        $comment = $this->prisma->comment->create(['data' => ['body' => 'x', 'post' => ['create' => ['title' => 'H', 'author' => ['connect' => ['id' => 1]]]]], 'include' => ['post' => ['include' => ['author' => ['select' => ['email' => true]]]]]]);
        self::assertSame('ada@example.com', $comment['post']['author']['email']);

        // self relations
        $boss = $this->prisma->employee->create(['data' => ['name' => 'Boss', 'reports' => ['create' => [['name' => 'Ann'], ['name' => 'Ben']]], 'friends' => ['create' => [['name' => 'Pal']]]], 'include' => ['reports' => ['orderBy' => ['name' => 'asc']], 'friends' => true, 'friendOf' => true]]);
        self::assertSame(['Ann', 'Ben'], self::pluck($boss['reports'], 'name'));
        self::assertSame(['Pal'], self::pluck($boss['friends'], 'name'));
        self::assertSame([], $boss['friendOf']);
        $pal = $this->prisma->employee->findFirst(['where' => ['name' => 'Pal'], 'include' => ['friendOf' => true, 'manager' => true]]);
        self::assertSame(['Boss'], self::pluck($pal['friendOf'], 'name'));
        self::assertNull($pal['manager']);
        $ann = $this->prisma->employee->findFirst(['where' => ['name' => 'Ann'], 'include' => ['manager' => true]]);
        self::assertSame('Boss', $ann['manager']['name']);

        try {
            $this->prisma->post->create(['data' => ['title' => 'Z', 'author' => ['connect' => ['email' => 'nobody@example.com']]]]);
            self::fail('connecting a missing record must fail');
        } catch (RecordNotFoundError $e) {
            self::assertSame('P2025', $e->getCode());
            self::assertStringContainsString("No 'User' record(s)", $e->getMessage());
        }
        try {
            $this->prisma->comment->create(['data' => ['body' => 'no post']]);
            self::fail('a required relation is required');
        } catch (PrismaClientValidationError $e) {
            self::assertStringContainsString('Argument `post` is missing', $e->getMessage());
        }
        self::assertSame(8, $this->prisma->post->count(), 'failed creates were rolled back');
    }

    public function testNestedUpdatesOnEveryRelationKind(): void
    {
        $this->seed();
        // to-many: create, connect, disconnect, update, updateMany, upsert, delete, deleteMany
        $this->prisma->post->create(['data' => ['title' => 'Loose']]);
        $ada = $this->prisma->user->update([
            'where' => ['email' => 'ada@example.com'],
            'data' => [
                'name' => 'Ada L.',
                'posts' => [
                    'create' => ['title' => 'New'],
                    'connect' => ['id' => 4],
                    'update' => ['where' => ['id' => 1], 'data' => ['views' => ['increment' => 5]]],
                    'updateMany' => ['where' => ['published' => false], 'data' => ['rating' => 1.5]],
                    'upsert' => ['where' => ['id' => 2], 'create' => ['title' => 'never'], 'update' => ['title' => 'Draft v2']],
                ],
            ],
            'include' => ['posts' => ['orderBy' => ['id' => 'asc']]],
        ]);
        self::assertSame('Ada L.', $ada['name']);
        self::assertSame(['Hello PHP', 'Draft v2', 'Loose', 'New'], self::pluck($ada['posts'], 'title'));
        self::assertSame(15, $ada['posts'][0]['views']);
        self::assertSame([4.5, 1.5, 1.5, 1.5], self::pluck($ada['posts'], 'rating'));

        $ada = $this->prisma->user->update([
            'where' => ['id' => 1],
            'data' => ['posts' => ['disconnect' => [['id' => 4]], 'delete' => ['id' => 5], 'deleteMany' => ['title' => ['startsWith' => 'Draft']]]],
            'include' => ['posts' => true],
        ]);
        self::assertSame(['Hello PHP'], self::pluck($ada['posts'], 'title'));
        self::assertNull($this->prisma->post->findUnique(['where' => ['id' => 4]])['authorId']);
        self::assertNull($this->prisma->post->findUnique(['where' => ['id' => 5]]));

        // set replaces the whole list
        $bob = $this->prisma->user->update(['where' => ['id' => 2], 'data' => ['posts' => ['set' => [['id' => 4], ['id' => 1]]]], 'include' => ['posts' => ['orderBy' => ['id' => 'asc']]]]);
        self::assertSame([1, 4], self::pluck($bob['posts'], 'id'));
        self::assertSame(0, $this->prisma->post->count(['where' => ['authorId' => 1]]));

        // many-to-many: connect, disconnect, set, create, update, delete
        $post = $this->prisma->post->update([
            'where' => ['id' => 4],
            'data' => ['categories' => ['connect' => [['name' => 'php'], ['name' => 'js']], 'create' => ['name' => 'go']]],
            'include' => ['categories' => ['orderBy' => ['name' => 'asc']]],
        ]);
        self::assertSame(['go', 'js', 'php'], self::pluck($post['categories'], 'name'));
        $post = $this->prisma->post->update(['where' => ['id' => 4], 'data' => ['categories' => ['connect' => ['name' => 'js']]], 'include' => ['_count' => true]]);
        self::assertSame(3, $post['_count']['categories'], 'connecting twice keeps one link');
        $post = $this->prisma->post->update([
            'where' => ['id' => 4],
            'data' => ['categories' => ['disconnect' => ['name' => 'js'], 'update' => ['where' => ['name' => 'go'], 'data' => ['name' => 'golang']]]],
            'include' => ['categories' => ['orderBy' => ['name' => 'asc']]],
        ]);
        self::assertSame(['golang', 'php'], self::pluck($post['categories'], 'name'));
        self::assertNotNull($this->prisma->category->findUnique(['where' => ['name' => 'js']]), 'disconnect keeps the record');
        $post = $this->prisma->post->update(['where' => ['id' => 4], 'data' => ['categories' => ['set' => [['name' => 'js']]]], 'include' => ['categories' => true]]);
        self::assertSame(['js'], self::pluck($post['categories'], 'name'));
        $post = $this->prisma->post->update(['where' => ['id' => 3], 'data' => ['categories' => ['delete' => ['name' => 'php']]], 'include' => ['categories' => true]]);
        self::assertSame(['js'], self::pluck($post['categories'], 'name'));
        self::assertNull($this->prisma->category->findUnique(['where' => ['name' => 'php']]));

        // to-one, foreign key on the other side (User.profile)
        $bob = $this->prisma->user->update(['where' => ['id' => 2], 'data' => ['profile' => ['create' => ['bio' => 'Builder']]], 'include' => ['profile' => true]]);
        self::assertSame('Builder', $bob['profile']['bio']);
        $bob = $this->prisma->user->update(['where' => ['id' => 2], 'data' => ['profile' => ['update' => ['bio' => 'Bob the builder']]], 'include' => ['profile' => true]]);
        self::assertSame('Bob the builder', $bob['profile']['bio']);
        $bob = $this->prisma->user->update(['where' => ['id' => 2], 'data' => ['profile' => ['upsert' => ['create' => ['bio' => 'x'], 'update' => ['bio' => 'Upserted']]]], 'include' => ['profile' => true]]);
        self::assertSame('Upserted', $bob['profile']['bio']);
        $bob = $this->prisma->user->update(['where' => ['id' => 2], 'data' => ['profile' => ['delete' => true]], 'include' => ['profile' => true]]);
        self::assertNull($bob['profile']);
        try {
            $this->prisma->user->update(['where' => ['id' => 1], 'data' => ['profile' => ['disconnect' => true]]]);
            self::fail('the profile cannot exist without its user');
        } catch (RequiredRelationViolationError $e) {
            self::assertSame('P2014', $e->getCode());
        }

        // to-one, foreign key on this side (Post.author)
        $post = $this->prisma->post->update(['where' => ['id' => 4], 'data' => ['author' => ['disconnect' => true]], 'include' => ['author' => true]]);
        self::assertNull($post['author']);
        $post = $this->prisma->post->update(['where' => ['id' => 4], 'data' => ['author' => ['connect' => ['email' => 'cyd@example.com']]], 'include' => ['author' => true]]);
        self::assertSame('cyd@example.com', $post['author']['email']);
        $post = $this->prisma->post->update(['where' => ['id' => 4], 'data' => ['author' => ['update' => ['name' => 'Cyd']]], 'include' => ['author' => true]]);
        self::assertSame('Cyd', $post['author']['name']);
        $post = $this->prisma->post->update(['where' => ['id' => 4], 'data' => ['author' => ['create' => ['email' => 'dan@example.com']]], 'include' => ['author' => true]]);
        self::assertSame('dan@example.com', $post['author']['email']);
        $post = $this->prisma->post->update(['where' => ['id' => 4], 'data' => ['author' => ['delete' => true]], 'include' => ['author' => true]]);
        self::assertNull($post['author']);
        self::assertNull($this->prisma->user->findUnique(['where' => ['email' => 'dan@example.com']]));
        try {
            $this->prisma->post->update(['where' => ['id' => 4], 'data' => ['author' => ['update' => ['name' => 'nobody']]]]);
            self::fail('no author to update');
        } catch (RecordNotFoundError $e) {
            self::assertStringContainsString("No 'User' record was found for a nested update", $e->getMessage());
        }
    }

    public function testAtomicNumberOperations(): void
    {
        $post = $this->prisma->post->create(['data' => ['title' => 'n', 'views' => 10, 'rating' => 2.0]]);
        $user = $this->prisma->user->create(['data' => ['email' => 'n@example.com', 'balance' => '10.25', 'big' => 5]]);
        $post = $this->prisma->post->update(['where' => ['id' => $post['id']], 'data' => ['views' => ['increment' => 5], 'rating' => ['multiply' => 1.5]]]);
        self::assertSame(15, $post['views']);
        self::assertSame(3.0, $post['rating']);
        $post = $this->prisma->post->update(['where' => ['id' => $post['id']], 'data' => ['views' => ['divide' => 2], 'rating' => ['decrement' => 0.5]]]);
        self::assertSame(7, $post['views'], 'integer division');
        self::assertSame(2.5, $post['rating']);
        $post = $this->prisma->post->update(['where' => ['id' => $post['id']], 'data' => ['views' => ['set' => 1], 'rating' => ['set' => null]]]);
        self::assertSame(1, $post['views']);
        self::assertNull($post['rating']);
        $user = $this->prisma->user->update(['where' => ['id' => $user['id']], 'data' => ['balance' => ['increment' => '0.10'], 'big' => ['multiply' => 3]]]);
        self::assertSame('10.35', $user['balance']);
        self::assertSame(15, $user['big']);
        self::assertSame(['count' => 1], $this->prisma->post->updateMany(['where' => [], 'data' => ['views' => ['increment' => 1]]]));
        self::assertSame(2, $this->prisma->post->findFirst()['views']);
        $this->expectException(PrismaClientValidationError::class);
        $this->prisma->user->update(['where' => ['id' => $user['id']], 'data' => ['email' => ['increment' => 1]]]);
    }

    public function testUpsertUpdateManyDeleteMany(): void
    {
        $created = $this->prisma->user->upsert(['where' => ['email' => 'u@example.com'], 'create' => ['email' => 'u@example.com', 'name' => 'New'], 'update' => ['name' => 'Updated']]);
        self::assertSame('New', $created['name']);
        $updated = $this->prisma->user->upsert(['where' => ['email' => 'u@example.com'], 'create' => ['email' => 'u@example.com', 'name' => 'New'], 'update' => ['name' => 'Updated'], 'select' => ['name' => true]]);
        self::assertSame(['name' => 'Updated'], $updated);
        $this->seed();
        self::assertSame(['count' => 2], $this->prisma->post->updateMany(['where' => ['published' => true], 'data' => ['views' => 0]]));
        self::assertSame(2, $this->prisma->post->count(['where' => ['views' => 0]]));
        self::assertSame(['count' => 1], $this->prisma->post->updateMany(['where' => ['author' => ['is' => ['email' => 'ada@example.com']], 'published' => false], 'data' => ['title' => 'Edited']]));
        self::assertSame(['count' => 0], $this->prisma->post->updateMany(['where' => ['id' => 999], 'data' => ['title' => 'x']]));
        self::assertSame(['count' => 1], $this->prisma->post->deleteMany(['where' => ['title' => 'Edited']]));
        $deleted = $this->prisma->post->delete(['where' => ['id' => 3], 'include' => ['author' => ['select' => ['email' => true]]]]);
        self::assertSame('bob@example.com', $deleted['author']['email']);
        self::assertSame(['count' => 1], $this->prisma->post->deleteMany());
        self::assertSame(0, $this->prisma->post->count());
        // cascades through foreign keys: deleting a user deletes its profile
        $this->prisma->user->delete(['where' => ['email' => 'ada@example.com']]);
        self::assertSame(0, $this->prisma->profile->count());
    }

    public function testCreateManySkipDuplicatesAndReturn(): void
    {
        self::assertSame(['count' => 3], $this->prisma->category->createMany(['data' => [['name' => 'a'], ['name' => 'b'], ['name' => 'c']]]));
        self::assertSame(['count' => 1], $this->prisma->category->createMany(['data' => [['name' => 'a'], ['name' => 'd']], 'skipDuplicates' => true]));
        try {
            $this->prisma->category->createMany(['data' => [['name' => 'e'], ['name' => 'a']]]);
            self::fail('duplicates without skipDuplicates must fail');
        } catch (UniqueConstraintError) {
        }
        self::assertSame(4, $this->prisma->category->count(), 'the failed createMany was rolled back');
        $comments = $this->prisma->post->create(['data' => ['title' => 'p']]);
        $this->prisma->comment->createMany(['data' => [['body' => 'x', 'postId' => $comments['id']], ['body' => 'y', 'postId' => $comments['id']]]]);
        $ids = self::pluck($this->prisma->comment->findMany(), 'id');
        self::assertCount(2, array_unique($ids), 'cuid() per row');

        if (in_array(static::provider(), ['postgresql', 'sqlite'], true)) {
            $returned = $this->prisma->category->createManyAndReturn(['data' => [['name' => 'x'], ['name' => 'y']], 'select' => ['name' => true]]);
            self::assertSame([['name' => 'x'], ['name' => 'y']], $returned);
            $updated = $this->prisma->category->updateManyAndReturn(['where' => ['name' => ['in' => ['x', 'y']]], 'data' => ['name' => ['set' => 'z']], 'limit' => 1]);
            self::assertCount(1, $updated);
        } else {
            $this->expectException(PrismaClientValidationError::class);
            $this->prisma->category->createManyAndReturn(['data' => [['name' => 'x']]]);
        }
    }

    // ---- aggregates ------------------------------------------------------------------------------

    public function testCountAggregateGroupBy(): void
    {
        $this->seed();
        self::assertSame(3, $this->prisma->user->count());
        self::assertSame(2, $this->prisma->user->count(['where' => ['age' => ['not' => null]]]));
        self::assertSame(['_all' => 3, 'age' => 2, 'name' => 2], $this->prisma->user->count(['select' => ['_all' => true, 'age' => true, 'name' => true]]));
        self::assertSame(1, $this->prisma->user->count(['orderBy' => ['id' => 'asc'], 'skip' => 2, 'take' => 5]));

        $aggregate = $this->prisma->post->aggregate([
            '_count' => ['_all' => true, 'rating' => true],
            '_sum' => ['views' => true],
            '_avg' => ['views' => true, 'rating' => true],
            '_min' => ['views' => true, 'title' => true],
            '_max' => ['views' => true, 'createdAt' => true],
        ]);
        self::assertSame(['_all' => 3, 'rating' => 1], $aggregate['_count']);
        self::assertSame(['views' => 19], $aggregate['_sum']);
        self::assertEqualsWithDelta(19 / 3, $aggregate['_avg']['views'], 0.0001);
        self::assertSame(4.5, $aggregate['_avg']['rating']);
        self::assertSame(['views' => 2, 'title' => 'Draft'], $aggregate['_min']);
        self::assertSame(10, $aggregate['_max']['views']);
        self::assertInstanceOf(DateTimeImmutable::class, $aggregate['_max']['createdAt']);

        $windowed = $this->prisma->post->aggregate(['orderBy' => ['views' => 'desc'], 'take' => 2, '_sum' => ['views' => true], '_count' => true]);
        self::assertSame(['_sum' => ['views' => 17], '_count' => 2], $windowed);
        $empty = $this->prisma->post->aggregate(['where' => ['id' => 99], '_sum' => ['views' => true], '_avg' => ['views' => true], '_count' => true]);
        self::assertSame(['_sum' => ['views' => null], '_avg' => ['views' => null], '_count' => 0], $empty);
        $money = $this->prisma->user->aggregate(['_sum' => ['balance' => true]]);
        self::assertSame('0', $money['_sum']['balance']);

        $groups = $this->prisma->post->groupBy([
            'by' => ['authorId'],
            '_count' => ['_all' => true],
            '_sum' => ['views' => true],
            '_max' => ['views' => true],
            'orderBy' => ['authorId' => 'asc'],
        ]);
        self::assertSame([
            ['authorId' => 1, '_count' => ['_all' => 2], '_sum' => ['views' => 12], '_max' => ['views' => 10]],
            ['authorId' => 2, '_count' => ['_all' => 1], '_sum' => ['views' => 7], '_max' => ['views' => 7]],
        ], $groups);
        $having = $this->prisma->post->groupBy(['by' => ['authorId', 'published'], '_count' => true, 'having' => ['views' => ['_sum' => ['gt' => 5]]], 'orderBy' => [['_sum' => ['views' => 'desc']]]]);
        self::assertSame([
            ['authorId' => 1, 'published' => true, '_count' => 1],
            ['authorId' => 2, 'published' => true, '_count' => 1],
        ], $having);
        $filtered = $this->prisma->post->groupBy(['by' => 'published', 'where' => ['authorId' => 1], '_avg' => ['views' => true], 'having' => ['published' => false]]);
        self::assertSame([['published' => false, '_avg' => ['views' => 2.0]]], $filtered);
        $paged = $this->prisma->user->groupBy(['by' => ['name'], '_count' => ['_all' => true], 'orderBy' => ['name' => 'asc'], 'skip' => 1, 'take' => 1, 'where' => ['name' => ['not' => null]]]);
        self::assertSame([['name' => 'Bob', '_count' => ['_all' => 1]]], $paged);
    }

    public function testAggregateResultTypesAreTheSameOnEveryDatabase(): void
    {
        $this->seed();
        $this->prisma->post->update(['where' => ['id' => 2], 'data' => ['rating' => 1.25]]);
        $this->prisma->user->update(['where' => ['id' => 1], 'data' => ['balance' => '10.25', 'big' => 3]]);
        $this->prisma->user->update(['where' => ['id' => 2], 'data' => ['balance' => '0.5', 'big' => 4]]);
        $this->prisma->user->update(['where' => ['id' => 3], 'data' => ['balance' => '3', 'big' => 4]]);
        $expected = [
            '_avg' => ['views' => 19 / 3, 'rating' => 2.875],
            '_sum' => ['views' => 19, 'rating' => 5.75],
        ];
        $posts = $this->prisma->post->aggregate(['_avg' => ['views' => true, 'rating' => true], '_sum' => ['views' => true, 'rating' => true]]);
        self::assertSame($expected, $posts, 'Int / Float _avg → float with full precision, _sum keeps the field type');
        $windowed = $this->prisma->post->aggregate(['orderBy' => ['id' => 'asc'], 'take' => 3, '_avg' => ['views' => true, 'rating' => true], '_sum' => ['views' => true, 'rating' => true]]);
        self::assertSame($expected, $windowed, 'the same types when the rows are aggregated in PHP');

        $users = $this->prisma->user->aggregate(['_avg' => ['balance' => true, 'big' => true, 'age' => true], '_sum' => ['balance' => true, 'big' => true, 'age' => true]]);
        self::assertSame([
            '_avg' => ['balance' => '4.583333333333333333333333333333', 'big' => 11 / 3, 'age' => 30.5],
            '_sum' => ['balance' => '13.75', 'big' => 11, 'age' => 61],
        ], $users, 'Decimal _avg: a Decimal string with the scale of the column (DECIMAL(65,30)), BigInt _avg: float');
        $windowedUsers = $this->prisma->user->aggregate(['skip' => 0, 'take' => 10, '_avg' => ['balance' => true, 'big' => true, 'age' => true], '_sum' => ['balance' => true, 'big' => true, 'age' => true]]);
        self::assertSame($users, $windowedUsers);

        $groups = $this->prisma->post->groupBy(['by' => ['authorId'], '_avg' => ['views' => true], 'orderBy' => ['authorId' => 'asc']]);
        self::assertSame([['authorId' => 1, '_avg' => ['views' => 6.0]], ['authorId' => 2, '_avg' => ['views' => 7.0]]], $groups);
        $this->prisma->post->create(['data' => ['title' => 'Third', 'views' => 1, 'authorId' => 1]]);
        $groups = $this->prisma->post->groupBy(['by' => ['authorId'], '_avg' => ['views' => true], '_sum' => ['views' => true], 'where' => ['authorId' => 1]]);
        self::assertSame([['authorId' => 1, '_avg' => ['views' => 13 / 3], '_sum' => ['views' => 13]]], $groups);
        $balances = $this->prisma->user->groupBy(['by' => ['role'], '_avg' => ['balance' => true], '_sum' => ['balance' => true]]);
        self::assertSame('4.583333333333333333333333333333', $balances[0]['_avg']['balance']);
        self::assertSame('13.75', $balances[0]['_sum']['balance']);
    }

    public function testTheTestDatabaseHelpersReadTheConnectedDatabase(): void
    {
        $name = PrismaTestDatabase::databaseName($this->prisma->connection());
        if (static::provider() === 'sqlite') {
            self::assertStringEndsWith('/test.db', $name);
        } else {
            self::assertSame((new DatabaseUrl((string) static::databaseUrl(), static::provider()))->database(), $name);
        }
        self::assertTrue(PrismaTestDatabase::isTestDatabase($name));
        self::assertFalse(PrismaTestDatabase::isTestDatabase('app'));
        self::assertFalse(PrismaTestDatabase::isTestDatabase('/tmp/tests/dev.db'), 'SQLite: the file name counts, not the folder');
        self::assertTrue(PrismaTestDatabase::isTestDatabase(':memory:'));
    }

    public function testWarnEvents(): void
    {
        $this->seed();
        $warnings = [];
        $stream = fopen('php://memory', 'w+');
        $source = "generator client {\n  provider        = \"nesto-client\"\n  previewFeatures = [\"interactiveTransactions\"]\n}\n\n"
            . (string) file_get_contents(end(self::$dirs[static::class]) . '/prisma/schema.prisma');
        $client = new PrismaClient([
            'document' => \Nesto\Prisma\Schema\SchemaLoader::load($source),
            'schemaDir' => end(self::$dirs[static::class]) . '/prisma',
            'log' => ['warn'],
            'logStream' => $stream,
        ]);
        $client->on('warn', function (array $event) use (&$warnings): void {
            $warnings[] = $event;
        });
        $client->user->findMany(['include' => ['posts' => true], 'relationLoadStrategy' => 'query']);
        self::assertCount(1, $warnings, 'the deprecated preview feature, when the client starts');
        self::assertStringContainsString('Preview feature "interactiveTransactions" is deprecated', $warnings[0]['message']);
        self::assertInstanceOf(DateTimeImmutable::class, $warnings[0]['timestamp']);
        $users = $client->user->findMany(['include' => ['posts' => true], 'relationLoadStrategy' => 'join', 'orderBy' => ['id' => 'asc']]);
        self::assertCount(2, $users[0]['posts'], 'the query strategy is used');
        self::assertCount(2, $warnings);
        self::assertStringContainsString("relationLoadStrategy: 'join'", $warnings[1]['message']);
        self::assertSame('prisma.user.findMany', $warnings[1]['target']);
        $client->user->findUnique(['where' => ['id' => 1], 'relationLoadStrategy' => 'join']);
        self::assertCount(3, $warnings);
        rewind($stream);
        $log = (string) stream_get_contents($stream);
        self::assertStringStartsWith('prisma:warn Preview feature "interactiveTransactions" is deprecated', $log);
        self::assertSame(3, substr_count($log, 'prisma:warn '));
        try {
            $client->user->findMany(['relationLoadStrategy' => 'lateral']);
            self::fail('unknown strategy');
        } catch (PrismaClientValidationError $e) {
            self::assertStringContainsString('relationLoadStrategy', $e->getMessage());
        }
        $client->disconnect();
    }

    public function testJsonFilters(): void
    {
        $this->prisma->post->createMany(['data' => [
            ['title' => 'a', 'meta' => ['kind' => 'news', 'score' => 7, 'tags' => ['php', 'db'], 'author' => ['name' => 'Ada']]],
            ['title' => 'b', 'meta' => ['kind' => 'blog', 'score' => 3, 'tags' => ['js']]],
            ['title' => 'c', 'meta' => Prisma::JsonNull],
            ['title' => 'd'],
        ]]);
        $titles = fn (array $filter) => self::pluck($this->prisma->post->findMany(['where' => ['meta' => $filter], 'orderBy' => ['id' => 'asc']]), 'title');
        self::assertSame(['b'], $titles(['equals' => ['kind' => 'blog', 'score' => 3, 'tags' => ['js']]]));
        self::assertSame(['a', 'c'], $titles(['not' => ['kind' => 'blog', 'score' => 3, 'tags' => ['js']]]));
        self::assertSame(['d'], $titles(['equals' => Prisma::DbNull]));
        self::assertSame(['c'], $titles(['equals' => Prisma::JsonNull]));
        self::assertSame(['c', 'd'], $titles(['equals' => Prisma::AnyNull]));
        self::assertSame(['a'], $titles(['path' => ['kind'], 'equals' => 'news']));
        self::assertSame(['a'], $titles(['path' => '$.author.name', 'equals' => 'Ada']));
        self::assertSame(['a'], $titles(['path' => ['author', 'name'], 'string_starts_with' => 'Ad']));
        self::assertSame(['b'], $titles(['path' => ['kind'], 'string_contains' => 'lo']));
        self::assertSame(['a'], $titles(['path' => ['kind'], 'string_ends_with' => 'ws']));
        self::assertSame(['a'], $titles(['path' => ['score'], 'gt' => 5]));
        self::assertSame(['a', 'b'], $titles(['path' => ['score'], 'gte' => 3]));
        self::assertSame(['a'], $titles(['path' => ['tags'], 'array_contains' => ['php']]));
        self::assertSame(['a'], $titles(['path' => ['tags'], 'array_contains' => 'db']));
        self::assertSame(['b'], $titles(['path' => ['tags'], 'array_starts_with' => 'js']));
        self::assertSame(['a'], $titles(['path' => ['tags'], 'array_ends_with' => 'db']));
        $post = $this->prisma->post->findFirst(['where' => ['title' => 'c']]);
        self::assertNull($post['meta']);
    }

    // ---- errors ----------------------------------------------------------------------------------

    public function testPrismaErrors(): void
    {
        $this->seed();
        try {
            $this->prisma->user->create(['data' => ['email' => 'ada@example.com']]);
            self::fail('duplicate email');
        } catch (UniqueConstraintError $e) {
            self::assertInstanceOf(PrismaClientKnownRequestError::class, $e);
            self::assertSame('P2002', $e->getCode());
            self::assertSame(['email'], $e->meta['target']);
            self::assertSame('User', $e->meta['modelName']);
            self::assertStringContainsString('Unique constraint failed on the fields: (`email`)', $e->getMessage());
            self::assertStringContainsString('Invalid `prisma.user.create()` invocation', $e->getMessage());
        }
        try {
            $this->prisma->post->create(['data' => ['title' => 'Draft', 'authorId' => 1]]);
            self::fail('duplicate compound unique');
        } catch (UniqueConstraintError $e) {
            self::assertSame(['authorId', 'title'], $e->meta['target']);
        }
        try {
            $this->prisma->post->create(['data' => ['title' => 'x', 'authorId' => 999]]);
            self::fail('foreign key');
        } catch (ForeignKeyConstraintError $e) {
            self::assertSame('P2003', $e->getCode());
            // Prisma's format on every database: the constraint name + " (index)"
            self::assertSame('Post_author_id_fkey (index)', $e->meta['field_name']);
            self::assertSame('Post', $e->meta['modelName']);
            self::assertStringContainsString('Foreign key constraint failed on the field: `Post_author_id_fkey (index)`', $e->getMessage());
        }
        try {
            $this->prisma->post->update(['where' => ['id' => 1], 'data' => ['authorId' => 999]]);
            self::fail('foreign key on update');
        } catch (ForeignKeyConstraintError $e) {
            self::assertSame('Post_author_id_fkey (index)', $e->meta['field_name']);
        }
        try {
            $this->prisma->comment->create(['data' => ['body' => 'x', 'postId' => 999]]);
            self::fail('foreign key of a model with two foreign keys');
        } catch (ForeignKeyConstraintError $e) {
            self::assertSame('Comment_postId_fkey (index)', $e->meta['field_name']);
        }
        try {
            $this->prisma->user->update(['where' => ['id' => 999], 'data' => ['name' => 'x']]);
            self::fail('update of a missing record');
        } catch (RecordNotFoundError $e) {
            self::assertSame('P2025', $e->getCode());
            self::assertSame('Record to update not found.', $e->meta['cause']);
        }
        try {
            $this->prisma->user->delete(['where' => ['id' => 999]]);
            self::fail('delete of a missing record');
        } catch (RecordNotFoundError $e) {
            self::assertSame('Record to delete does not exist.', $e->meta['cause']);
        }
        try {
            $this->prisma->user->findUniqueOrThrow(['where' => ['id' => 999]]);
            self::fail('findUniqueOrThrow');
        } catch (RecordNotFoundError $e) {
            self::assertSame('No User found', $e->getMessage());
        }
        try {
            $this->prisma->user->findFirstOrThrow(['where' => ['name' => 'nobody']]);
            self::fail('findFirstOrThrow');
        } catch (RecordNotFoundError) {
        }
        self::assertSame('Ada', $this->prisma->user->findFirstOrThrow(['where' => ['name' => 'Ada']])['name']);
        try {
            $this->prisma->user->findMany(['where' => ['nope' => 1]]);
            self::fail('unknown field');
        } catch (PrismaClientValidationError $e) {
            self::assertStringContainsString('Unknown argument `nope`', $e->getMessage());
        }
        try {
            $this->prisma->user->findMany(['wher' => []]);
            self::fail('unknown argument');
        } catch (PrismaClientValidationError $e) {
            self::assertStringContainsString('Unknown argument `wher`', $e->getMessage());
        }
        try {
            $this->prisma->user->create(['data' => ['name' => 'no email']]);
            self::fail('missing required field');
        } catch (PrismaClientValidationError $e) {
            self::assertStringContainsString('Argument `email` is missing', $e->getMessage());
        }
        if (static::provider() === 'mysql') {
            try {
                $this->prisma->user->create(['data' => ['email' => str_repeat('a', 300)]]);
                self::fail('VARCHAR(191) overflow');
            } catch (\Nesto\Prisma\Client\Errors\ValueTooLongError $e) {
                self::assertSame('P2000', $e->getCode());
                self::assertSame('email', $e->meta['column_name']);
            }
        }
        $unreachable = match (static::provider()) {
            'sqlite' => 'file:/nonexistent-' . bin2hex(random_bytes(4)) . '/db.sqlite',
            'postgresql' => 'postgresql://nobody@127.0.0.1:1/nothing_test',
            default => 'mysql://nobody@127.0.0.1:1/nothing_test',
        };
        $client = new PrismaClient(['schema' => end(self::$dirs[static::class]) . '/prisma/schema.prisma', 'datasourceUrl' => $unreachable]);
        try {
            $client->user->count();
            self::fail('unreachable database');
        } catch (\Nesto\Prisma\Client\Errors\PrismaClientInitializationError $e) {
            self::assertContains($e->errorCode, ['P1001', 'P1003']);
        }
    }

    public function testNestedCursorAndDistinctPerParent(): void
    {
        $this->seed();
        $this->prisma->post->createMany(['data' => [['title' => 'A2', 'authorId' => 1, 'views' => 2], ['title' => 'B2', 'authorId' => 2, 'views' => 7]]]);
        $users = $this->prisma->user->findMany([
            'where' => ['id' => ['in' => [1, 2]]],
            'orderBy' => ['id' => 'asc'],
            'select' => [
                'id' => true,
                'posts' => ['orderBy' => ['id' => 'asc'], 'cursor' => ['id' => 2], 'select' => ['id' => true]],
                'comments' => true,
            ],
        ]);
        self::assertSame([['id' => 2], ['id' => 4]], $users[0]['posts'], 'the cursor applies inside its parent');
        self::assertSame([], $users[1]['posts'], 'the cursor record belongs to another parent');
        $distinct = $this->prisma->user->findMany([
            'where' => ['id' => ['in' => [1, 2]]],
            'orderBy' => ['id' => 'asc'],
            'select' => ['posts' => ['distinct' => ['views'], 'orderBy' => ['id' => 'asc'], 'take' => 5, 'select' => ['views' => true]]],
        ]);
        self::assertSame([[['views' => 10], ['views' => 2]], [['views' => 7]]], array_column($distinct, 'posts'));
    }

    // ---- transactions / raw / events / extensions ------------------------------------------------

    public function testTransactions(): void
    {
        [$user, $count] = $this->prisma->transaction([
            fn (PrismaClient $tx) => $tx->user->create(['data' => ['email' => 't@example.com']]),
            fn (PrismaClient $tx) => $tx->user->count(),
        ]);
        self::assertSame('t@example.com', $user['email']);
        self::assertSame(1, $count);
        try {
            $this->prisma->transaction([
                fn ($tx) => $tx->user->create(['data' => ['email' => 'u@example.com']]),
                fn ($tx) => $tx->user->create(['data' => ['email' => 't@example.com']]),
            ]);
            self::fail('the second create fails');
        } catch (UniqueConstraintError) {
        }
        self::assertSame(1, $this->prisma->user->count(), 'the array transaction was rolled back');

        $result = $this->prisma->transaction(function (PrismaClient $tx) {
            $sender = $tx->user->update(['where' => ['email' => 't@example.com'], 'data' => ['balance' => ['decrement' => 10]]]);
            $tx->user->create(['data' => ['email' => 'r@example.com', 'balance' => 10]]);

            return $sender['balance'];
        }, ['isolationLevel' => Prisma::Serializable, 'timeout' => 10000]);
        self::assertSame('-10', $result);
        self::assertSame(2, $this->prisma->user->count());

        try {
            $this->prisma->transaction(function (PrismaClient $tx) {
                $tx->user->create(['data' => ['email' => 'gone@example.com']]);
                throw new \DomainException('business rule');
            });
            self::fail('the closure throws');
        } catch (\DomainException $e) {
            self::assertSame('business rule', $e->getMessage());
        }
        self::assertNull($this->prisma->user->findUnique(['where' => ['email' => 'gone@example.com']]));

        // the timeout rolls back
        try {
            $this->prisma->transaction(function (PrismaClient $tx) {
                $tx->user->create(['data' => ['email' => 'slow@example.com']]);
                usleep(30_000);
                $tx->user->count();
            }, ['timeout' => 10]);
            self::fail('expired transaction');
        } catch (TransactionError $e) {
            self::assertSame('P2028', $e->getCode());
            self::assertStringContainsString('expired transaction', $e->getMessage());
        }
        self::assertNull($this->prisma->user->findUnique(['where' => ['email' => 'slow@example.com']]));

        // the $tx client cannot be used after the transaction
        $leaked = $this->prisma->transaction(fn (PrismaClient $tx) => $tx);
        self::assertTrue($leaked->isTransactionClient());
        try {
            $leaked->user->count();
            self::fail('closed transaction');
        } catch (TransactionError $e) {
            self::assertStringContainsString('Transaction already closed', $e->getMessage());
        }
        // nested writes are atomic on their own
        try {
            $this->prisma->user->create(['data' => ['email' => 'nested@example.com', 'posts' => ['create' => [['title' => 'ok'], ['title' => 'ok']]]]]);
            self::fail('the duplicate nested post fails');
        } catch (UniqueConstraintError) {
        }
        self::assertNull($this->prisma->user->findUnique(['where' => ['email' => 'nested@example.com']]));
        self::assertFalse($this->prisma->connection()->inTransaction());
        // `$`-prefixed Prisma names
        self::assertSame([2], $this->prisma->{'$transaction'}([fn ($tx) => $tx->user->count()]));
    }

    public function testRawQueries(): void
    {
        $this->seed();
        $users = $this->q('users');
        $rows = $this->prisma->queryRaw(Prisma::sql('SELECT ' . $this->q('email') . ' FROM ' . $users . ' WHERE ' . $this->q('age') . ' > ', 30, ' AND ' . $this->q('name') . ' = ', 'Ada'));
        self::assertSame([['email' => 'ada@example.com']], $rows);
        $rows = $this->prisma->queryRaw('SELECT ' . $this->q('email') . ' FROM ' . $users . ' WHERE ' . $this->q('id') . ' IN (?, ?) ORDER BY ' . $this->q('id'), [1, 2]);
        self::assertSame(['ada@example.com', 'bob@example.com'], self::pluck($rows, 'email'));
        $rows = $this->prisma->queryRaw(Prisma::sql('SELECT ' . $this->q('id') . ' FROM ' . $users . ' WHERE ' . $this->q('id') . ' IN (', Prisma::join([2, 3]), ') ORDER BY ', Prisma::raw($this->q('id')), ' DESC'));
        self::assertSame([3, 2], array_map('intval', self::pluck($rows, 'id')));
        $injection = "x' OR '1'='1";
        self::assertSame([], $this->prisma->queryRaw(Prisma::sql('SELECT * FROM ' . $users . ' WHERE ' . $this->q('email') . ' = ', $injection)));
        self::assertSame(1, $this->prisma->executeRaw(Prisma::sql('UPDATE ' . $users . ' SET ' . $this->q('name') . ' = ', 'Cyd', ' WHERE ' . $this->q('id') . ' = ', 3)));
        self::assertSame(2, $this->prisma->executeRawUnsafe('UPDATE ' . $users . ' SET ' . $this->q('age') . ' = ? WHERE ' . $this->q('age') . ' IS NOT NULL', 40));
        self::assertSame(1, (int) $this->prisma->queryRawUnsafe('SELECT COUNT(*) AS n FROM ' . $users . ' WHERE ' . $this->q('name') . ' = ?', 'Cyd')[0]['n']);
        if (static::provider() === 'mysql') {
            self::assertSame('+00:00', $this->prisma->queryRaw('SELECT @@session.time_zone AS tz')[0]['tz'], 'sessions run in UTC');
        }
        try {
            $this->prisma->queryRaw('SELECT * FROM no_such_table');
            self::fail('raw error');
        } catch (RawQueryError $e) {
            self::assertSame('P2010', $e->getCode());
            self::assertStringContainsString('Raw query failed', $e->getMessage());
        }
    }

    public function testQueryEventsAndLogging(): void
    {
        $events = [];
        $stream = fopen('php://memory', 'w+');
        $client = new PrismaClient(['schema' => end(self::$dirs[static::class]) . '/prisma/schema.prisma', 'log' => [['emit' => 'stdout', 'level' => 'query'], ['emit' => 'event', 'level' => 'error']], 'logStream' => $stream]);
        $client->on('query', function (array $event) use (&$events): void {
            $events[] = $event;
        });
        $errors = [];
        $client->on('error', function (array $event) use (&$errors): void {
            $errors[] = $event;
        });
        $client->user->findMany(['where' => ['email' => 'x@example.com']]);
        self::assertCount(1, $events);
        self::assertStringContainsString('SELECT', $events[0]['query']);
        self::assertSame('["x@example.com"]', $events[0]['params']);
        self::assertIsFloat($events[0]['duration']);
        self::assertInstanceOf(DateTimeImmutable::class, $events[0]['timestamp']);
        rewind($stream);
        self::assertStringStartsWith('prisma:query SELECT', (string) stream_get_contents($stream));
        try {
            $client->user->update(['where' => ['id' => 42], 'data' => ['name' => 'x']]);
        } catch (RecordNotFoundError) {
        }
        self::assertCount(1, $errors);
        self::assertStringContainsString('Record to update not found', $errors[0]['message']);
        $client->disconnect();
    }

    public function testClientExtensions(): void
    {
        $this->seed();
        $calls = [];
        $xprisma = $this->prisma->extends([
            'name' => 'demo',
            'result' => ['user' => ['label' => [
                'needs' => ['name' => true, 'email' => true],
                'compute' => fn (array $user) => ($user['name'] ?? '?') . ' <' . $user['email'] . '>',
            ]]],
            'query' => [
                'user' => ['findMany' => function (array $args, \Closure $query) use (&$calls) {
                    $calls[] = 'user.findMany';
                    $args['where'] = ($args['where'] ?? []) + ['name' => ['not' => null]];

                    return $query($args);
                }],
                '$allModels' => ['$allOperations' => function (string $model, string $operation, array $args, \Closure $query) use (&$calls) {
                    $calls[] = "$model.$operation";

                    return $query($args);
                }],
            ],
            'model' => ['user' => ['findByEmail' => function (string $email) {
                return $this->findUnique(['where' => ['email' => $email]]);
            }]],
            'client' => ['$greet' => fn (string $who) => "hello $who"],
        ]);
        $users = $xprisma->user->findMany(['orderBy' => ['id' => 'asc'], 'select' => ['id' => true, 'label' => true]]);
        self::assertSame([['id' => 1, 'label' => 'Ada <ada@example.com>'], ['id' => 2, 'label' => 'Bob <bob@example.com>']], $users);
        self::assertSame(['User.findMany', 'user.findMany'], $calls, 'later hooks wrap earlier ones; model-specific hooks run inside $allModels');
        $full = $xprisma->user->findUnique(['where' => ['id' => 3]]);
        self::assertSame('? <cyd@example.com>', $full['label']);
        $omitted = $xprisma->user->findUnique(['where' => ['id' => 3], 'omit' => ['label' => true]]);
        self::assertArrayNotHasKey('label', $omitted);
        $nested = $xprisma->post->findFirst(['where' => ['id' => 1], 'include' => ['author' => true]]);
        self::assertSame('Ada <ada@example.com>', $nested['author']['label'], 'computed fields apply to nested records');
        self::assertSame('Bob', $xprisma->user->findByEmail('bob@example.com')['name']);
        self::assertSame('hello you', $xprisma->greet('you'));
        self::assertSame('hello me', $xprisma->{'$greet'}('me'));
        // the original client is unchanged
        self::assertArrayNotHasKey('label', $this->prisma->user->findUnique(['where' => ['id' => 1]]));
        self::assertCount(3, $this->prisma->user->findMany());
        // extensions compose
        $twice = $xprisma->extends(['query' => ['post' => ['count' => fn ($args, $query) => $query($args) * 10]]]);
        self::assertSame(30, $twice->post->count());
        self::assertSame(3, $xprisma->post->count());
        // transactions keep the extensions
        $label = $xprisma->transaction(fn ($tx) => $tx->user->findUnique(['where' => ['id' => 2]])['label']);
        self::assertSame('Bob <bob@example.com>', $label);
    }

    public function testGlobalOmitOption(): void
    {
        $client = new PrismaClient(['schema' => end(self::$dirs[static::class]) . '/prisma/schema.prisma', 'omit' => ['user' => ['avatar' => true, 'settings' => true]]]);
        $created = $client->user->create(['data' => ['email' => 'o@example.com']]);
        self::assertArrayNotHasKey('avatar', $created);
        $user = $client->user->findFirst();
        self::assertArrayNotHasKey('avatar', $user);
        self::assertArrayHasKey('email', $user);
        $user = $client->user->findFirst(['omit' => ['avatar' => false]]);
        self::assertArrayHasKey('avatar', $user);
        self::assertArrayNotHasKey('settings', $user);
        self::assertSame(['avatar' => null], $client->user->findFirst(['select' => ['avatar' => true]]));
        $post = $client->post->create(['data' => ['title' => 'x', 'author' => ['connect' => ['email' => 'o@example.com']]], 'include' => ['author' => true]]);
        self::assertArrayNotHasKey('avatar', $post['author']);
        self::assertArrayHasKey('avatar', $this->prisma->user->findFirst(), 'other clients are not affected');
        $client->disconnect();
    }

    public function testReferentialActionsEmulatedInPrismaRelationMode(): void
    {
        $url = static::databaseUrl();
        $dir = sys_get_temp_dir() . '/nesto_prisma_rm_' . bin2hex(random_bytes(5));
        mkdir($dir . '/prisma', 0777, true);
        $connection = $this->prisma->connection();
        $schema = sprintf("datasource db {\n  provider     = \"%s\"\n  url          = \"%s\"\n  relationMode = \"prisma\"\n}\n\n", static::provider(), str_contains((string) $url, 'file:') ? 'file:./rm_test.db' : $url) . <<<'PRISMA'
            model Owner {
              id      Int      @id @default(autoincrement())
              pets    Pet[]
              toys    Toy[]
              badges  Badge[]
              tags    Tag[]
            }

            model Pet {
              id      Int   @id @default(autoincrement())
              owner   Owner @relation(fields: [ownerId], references: [id], onDelete: Cascade)
              ownerId Int
              vets    Vet[]

              @@index([ownerId])
            }

            model Vet {
              id    Int @id @default(autoincrement())
              pet   Pet @relation(fields: [petId], references: [id], onDelete: Cascade)
              petId Int

              @@index([petId])
            }

            model Toy {
              id      Int    @id @default(autoincrement())
              owner   Owner? @relation(fields: [ownerId], references: [id], onDelete: SetNull)
              ownerId Int?

              @@index([ownerId])
            }

            model Badge {
              id      Int   @id @default(autoincrement())
              owner   Owner @relation(fields: [ownerId], references: [id], onDelete: Restrict)
              ownerId Int

              @@index([ownerId])
            }

            model Tag {
              id     Int     @id @default(autoincrement())
              owners Owner[]
            }
            PRISMA;
        file_put_contents($dir . '/prisma/schema.prisma', $schema);
        if (static::provider() !== 'sqlite') {
            DatabaseReset::reset($connection);
            unset(self::$clients[static::class]); // the main schema is pushed again by the next test
        }
        PrismaTestDatabase::push($dir . '/prisma/schema.prisma', null, true);
        $client = new PrismaClient(['schema' => $dir . '/prisma/schema.prisma']);
        try {
            $client->owner->create(['data' => [
                'pets' => ['create' => [['vets' => ['create' => [[], []]]], []]],
                'toys' => ['create' => [[]]],
                'tags' => ['create' => [[]]],
            ]]);
            $client->owner->create(['data' => ['badges' => ['create' => [[]]]]]);
            self::assertSame([], $client->connection()->fetchAll(static::provider() === 'sqlite' ? "SELECT * FROM pragma_foreign_key_list('Pet')" : 'SELECT 1 WHERE 1 = 0'), 'no foreign keys in this mode');

            $client->owner->delete(['where' => ['id' => 1]]);
            self::assertSame(0, $client->pet->count(), 'Cascade');
            self::assertSame(0, $client->vet->count(), 'Cascade, recursively');
            self::assertSame([['id' => 1, 'ownerId' => null]], $client->toy->findMany(), 'SetNull');
            self::assertSame(0, (int) $client->queryRawUnsafe('SELECT COUNT(*) AS n FROM ' . $connection->quote('_OwnerToTag'))[0]['n'], 'join rows are removed');
            try {
                $client->owner->delete(['where' => ['id' => 2]]);
                self::fail('Restrict');
            } catch (RequiredRelationViolationError $e) {
                self::assertSame('P2014', $e->getCode());
                self::assertStringContainsString("'BadgeToOwner'", $e->getMessage());
            }
            self::assertSame(1, $client->owner->count());
            $client->badge->deleteMany();
            self::assertSame(['count' => 1], $client->owner->deleteMany());
        } finally {
            $client->disconnect();
            if (static::provider() !== 'sqlite') {
                DatabaseReset::reset($connection);
            }
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    protected function q(string $identifier): string
    {
        return $this->prisma->connection()->quote($identifier);
    }
}
