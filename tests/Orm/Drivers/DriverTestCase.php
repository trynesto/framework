<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Drivers;

use DateTimeImmutable;
use Nesto\Orm\Connection;
use Nesto\Orm\DataSource;
use Nesto\Orm\EntityManager;
use Nesto\Orm\MetadataRegistry;
use Nesto\Orm\Migration\Blueprint;
use Nesto\Orm\MorphMap;
use Nesto\Orm\QueryException;
use Nesto\Orm\Repository;
use Nesto\Tests\Orm\Drivers\Fixtures\Gadget;
use Nesto\Tests\Orm\Drivers\Fixtures\GadgetKind;
use Nesto\Tests\Orm\Drivers\Fixtures\GadgetV2;
use Nesto\Tests\Orm\Drivers\Fixtures\Member;
use Nesto\Tests\Orm\Drivers\Fixtures\Team;
use Nesto\Tests\Orm\Fixtures\CreateAuthorsMigration;
use Nesto\Tests\Orm\Fixtures\CreateBooksMigration;
use Nesto\Tests\Orm\Fixtures\Post;
use Nesto\Tests\Orm\Fixtures\PostStatus;
use Nesto\Tests\Orm\Fixtures\Profile;
use Nesto\Tests\Orm\Fixtures\Relations\Article;
use Nesto\Tests\Orm\Fixtures\Relations\Comment;
use Nesto\Tests\Orm\Fixtures\Relations\Country;
use Nesto\Tests\Orm\Fixtures\Relations\Image;
use Nesto\Tests\Orm\Fixtures\Relations\Label;
use Nesto\Tests\Orm\Fixtures\Relations\Mechanic;
use Nesto\Tests\Orm\Fixtures\Relations\MorphIntDoc;
use Nesto\Tests\Orm\Fixtures\Relations\MorphNote;
use Nesto\Tests\Orm\Fixtures\Relations\MorphUuidDoc;
use Nesto\Tests\Orm\Fixtures\Relations\MorphUuidOnlyNote;
use Nesto\Tests\Orm\Fixtures\Relations\Owner;
use Nesto\Tests\Orm\Fixtures\Relations\Paragraph;
use Nesto\Tests\Orm\Fixtures\Relations\RelationsSeeder;
use Nesto\Tests\Orm\Fixtures\Relations\Video;
use Nesto\Tests\Orm\Fixtures\Relations\Writer;
use Nesto\Tests\Orm\Fixtures\Tag;
use Nesto\Tests\Orm\Fixtures\User;
use PHPUnit\Framework\TestCase;

/**
 * Driver integration suite: the same scenarios run against every database whose URL is configured.
 * SQLite (in memory) always runs; the server-backed drivers are skipped unless their env var is set:
 *
 *   NESTO_TEST_MYSQL_URL=mysql://root@127.0.0.1:3306/nesto_test
 *   NESTO_TEST_MARIADB_URL=mariadb://root@127.0.0.1:3307/nesto_test
 *   NESTO_TEST_PGSQL_URL=postgres://postgres@127.0.0.1:5432/nesto_test
 *
 *   NESTO_TEST_MYSQL_URL=... vendor/bin/phpunit tests/Orm/Drivers
 *
 * URL form: scheme://user:password@host:port/database (password and port optional; URL-encode special
 * characters). The database must already exist and its name must contain "test": every test DROPS and
 * recreates the tables it uses (users, posts, tags, post_tags, profiles, gadgets, teams, members,
 * authors, books, migrations, and the relation fixtures: countries, writers, articles, videos, comments,
 * images, labels, labelables, paragraphs, mechanics, cars, owners, morph_*, and the col_* column fixtures).
 */
abstract class DriverTestCase extends TestCase
{
    use RelationBehaviorScenarios;
    use SchemaDiffScenarios;
    use ColumnFeatureScenarios;
    use TransactionLockingScenarios;
    use InheritanceTreeScenarios;
    use DataAccessScenarios;
    use WriteSafetyScenarios;

    protected const ENTITIES = [User::class, Post::class, Tag::class, Profile::class, Gadget::class, Team::class, Member::class];
    /** Polymorphic / through fixtures: synchronized by the relation scenarios only, on their own DataSource. */
    protected const RELATION_ENTITIES = [...RelationsSeeder::ENTITIES, MorphUuidDoc::class, MorphIntDoc::class, MorphNote::class, MorphUuidOnlyNote::class];
    private const EXTRA_TABLES = ['books', 'authors', 'migrations'];

    protected DataSource $ds;
    protected Repository $users;
    protected Repository $posts;
    protected Repository $tags;
    protected Repository $gadgets;

    /** ORM options for this driver, or null when it is not configured (the test is skipped). */
    abstract protected static function options(): ?array;

    /** Expected Connection::platform(): sqlite | mysql | mariadb | pgsql. */
    abstract protected static function platform(): string;

    protected function setUp(): void
    {
        $options = static::options();
        if ($options === null) {
            $this->markTestSkipped(static::class . ': set ' . static::envVar() . ' to run this driver.');
        }
        MetadataRegistry::clear();
        $this->ds = new DataSource($options + ['entities' => self::ENTITIES]);
        $this->resetDatabase();
        $this->ds->synchronize();
        $this->users = $this->ds->getRepository(User::class);
        $this->posts = $this->ds->getRepository(Post::class);
        $this->tags = $this->ds->getRepository(Tag::class);
        $this->gadgets = $this->ds->getRepository(Gadget::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->ds) && $this->ds->isInitialized() && static::platform() !== 'sqlite') {
            $conn = $this->ds->getConnection();
            while ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $this->resetDatabase();
        }
        if (isset($this->ds)) {
            $this->ds->destroy();
        }
        MorphMap::clear();
    }

    protected static function envVar(): string
    {
        return '';
    }

    /** @return array<string, mixed>|null */
    protected static function optionsFromEnv(string $var): ?array
    {
        $url = getenv($var);
        if ($url === false || trim($url) === '') {
            return null;
        }
        $parsed = Connection::parseUrl(trim($url));
        if (! str_contains(strtolower((string) ($parsed['database'] ?? '')), 'test')) {
            throw new \RuntimeException("$var must point at a throwaway database whose name contains \"test\" (its tables are dropped).");
        }

        return ['url' => trim($url)];
    }

    private function resetDatabase(): void
    {
        $this->ds->synchronizer()->drop(self::RELATION_ENTITIES);
        $this->ds->dropDatabase();
        $schema = $this->ds->schema();
        $conn = $this->ds->getConnection();
        if ($conn->driver() === 'mysql') {
            $conn->execute('SET FOREIGN_KEY_CHECKS = 0');
        }
        foreach (self::EXTRA_TABLES as $table) {
            $schema->dropTableIfExists($table);
        }
        if ($conn->driver() === 'mysql') {
            $conn->execute('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    // ---- connection / platform ----------------------------------------------------------------

    public function testConnectionKnowsItsDriverAndPlatform(): void
    {
        $conn = $this->ds->getConnection();
        $this->assertSame(static::platform(), $conn->platform());
        $this->assertSame(static::platform() === 'mariadb' ? 'mysql' : static::platform(), $conn->driver());
        $this->assertNotNull($conn->serverVersion());
        $this->assertSame(1, (int) $conn->fetchColumn('SELECT 1'));

        if (in_array(static::platform(), ['mysql', 'mariadb'], true)) {
            // separate host/port/... options, configured as plain "mysql": MariaDB is still recognised
            $options = Connection::parseUrl((string) static::options()['url']);
            $options['type'] = 'mysql';
            $detected = Connection::fromOptions($options);
            $this->assertSame('mysql', $detected->driver());
            $this->assertSame(static::platform(), $detected->platform());
        }
    }

    public function testSchemaHelpers(): void
    {
        $schema = $this->ds->schema();
        $schema->createTable('authors', fn (Blueprint $t) => $t->id() && $t->string('a'));
        $this->ds->getConnection()->execute('INSERT INTO ' . $this->q('authors') . ' (a) VALUES (:a)', ['a' => 'x']);
        $schema->addColumn('authors', fn (Blueprint $t) => $t->integer('b')->default(2));
        $schema->renameColumn('authors', 'b', 'c');
        $this->assertSame(['id', 'a', 'c'], $schema->columns('authors'));
        $this->assertSame(2, (int) $this->ds->getConnection()->fetchColumn('SELECT ' . $this->q('c') . ' FROM ' . $this->q('authors')));
        $schema->dropColumn('authors', 'c');
        $this->assertFalse($schema->hasColumn('authors', 'c'));
        $schema->renameTable('authors', 'books');
        $this->assertTrue($schema->hasTable('books'));
        $this->assertFalse($schema->hasTable('authors'));
        $schema->dropTableIfExists('books');
        $schema->dropTableIfExists('books');
        $this->assertFalse($schema->hasTable('books'));
    }

    // ---- schema synchronization ---------------------------------------------------------------

    public function testSynchronizeCreatesEveryColumnTypeAndIsIdempotent(): void
    {
        $schema = $this->ds->schema();
        foreach (['users', 'posts', 'tags', 'post_tags', 'profiles', 'gadgets', 'teams', 'members'] as $table) {
            $this->assertTrue($schema->hasTable($table), "table $table");
        }
        $types = array_column($schema->columnDetails('gadgets'), 'type', 'name');
        $expected = $this->expectedColumnTypes();
        foreach ($expected as $column => $type) {
            $this->assertArrayHasKey($column, $types);
            $this->assertStringStartsWith($type, $types[$column], "type of gadgets.$column");
        }
        $nullable = array_column($schema->columnDetails('gadgets'), 'nullable', 'name');
        $this->assertTrue($nullable['description']);
        $this->assertTrue($nullable['rating']);
        $this->assertFalse($nullable['name']);
        $this->assertFalse($nullable['quantity']);
        $this->assertSame(
            ['id', 'name', 'description', 'quantity', 'serial', 'active', 'weight', 'price', 'specs', 'labels', 'released_on', 'last_seen_at', 'external_id', 'kind', 'rating'],
            $schema->columns('gadgets'),
        );

        // second run: nothing to create or alter
        $conn = $this->ds->getConnection();
        $conn->enableQueryLog()->flushQueryLog();
        $this->ds->synchronize();
        $ddl = array_filter(array_column($conn->getQueryLog(), 'sql'), fn (string $sql) => preg_match('/^\s*(CREATE|ALTER|DROP)\b/i', $sql) === 1);
        $this->assertSame([], array_values($ddl));
        $conn->disableQueryLog();
    }

    /** @return array<string, string> column => expected (prefix of the) introspected type */
    private function expectedColumnTypes(): array
    {
        return match (static::platform()) {
            'sqlite' => [
                'id' => 'integer', 'name' => 'varchar(120)', 'description' => 'text', 'quantity' => 'integer', 'serial' => 'integer',
                'active' => 'integer', 'weight' => 'real', 'price' => 'decimal(10, 2)', 'specs' => 'text', 'released_on' => 'date',
                'last_seen_at' => 'datetime', 'external_id' => 'char(36)', 'kind' => 'varchar',
            ],
            'mysql', 'mariadb' => [
                'id' => 'bigint', 'name' => 'varchar', 'description' => 'text', 'quantity' => 'int', 'serial' => 'bigint',
                'active' => 'tinyint', 'weight' => 'double', 'price' => 'decimal', 'specs' => static::platform() === 'mariadb' ? 'longtext' : 'json',
                'released_on' => 'date', 'last_seen_at' => 'datetime', 'external_id' => 'char', 'kind' => 'enum',
            ],
            'pgsql' => [
                'id' => 'int8', 'name' => 'varchar', 'description' => 'text', 'quantity' => 'int4', 'serial' => 'int8',
                'active' => 'bool', 'weight' => 'float8', 'price' => 'numeric', 'specs' => 'jsonb', 'released_on' => 'date',
                'last_seen_at' => 'timestamp', 'external_id' => 'uuid', 'kind' => 'gadgets_kind_enum', // native enum type, named like TypeORM
            ],
        };
    }

    public function testSynchronizeAddsNewColumnsToAnExistingTable(): void
    {
        $this->gadgets->save($this->gadgets->create(['name' => 'Old row']));

        $v2 = new DataSource(['entities' => [GadgetV2::class]]);
        $v2->useConnection($this->ds->getConnection());
        $v2->synchronize();

        $columns = $this->ds->schema()->columns('gadgets');
        foreach (['nickname', 'stock', 'sku', 'barcode'] as $column) {
            $this->assertContains($column, $columns);
        }
        // like TypeORM's synchronize: columns no longer mapped are dropped
        $this->assertNotContains('quantity', $columns, 'columns removed from the entity are dropped');
        $this->assertSame(['id', 'name', 'nickname', 'stock', 'sku', 'barcode'], $columns);

        $repo = $v2->getRepository(GadgetV2::class);
        $old = $repo->findOneByOrFail(['name' => 'Old row']);
        $this->assertNull($old->nickname);
        $this->assertSame(5, $old->stock);
        $this->assertSame('', $old->sku);

        $new = $repo->save($repo->create(['name' => 'New', 'sku' => 'N-1', 'barcode' => 'B1']));
        $this->assertSame('N-1', $repo->findByIdOrFail($new->id)->sku);
        try {
            $repo->save($repo->create(['name' => 'Dup', 'sku' => 'N-2', 'barcode' => 'B1']));
            $this->fail('barcode must be unique');
        } catch (QueryException) {
        }

        // and the added columns are a no-op the next time
        $conn = $this->ds->getConnection();
        $conn->enableQueryLog()->flushQueryLog();
        $v2->synchronize();
        $ddl = array_filter(array_column($conn->getQueryLog(), 'sql'), fn (string $sql) => preg_match('/^\s*(CREATE|ALTER)\b/i', $sql) === 1);
        $this->assertSame([], array_values($ddl));
        $conn->disableQueryLog();
    }

    public function testMutuallyReferencingTablesAreCreatedAndUsable(): void
    {
        $teams = $this->ds->getRepository(Team::class);
        $members = $this->ds->getRepository(Member::class);
        $team = $teams->save($teams->create(['name' => 'Red']));
        $ada = $members->create(['name' => 'Ada']);
        $ada->team = $team;
        $members->save($ada);
        $team->captain = $ada;
        $teams->save($team);

        $loaded = $teams->findOneOrFail(['where' => ['name' => 'Red'], 'relations' => ['captain', 'members']]);
        $this->assertSame('Ada', $loaded->captain->name);
        $this->assertSame(['Ada'], array_map(fn (Member $m) => $m->name, $loaded->members));

        // saving a loaded entity whose nullable relation was not loaded keeps the foreign key
        $plain = $teams->findOneByOrFail(['name' => 'Red']);
        $this->assertFalse(isset($plain->captain), 'not loaded');
        $plain->name = 'Blue';
        $teams->save($plain);
        $this->assertSame('Ada', $teams->findOneOrFail(['where' => ['name' => 'Blue'], 'relations' => ['captain']])->captain->name);
        // explicitly clearing it still works
        $plain->captain = null;
        $teams->save($plain);
        $this->assertNull($teams->findOneOrFail(['where' => ['name' => 'Blue'], 'relations' => ['captain']])->captain);

        // FK enforced in both directions
        $this->expectException(QueryException::class);
        $this->ds->getConnection()->execute('INSERT INTO ' . $this->ds->getConnection()->quote('members') . ' (name, team_id) VALUES (:n, :t)', ['n' => 'Ghost', 't' => 999]);
    }

    // ---- CRUD -----------------------------------------------------------------------------------

    public function testCrudThroughRepository(): void
    {
        $id = $this->users->insert(['name' => 'Ada', 'email' => 'ada@example.com', 'age' => 36]);
        $this->assertSame(1, $id);
        $bob = $this->users->save($this->users->create(['name' => 'Bob', 'email' => 'bob@example.com']));
        $this->assertSame(2, $bob->id);
        $this->assertSame([3, 4], $this->users->insert([
            ['name' => 'Cyd', 'email' => 'cyd@example.com'],
            ['name' => 'Dee', 'email' => 'dee@example.com'],
        ]));

        $ada = $this->users->findByIdOrFail(1);
        $this->assertSame('Ada', $ada->name);
        $this->assertSame(36, $ada->age);
        $this->assertTrue($ada->active);
        $this->assertInstanceOf(DateTimeImmutable::class, $ada->createdAt);
        $this->assertSame('Bob', $this->users->findOneBy(['email' => 'bob@example.com'])->name);
        $this->assertNull($this->users->findOneBy(['email' => 'nobody@example.com']));
        $this->assertSame(4, $this->users->count());

        $ada->name = 'Ada L.';
        $ada->age = 37;
        $this->users->save($ada);
        $fresh = $this->users->findByIdOrFail(1);
        $this->assertSame('Ada L.', $fresh->name);
        $this->assertSame(37, $fresh->age);

        $this->assertSame(1, $this->users->update(2, ['age' => 50]));
        $this->assertSame(1, $this->users->update(2, ['age' => 50]), 'matched rows are reported even when unchanged');
        $this->assertSame(2, $this->users->update(['age' => ['<', 1]], ['active' => false]));
        $this->assertSame(2, $this->users->countBy(['active' => false]));
        $this->assertSame(1, $this->users->increment(2, 'age', 5));
        $this->assertSame(55, $this->users->findByIdOrFail(2)->age);

        $this->assertSame(1, $this->users->delete(4));
        $this->users->remove($this->users->findByIdOrFail(3));
        $this->assertSame(2, $this->users->count());

        // uuid primary key generated by the ORM
        $profiles = $this->ds->getRepository(Profile::class);
        $profile = $profiles->create(['bio' => 'Hi']);
        $profile->user = $fresh;
        $profiles->save($profile);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $profile->id);
        $this->assertSame('Hi', $profiles->findByIdOrFail($profile->id)->bio);

        // soft delete
        $this->users->softDelete(1);
        $this->assertSame(1, $this->users->count());
        $this->assertSame(2, $this->users->count(['withDeleted' => true]));
        $this->users->restore(1);
        $this->assertSame(2, $this->users->count());
    }

    public function testEveryTypeRoundTrips(): void
    {
        $when = new DateTimeImmutable('2024-02-29 13:45:07');
        $uuid = Repository::uuid();
        $gadget = $this->gadgets->create([
            'name' => 'Ünïcødé ✓',
            'description' => str_repeat('long text ', 500),
            'quantity' => -42,
            'serial' => 9007199254740993,
            'active' => false,
            'weight' => 1.25,
            'price' => '1234.50',
            'specs' => ['b' => 2, 'a' => ['nested' => true, 'list' => [1, 2.5, 'x']], 'emoji' => 'ü✓', 'null' => null],
            'labels' => ['one', 'two'],
            'releasedOn' => new DateTimeImmutable('2023-12-31'),
            'lastSeenAt' => $when,
            'externalId' => $uuid,
            'kind' => GadgetKind::Toy,
            'rating' => 0,
        ]);
        $this->gadgets->save($gadget);
        $this->assertSame(1, $gadget->id);

        $g = $this->gadgets->findByIdOrFail(1);
        $this->assertSame('Ünïcødé ✓', $g->name);
        $this->assertSame(5000, strlen((string) $g->description));
        $this->assertSame(-42, $g->quantity);
        $this->assertSame(9007199254740993, $g->serial);
        $this->assertFalse($g->active);
        $this->assertSame(1.25, $g->weight);
        $this->assertSame('1234.50', $g->price);
        $this->assertEquals(['a' => ['nested' => true, 'list' => [1, 2.5, 'x']], 'b' => 2, 'emoji' => 'ü✓', 'null' => null], $g->specs);
        $this->assertSame(['one', 'two'], $g->labels);
        $this->assertSame('2023-12-31', $g->releasedOn->format('Y-m-d'));
        $this->assertSame('2024-02-29 13:45:07', $g->lastSeenAt->format('Y-m-d H:i:s'));
        $this->assertSame($uuid, $g->externalId);
        $this->assertSame(GadgetKind::Toy, $g->kind);
        $this->assertSame(0, $g->rating);

        // defaults from the entity / DDL
        $this->gadgets->save($this->gadgets->create(['name' => 'Defaults']));
        $d = $this->gadgets->findOneByOrFail(['name' => 'Defaults']);
        $this->assertTrue($d->active);
        $this->assertSame([], $d->labels);
        $this->assertSame(GadgetKind::Tool, $d->kind);
        $this->assertNull($d->specs);
        $this->assertNull($d->rating);
        $raw = $this->ds->getConnection()->fetchOne('SELECT * FROM ' . $this->ds->getConnection()->quote('gadgets') . ' WHERE ' . $this->ds->getConnection()->quote('name') . ' = :n', ['n' => 'Defaults']);
        $this->assertEquals(1, (int) $raw['active']);

        // booleans in criteria, both values
        $this->assertSame(['Defaults'], array_map(fn (Gadget $x) => $x->name, $this->gadgets->findBy(['active' => true])));
        $this->assertSame(['Ünïcødé ✓'], array_map(fn (Gadget $x) => $x->name, $this->gadgets->findBy(['active' => false])));
        $this->assertSame(1, $this->gadgets->createQueryBuilder('g')->where('g.active = :a', ['a' => false])->getCount());

        // dirty checking: reloaded rows saved unchanged issue no UPDATE (DECIMAL "1234.50", JSONB key order, ...)
        $conn = $this->ds->getConnection();
        $conn->enableQueryLog()->flushQueryLog();
        $this->gadgets->save($g);
        $this->gadgets->save($d);
        $updates = array_filter(array_column($conn->getQueryLog(), 'sql'), fn (string $sql) => str_starts_with($sql, 'UPDATE'));
        $this->assertSame([], array_values($updates));

        $g->specs['b'] = 3;
        $g->active = true;
        $this->gadgets->save($g);
        $updates = array_filter(array_column($conn->getQueryLog(), 'sql'), fn (string $sql) => str_starts_with($sql, 'UPDATE'));
        $this->assertCount(1, $updates);
        $conn->disableQueryLog();
        $reloaded = $this->gadgets->findByIdOrFail(1);
        $this->assertSame(3, $reloaded->specs['b']);
        $this->assertTrue($reloaded->active);
    }

    public function testNullableColumns(): void
    {
        $g = $this->gadgets->save($this->gadgets->create(['name' => 'N', 'rating' => 5, 'description' => 'd', 'specs' => ['x' => 1], 'externalId' => Repository::uuid()]));
        $g->rating = null;
        $g->description = null;
        $g->specs = null;
        $g->externalId = null;
        $g->lastSeenAt = null;
        $this->gadgets->save($g);
        $r = $this->gadgets->findByIdOrFail($g->id);
        $this->assertNull($r->rating);
        $this->assertNull($r->description);
        $this->assertNull($r->specs);
        $this->assertNull($r->externalId);
        $this->assertNull($r->lastSeenAt);
        $this->assertSame(1, $this->gadgets->count(['where' => ['rating' => null, 'specs' => ['IsNull']]]));
        $this->assertSame(0, $this->gadgets->count(['where' => ['rating' => ['IsNotNull']]]));

        $r->rating = 3;
        $this->gadgets->save($r);
        $this->assertSame(3, $this->gadgets->findByIdOrFail($g->id)->rating);

        $this->expectException(QueryException::class); // NOT NULL is enforced
        $this->ds->getConnection()->execute('INSERT INTO ' . $this->ds->getConnection()->quote('users') . ' (name, email) VALUES (:n, NULL)', ['n' => 'x']);
    }

    // ---- query builder ----------------------------------------------------------------------------

    public function testQueryBuilder(): void
    {
        $this->seed();

        $qb = $this->users->createQueryBuilder('u')->where('u.age >= :min', ['min' => 30])->orderBy('u.age', 'DESC');
        $this->assertSame(['Cyd', 'Ada'], $this->names($qb->getMany()));
        $this->assertSame(2, $qb->getCount());
        $this->assertSame('Cyd', $qb->getOne()->name);

        $ordered = $this->users->createQueryBuilder('u')->orderBy('u.name');
        $this->assertSame(['Bob'], $this->names((clone $ordered)->take(1)->skip(1)->getMany()));
        $this->assertSame(['Bob', 'Cyd'], $this->names((clone $ordered)->skip(1)->getMany()), 'OFFSET without LIMIT');
        $this->assertSame(['Ada', 'Bob'], $this->names((clone $ordered)->limit(2)->getMany()));

        $this->assertSame(['Ada'], $this->names($this->users->find(['where' => ['name' => ['Like', 'A%']]])));
        $this->assertSame(['Ada'], $this->names($this->users->find(['where' => ['email' => ['ILike', 'ADA@%']]])));
        $this->assertSame(['Bob', 'Cyd'], $this->names($this->users->find(['where' => ['name' => ['NotLike', 'A%']], 'order' => ['name' => 'ASC']])));
        $this->assertSame(['Ada', 'Cyd'], $this->names($this->users->createQueryBuilder('u')->whereIn('name', ['Ada', 'Cyd'])->orderBy('u.name')->getMany()));
        $this->assertSame(['Bob'], $this->names($this->users->createQueryBuilder('u')->whereNotIn('u.name', ['Ada', 'Cyd'])->getMany()));
        $this->assertSame(['Ada', 'Cyd'], $this->names($this->users->find(['where' => ['age' => ['Between', 30, 45]], 'order' => ['age' => 'ASC']])));
        $this->assertSame([], $this->users->find(['where' => ['id' => ['In', []]]]));
        $this->assertSame(['Ada', 'Cyd'], $this->names($this->users->find(['where' => [['name' => 'Ada'], ['name' => 'Cyd']], 'order' => ['name' => 'ASC']])));
        $this->assertCount(3, $this->users->createQueryBuilder('u')->whereNull('settings')->getMany());
        $this->assertSame(2, $this->users->createQueryBuilder('u')->where('u.active = :a')->setParameter('a', true)->getCount());
        $this->assertSame(1, $this->users->createQueryBuilder('u')->where(['active' => false])->getCount());

        $raw = $this->posts->createQueryBuilder('p')
            ->select('p.status AS status, COUNT(p.id) AS total, SUM(p.views) AS views')
            ->groupBy('p.status')->having('COUNT(p.id) > :n', ['n' => 0])
            ->orderBy('p.status')
            ->getRawMany();
        $this->assertSame(
            [['status' => 'draft', 'total' => 1, 'views' => 3], ['status' => 'published', 'total' => 2, 'views' => 17]],
            array_map(fn (array $r) => ['status' => $r['status'], 'total' => (int) $r['total'], 'views' => (int) $r['views']], $raw),
        );

        // joins
        $users = $this->users->createQueryBuilder('u')
            ->leftJoinAndSelect('u.posts', 'p')
            ->leftJoinAndSelect('p.tags', 't')
            ->orderBy('u.name')->addOrderBy('p.id')->addOrderBy('t.id')
            ->getMany();
        $this->assertSame(['Ada', 'Bob', 'Cyd'], $this->names($users));
        $this->assertSame(['Hello', 'Second'], array_map(fn (Post $p) => $p->title, $users[0]->posts));
        $this->assertSame(['php', 'orm'], array_map(fn (Tag $t) => $t->name, $users[0]->posts[0]->tags));
        $this->assertSame([], $users[2]->posts);

        $published = $this->users->createQueryBuilder('u')
            ->innerJoin('u.posts', 'p', 'p.status = :status', ['status' => PostStatus::Published])
            ->select('u')->distinct()->orderBy('u.name')->getMany();
        $this->assertSame(['Ada', 'Bob'], $this->names($published));

        $withUser = $this->posts->createQueryBuilder('p')->innerJoinAndSelect('p.user', 'u')->where('u.name = :n', ['n' => 'Bob'])->getOne();
        $this->assertSame('Bob', $withUser->user->name);

        $viaTable = $this->posts->createQueryBuilder('p')
            ->innerJoin('post_tags', 'pt', 'pt.post_id = p.id')
            ->innerJoin(Tag::class, 't', 't.id = pt.tag_id')
            ->where('t.name = :tag', ['tag' => 'sql'])
            ->getMany();
        $this->assertSame(['Second'], array_map(fn (Post $p) => $p->title, $viaTable));

        // paging a to-many join pages root rows, not joined rows
        $page = $this->users->createQueryBuilder('u')->leftJoinAndSelect('u.posts', 'p')->orderBy('u.name', 'DESC')->take(2)->skip(1)->getMany();
        $this->assertSame(['Bob', 'Ada'], $this->names($page));
        $this->assertCount(2, $page[1]->posts);

        // write builders
        $this->assertSame(2, $this->ds->createQueryBuilder()->insert()->into(User::class)->values([
            ['name' => 'Q1', 'email' => 'q1@example.com', 'active' => false],
            ['name' => 'Q2', 'email' => 'q2@example.com'],
        ])->execute());
        $this->assertSame(1, $this->ds->createQueryBuilder()->update(User::class, 'u')->set(['active' => true, 'age' => 9])->where('u.name = :n', ['n' => 'Q1'])->execute());
        $this->assertTrue($this->users->findOneByOrFail(['name' => 'Q1'])->active);
        $this->assertSame(2, $this->ds->createQueryBuilder()->delete()->from(User::class)->where(['name' => ['In', ['Q1', 'Q2']]])->execute());

        // raw SQL with array expansion
        $em = $this->ds->getEntityManager();
        $rows = $em->query('SELECT ' . $this->q('name') . ' FROM ' . $this->q('users') . ' WHERE ' . $this->q('id') . ' IN (:ids) ORDER BY ' . $this->q('id'), ['ids' => [1, 3]]);
        $this->assertSame([['name' => 'Ada'], ['name' => 'Cyd']], $rows);
    }

    public function testPagination(): void
    {
        $this->seed();
        for ($i = 1; $i <= 7; $i++) {
            $this->users->insert(['name' => sprintf('User %02d', $i), 'email' => "u$i@example.com", 'age' => $i]);
        }
        $page = $this->users->paginate(2, 4, ['order' => ['name' => 'ASC']]);
        $this->assertSame(10, $page->total);
        $this->assertSame(3, $page->lastPage);
        $this->assertSame(['User 02', 'User 03', 'User 04', 'User 05'], $this->names($page->data)); // Ada, Bob, Cyd, User 01 on page 1
        $this->assertTrue($page->hasMorePages());
        $last = $this->users->paginate(3, 4, ['order' => ['name' => 'ASC']]);
        $this->assertSame(['User 06', 'User 07'], $this->names($last->data));
        $this->assertFalse($last->hasMorePages());
        $filtered = $this->users->paginate(1, 2, ['where' => ['age' => ['<=', 3]], 'order' => ['age' => 'DESC']]);
        $this->assertSame(3, $filtered->total);
        $this->assertSame(['User 03', 'User 02'], $this->names($filtered->data));
    }

    // ---- relations ----------------------------------------------------------------------------------

    public function testRelations(): void
    {
        $this->seed();

        // relations are only loaded when asked for
        $plain = $this->posts->findOneByOrFail(['title' => 'Hello']);
        $this->assertFalse(isset($plain->user));
        $this->assertFalse(isset($plain->tags));

        $posts = $this->posts->find(['relations' => ['user', 'tags'], 'order' => ['id' => 'ASC']]);
        $this->assertSame('Ada', $posts[0]->user->name);
        $this->assertSame($posts[0]->user, $posts[1]->user);
        $this->assertSame(['php', 'orm'], array_map(fn (Tag $t) => $t->name, $posts[0]->tags));
        $this->assertSame([], $posts[2]->tags);

        $users = $this->users->find(['relations' => ['posts.tags', 'profile'], 'order' => ['name' => 'ASC']]);
        $this->assertCount(2, $users[0]->posts);
        $this->assertSame(['php', 'orm'], array_map(fn (Tag $t) => $t->name, $users[0]->posts[0]->tags));
        $this->assertSame('Ada bio', $users[0]->profile->bio);
        $this->assertNull($users[1]->profile);

        $tags = $this->tags->find(['relations' => ['posts'], 'order' => ['name' => 'ASC']]);
        $this->assertSame(['Hello'], array_map(fn (Post $p) => $p->title, $tags[0]->posts)); // orm

        $profile = $this->ds->getRepository(Profile::class)->findOneOrFail(['relations' => ['user']]);
        $this->assertSame('Ada', $profile->user->name);

        // ManyToMany sync on save
        $post = $this->posts->findOneOrFail(['where' => ['title' => 'Hello'], 'relations' => ['tags']]);
        $sql = $this->tags->findOneByOrFail(['name' => 'sql']);
        $post->tags = [$post->tags[0], $sql];
        $this->posts->save($post);
        $reloaded = $this->posts->findOneOrFail(['where' => ['id' => $post->id], 'relations' => ['tags']]);
        $this->assertSame(['php', 'sql'], array_map(fn (Tag $t) => $t->name, $reloaded->tags));
        $conn = $this->ds->getConnection();
        $conn->enableQueryLog()->flushQueryLog();
        $this->posts->save($reloaded);
        $this->assertSame(0, $conn->queryCount(), 'unchanged entity + relations: no writes');
        $conn->disableQueryLog();

        // OneToMany cascade insert
        $cyd = $this->users->findOneByOrFail(['name' => 'Cyd']);
        $cyd->posts = [$this->posts->create(['title' => 'Cyd 1']), $this->posts->create(['title' => 'Cyd 2'])];
        $this->users->save($cyd);
        $this->assertSame(2, $this->posts->countBy(['user' => $cyd]));

        // ON DELETE CASCADE through the foreign keys
        $this->users->delete(['name' => 'Ada']);
        $this->assertSame(0, $this->posts->countBy(['title' => 'Hello']));
        $this->assertSame(0, (int) $conn->fetchColumn('SELECT COUNT(*) FROM ' . $this->q('post_tags') . ' WHERE ' . $this->q('post_id') . ' = :id', ['id' => $post->id]));
        $this->assertSame(0, $this->ds->getRepository(Profile::class)->count());
    }

    // ---- polymorphic and through relations ---------------------------------------------------------

    /** The relation fixtures on their own DataSource, sharing this test's connection. */
    private function relationsDataSource(array $entities = RelationsSeeder::ENTITIES): DataSource
    {
        MorphMap::clear();
        $ds = new DataSource(['entities' => $entities, 'morphMap' => RelationsSeeder::MORPH_MAP]);
        $ds->useConnection($this->ds->getConnection());
        $ds->synchronize();

        return $ds;
    }

    public function testPolymorphicRelations(): void
    {
        $rds = $this->relationsDataSource();
        $em = $rds->getEntityManager();
        $conn = $rds->getConnection();
        $e = RelationsSeeder::seed($em);
        $col = fn (string $c) => $this->q($c);

        // schema: type + id columns, composite index, pivot table; a second synchronize is a no-op
        $schema = $rds->schema();
        $this->assertSame(['id', 'body', 'commentable_type', 'commentable_id'], $schema->columns('comments'));
        $this->assertSame(['label_id', 'labelable_type', 'labelable_id'], $schema->columns('labelables'));
        $types = array_column($schema->columnDetails('comments'), 'type', 'name');
        $this->assertStringStartsWith(match (static::platform()) { 'pgsql' => 'int8', 'sqlite' => 'integer', default => 'bigint' }, strtolower($types['commentable_id']));
        $this->assertStringStartsWith('varchar', strtolower($types['commentable_type']));
        $conn->enableQueryLog()->flushQueryLog();
        $rds->synchronize();
        $ddl = array_filter(array_column($conn->getQueryLog(), 'sql'), fn (string $sql) => preg_match('/^\s*(CREATE|ALTER|DROP)\b/i', $sql) === 1);
        $this->assertSame([], array_values($ddl));

        // aliases: morphMap option ("video"), #[MorphAlias] ("article"), class name otherwise
        $this->assertSame(['article', 'video'], array_column($conn->fetchAll('SELECT DISTINCT ' . $col('commentable_type') . ' AS t FROM ' . $col('comments') . ' WHERE ' . $col('commentable_type') . ' IS NOT NULL ORDER BY t'), 't'));
        $this->assertSame(['article', Writer::class], array_column($conn->fetchAll('SELECT ' . $col('imageable_kind') . ' AS k FROM ' . $col('images') . ' ORDER BY ' . $col('id')), 'k'));

        // MorphTo: one query per type
        $conn->flushQueryLog();
        $comments = $em->find(Comment::class, ['relations' => ['commentable']]);
        $this->assertSame(3, $conn->queryCount());
        $this->assertSame(['A1', 'A1', 'A2', 'V1', 'V1'], array_map(fn (Comment $c) => $c->commentable->title, array_slice($comments, 0, 5)));
        $this->assertInstanceOf(Video::class, $comments[3]->commentable);
        $this->assertNull($comments[5]->commentable);

        // MorphMany / MorphOne / MorphToMany are scoped by type (article 1 and video 1 share id 1) + nested paths
        $conn->flushQueryLog();
        $articles = $em->find(Article::class, ['relations' => ['comments.commentable', 'cover', 'labels', 'country'], 'order' => ['id' => 'ASC']]);
        $this->assertSame(6, $conn->queryCount());
        $this->assertSame(['a1-1', 'a1-2'], self::pluck($articles[0]->comments, 'body'));
        $this->assertSame($articles[0]->comments[0]->commentable, $articles[0]->comments[1]->commentable);
        $this->assertSame('A1', $articles[0]->comments[0]->commentable->title);
        $this->assertSame('a1.png', $articles[0]->cover->url);
        $this->assertNull($articles[1]->cover);
        $this->assertSame(['php', 'orm'], self::pluck($articles[0]->labels, 'name'));
        $this->assertSame('Iraq', $articles[0]->country->name);
        $videos = $em->find(Video::class, ['relations' => ['comments', 'labels'], 'order' => ['id' => 'ASC']]);
        $this->assertSame(['v1-1', 'v1-2'], self::pluck($videos[0]->comments, 'body'));
        $this->assertSame(['php'], self::pluck($videos[0]->labels, 'name'));
        $this->assertSame('ada.png', $em->findOneOrFail(Writer::class, ['where' => ['name' => 'Ada'], 'relations' => ['avatar']])->avatar->url);
        $php = $em->findOneOrFail(Label::class, ['where' => ['name' => 'php'], 'relations' => ['articles', 'videos.comments']]);
        $this->assertSame(['A1'], self::pluck($php->articles, 'title'));
        $this->assertSame(['v1-1', 'v1-2'], self::pluck($php->videos[0]->comments, 'body'));

        // saving: MorphTo sets both columns, pivots sync per type, unchanged lists write nothing
        $comment = $em->findOneOrFail(Comment::class, ['where' => ['body' => 'orphan']]);
        $comment->commentable = $e['v2'];
        $em->save($comment);
        $this->assertSame(['v2' => ['video', 2]], ['v2' => array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, array_values($conn->fetchOne('SELECT ' . $col('commentable_type') . ', ' . $col('commentable_id') . ' FROM ' . $col('comments') . ' WHERE ' . $col('id') . ' = :id', ['id' => $comment->id])))]);
        $a1 = $articles[0];
        $a1->labels = [$e['sql']];
        $em->save($a1);
        $this->assertSame(['sql'], self::pluck($em->findOneOrFail(Article::class, ['where' => ['id' => 1], 'relations' => ['labels']])->labels, 'name'));
        $this->assertSame(['php'], self::pluck($em->findOneOrFail(Video::class, ['where' => ['id' => 1], 'relations' => ['labels']])->labels, 'name'));
        $conn->flushQueryLog();
        $em->save($a1);
        $this->assertSame(0, $conn->queryCount());
        $v2 = $em->findOneOrFail(Video::class, ['where' => ['id' => 2], 'relations' => ['comments']]);
        $v2->comments[] = $em->create(Comment::class, ['body' => 'v2-new']);
        $em->save($v2);
        $this->assertSame(['orphan', 'v2-new'], self::pluck($em->find(Comment::class, ['where' => ['commentable' => $e['v2']], 'order' => ['id' => 'ASC']]), 'body'));

        // query builder joins with the type condition
        $joined = $rds->createQueryBuilder(Article::class, 'a')->leftJoinAndSelect('a.comments', 'c')->leftJoinAndSelect('a.labels', 'l')
            ->orderBy('a.id')->addOrderBy('c.id')->getMany();
        $this->assertSame(['a1-1', 'a1-2'], self::pluck($joined[0]->comments, 'body'));
        $this->assertSame(['sql'], self::pluck($joined[0]->labels, 'name'));
        $this->assertSame([], $joined[2]->comments);

        // deleting a label cascades its pivot rows
        $em->getRepository(Label::class)->delete(['name' => 'php']);
        $this->assertSame(0, (int) $conn->fetchColumn('SELECT COUNT(*) FROM ' . $col('labelables') . ' WHERE ' . $col('label_id') . ' = :id', ['id' => $e['php']->id]));
        $conn->disableQueryLog();
    }

    public function testThroughRelations(): void
    {
        $rds = $this->relationsDataSource();
        $em = $rds->getEntityManager();
        $conn = $rds->getConnection();
        $e = RelationsSeeder::seed($em);

        $conn->enableQueryLog()->flushQueryLog();
        $countries = $em->find(Country::class, ['relations' => ['articles.comments'], 'order' => ['id' => 'ASC']]);
        $this->assertSame(3, $conn->queryCount());
        $this->assertSame(['A1', 'A2', 'A3'], self::pluck($countries[0]->articles, 'title'));
        $this->assertSame(['a1-1', 'a1-2'], self::pluck($countries[0]->articles[0]->comments, 'body'));
        $this->assertSame(['A4'], self::pluck($countries[1]->articles, 'title'));
        $this->assertSame([], $countries[2]->articles);

        $mechanics = $em->find(Mechanic::class, ['relations' => ['carOwner'], 'order' => ['id' => 'ASC']]);
        $this->assertSame('Ow', $mechanics[0]->carOwner->name);
        $this->assertNull($mechanics[1]->carOwner);
        $this->assertSame('Mo', $em->findOneOrFail(Owner::class, ['relations' => ['mechanic']])->mechanic->name);

        $conn->flushQueryLog();
        $paragraphs = $em->find(Paragraph::class, ['relations' => ['country.writers', 'countryNearFirst', 'writer'], 'order' => ['id' => 'ASC']]);
        $this->assertSame(5, $conn->queryCount());
        $this->assertSame(['Iraq', 'Iraq', 'Japan'], array_map(fn (Paragraph $p) => $p->country->name, $paragraphs));
        $this->assertSame(['Iraq', 'Iraq', 'Japan'], array_map(fn (Paragraph $p) => $p->countryNearFirst->name, $paragraphs));
        $this->assertSame(['Ada', 'Bob'], self::pluck($paragraphs[0]->country->writers, 'name'));
        $this->assertSame(['Ada', 'Bob', 'Kei'], array_map(fn (Paragraph $p) => $p->writer->name, $paragraphs));

        // soft-deleted intermediates are skipped unless withDeleted
        $em->getRepository(Writer::class)->softDelete($e['bob']);
        $this->assertSame(['A1', 'A2'], self::pluck($em->findOneOrFail(Country::class, ['where' => ['name' => 'Iraq'], 'relations' => ['articles']])->articles, 'title'));
        $this->assertSame(['A1', 'A2', 'A3'], self::pluck($em->findOneOrFail(Country::class, ['where' => ['name' => 'Iraq'], 'relations' => ['articles'], 'withDeleted' => true])->articles, 'title'));

        // query builder: joins through the intermediate tables, also paginated
        $page = $rds->createQueryBuilder(Country::class, 'c')->leftJoinAndSelect('c.articles', 'a')->orderBy('c.id')->addOrderBy('a.id')->take(2)->getMany();
        $this->assertSame(['Iraq', 'Japan'], self::pluck($page, 'name'));
        $this->assertSame(['A1', 'A2'], self::pluck($page[0]->articles, 'title'));
        $inJapan = $rds->createQueryBuilder(Paragraph::class, 'p')->innerJoinAndSelect('p.country', 'c')->where('c.name = :n', ['n' => 'Japan'])->getMany();
        $this->assertSame(['P3'], self::pluck($inJapan, 'text'));
        $conn->disableQueryLog();
    }

    public function testPolymorphicKeysOfDifferentTypes(): void
    {
        $rds = $this->relationsDataSource([MorphUuidDoc::class, MorphIntDoc::class, MorphNote::class, MorphUuidOnlyNote::class]);
        $types = fn (string $table) => array_map('strtolower', array_column($rds->schema()->columnDetails($table), 'type', 'name'));
        $this->assertStringStartsWith('varchar', $types('morph_notes')['notable_id']);
        $this->assertStringStartsWith(static::platform() === 'pgsql' ? 'uuid' : 'char', $types('morph_uuid_only_notes')['notable_id']);
        $this->assertStringStartsWith(static::platform() === 'pgsql' ? 'uuid' : 'char', $types('morph_uuid_docs_tags')['owner_id']);

        $em = $rds->getEntityManager();
        $uuidDoc = $em->save($em->create(MorphUuidDoc::class, ['title' => 'u']));
        $intDoc = $em->save($em->create(MorphIntDoc::class, ['title' => 'i']));
        foreach ([$uuidDoc, $intDoc] as $doc) {
            $note = $em->create(MorphNote::class, ['text' => $doc->title]);
            $note->notable = $doc;
            $em->save($note);
        }
        $only = $em->create(MorphUuidOnlyNote::class);
        $only->doc = $uuidDoc;
        $em->save($only);
        $uuidDoc->tags = [$intDoc];
        $em->save($uuidDoc);

        $notes = $em->find(MorphNote::class, ['relations' => ['notable'], 'order' => ['id' => 'ASC']]);
        $this->assertSame($uuidDoc->id, $notes[0]->notable->id);
        $this->assertSame($intDoc->id, $notes[1]->notable->id);
        $this->assertSame(['u'], self::pluck($em->findOneOrFail(MorphUuidDoc::class, ['relations' => ['notes']])->notes, 'text'));
        $this->assertSame(['i'], self::pluck($em->findOneOrFail(MorphIntDoc::class, ['relations' => ['notes']])->notes, 'text'));
        $this->assertSame($uuidDoc->id, $em->findOneOrFail(MorphUuidOnlyNote::class, ['relations' => ['doc']])->doc->id);
        $this->assertSame(['i'], self::pluck($em->findOneOrFail(MorphUuidDoc::class, ['relations' => ['tags']])->tags, 'title'));
        $this->assertSame(['u'], self::pluck($em->findOneOrFail(MorphIntDoc::class, ['relations' => ['owners']])->owners, 'title'));
    }

    // ---- transactions -------------------------------------------------------------------------------

    public function testTransactionsAndSavepoints(): void
    {
        try {
            $this->ds->transaction(function (EntityManager $em): void {
                $em->save($em->create(User::class, ['name' => 'T', 'email' => 't@example.com']));
                $this->assertSame(1, $em->count(User::class));
                throw new \RuntimeException('fail');
            });
            $this->fail('expected exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('fail', $e->getMessage());
        }
        $this->assertSame(0, $this->users->count());

        $user = $this->ds->transaction(fn (EntityManager $em) => $em->save($em->create(User::class, ['name' => 'C', 'email' => 'c@example.com'])));
        $this->assertSame(1, $this->users->count());
        $this->assertGreaterThan(0, $user->id);

        $conn = $this->ds->getConnection();
        $this->ds->transaction(function (EntityManager $em) use ($conn): void {
            $em->save($em->create(User::class, ['name' => 'Outer', 'email' => 'o@example.com']));
            try {
                $this->ds->transaction(function (EntityManager $em) use ($conn): void {
                    $this->assertSame(2, $conn->transactionDepth());
                    $em->save($em->create(User::class, ['name' => 'Inner', 'email' => 'i@example.com']));
                    try {
                        $this->ds->transaction(function (EntityManager $em): void {
                            $em->save($em->create(User::class, ['name' => 'Deepest', 'email' => 'd@example.com']));
                            throw new \LogicException('deepest');
                        });
                    } catch (\LogicException) {
                    }
                    $this->assertSame(2, $conn->transactionDepth());
                    throw new \LogicException('inner');
                });
            } catch (\LogicException) {
            }
            // a failed statement inside a savepoint (PostgreSQL aborts the transaction until ROLLBACK TO)
            try {
                $this->ds->transaction(fn (EntityManager $em) => $em->save($em->create(User::class, ['name' => 'Dup', 'email' => 'o@example.com'])));
                $this->fail('duplicate email must fail');
            } catch (QueryException) {
            }
            $em->save($em->create(User::class, ['name' => 'After', 'email' => 'after@example.com']));
            $this->assertSame(1, $conn->transactionDepth());
        });
        $this->assertFalse($conn->inTransaction());
        $this->assertSame(['After', 'C', 'Outer'], $this->names($this->users->find(['order' => ['name' => 'ASC']])));

        $conn->beginTransaction();
        $this->users->insert(['name' => 'M', 'email' => 'm@example.com']);
        $conn->rollBack();
        $this->assertSame(0, $this->users->countBy(['name' => 'M']));
        $conn->beginTransaction();
        $this->users->insert(['name' => 'M', 'email' => 'm@example.com']);
        $conn->commit();
        $this->assertSame(1, $this->users->countBy(['name' => 'M']));
    }

    // ---- migrations ---------------------------------------------------------------------------------

    public function testMigrationsUpRevertAndStatus(): void
    {
        $ds = new DataSource(['migrations' => [CreateAuthorsMigration::class, new CreateBooksMigration]]);
        $ds->useConnection($this->ds->getConnection());
        $schema = $ds->schema();

        $this->assertSame([false, false], array_column($ds->showMigrations(), 'ran'));
        $this->assertSame([CreateAuthorsMigration::class, CreateBooksMigration::class], $ds->runMigrations());
        $this->assertTrue($schema->hasTable('authors'));
        $this->assertTrue($schema->hasTable('books'));
        $this->assertSame(
            ['id', 'title', 'summary', 'pages', 'isbn', 'weight', 'published_on', 'printed_at', 'archived_at', 'author_id', 'tagline'],
            $schema->columns('books'),
        );
        $this->assertSame([1, 1], array_column($ds->showMigrations(), 'batch'));
        $this->assertSame([], $ds->runMigrations());

        $conn = $ds->getConnection();
        $conn->execute('INSERT INTO ' . $this->q('authors') . ' (name, email) VALUES (:n, :e)', ['n' => 'A', 'e' => 'a@x']);
        $row = $conn->fetchOne('SELECT * FROM ' . $this->q('authors'));
        $this->assertEquals(1, (int) $row['active']);
        $this->assertSame('writer', $row['role']);
        $this->assertEquals(0, (float) $row['rating']);
        $conn->execute(
            'INSERT INTO ' . $this->q('books') . ' (id, title, author_id, published_on) VALUES (:id, :t, :a, :d)',
            ['id' => Repository::uuid(), 't' => 'Book', 'a' => $row['id'], 'd' => '2020-01-02'],
        );
        $this->assertSame(1, (int) $conn->fetchColumn('SELECT COUNT(*) FROM ' . $this->q('books')));

        $this->assertSame(CreateBooksMigration::class, $ds->revertLastMigration());
        $this->assertFalse($schema->hasTable('books'));
        $this->assertSame([true, false], array_column($ds->showMigrations(), 'ran'));
        $this->assertSame([CreateBooksMigration::class], $ds->runMigrations());
        $this->assertSame([1, 2], array_column($ds->showMigrations(), 'batch'));
        $this->assertSame([CreateBooksMigration::class], $ds->migrator()->rollbackBatch());
        $this->assertSame(CreateAuthorsMigration::class, $ds->revertLastMigration());
        $this->assertNull($ds->revertLastMigration());
        $this->assertFalse($schema->hasTable('authors'));
    }

    // ---- errors / reconnect -------------------------------------------------------------------------

    public function testUniqueViolationSurfacesAsQueryException(): void
    {
        $this->users->insert(['name' => 'Ada', 'email' => 'ada@example.com']);
        try {
            $this->users->save($this->users->create(['name' => 'Other', 'email' => 'ada@example.com']));
            $this->fail('expected a QueryException');
        } catch (QueryException $e) {
            $this->assertInstanceOf(\PDOException::class, $e->getPrevious());
            $this->assertStringContainsString('INSERT INTO', $e->sql);
            $this->assertMatchesRegularExpression('/unique|duplicate/i', $e->getMessage());
        }
        $this->assertFalse($this->ds->getConnection()->inTransaction());
        $this->assertSame(1, $this->users->count(), 'connection still usable after the error');
    }

    public function testReconnectsAfterTheServerKilledTheConnection(): void
    {
        if (static::platform() === 'sqlite') {
            $this->markTestSkipped('SQLite has no server connection to lose.');
        }
        $conn = $this->ds->getConnection();
        $this->users->insert(['name' => 'Ada', 'email' => 'ada@example.com']);
        $before = $this->backendId($conn);

        $this->killFromAnotherConnection($before);
        // outside a transaction the next statement transparently reconnects
        $this->assertSame(1, $this->users->count());
        $after = $this->backendId($conn);
        $this->assertNotSame($before, $after);

        // inside a transaction it must NOT silently retry on a fresh connection
        $conn->beginTransaction();
        $this->users->insert(['name' => 'Bob', 'email' => 'bob@example.com']);
        $this->killFromAnotherConnection($after);
        try {
            $this->users->count();
            $this->fail('expected a QueryException inside the transaction');
        } catch (QueryException $e) {
            $this->assertTrue(Connection::isLostConnection($e->getPrevious()), $e->getMessage());
        }
        $this->assertSame($after, $this->backendIdOrNull($conn) ?? $after, 'no reconnect happened mid-transaction');
        try {
            $conn->rollBack();
        } catch (\Throwable) {
            // the old connection is dead; rolling back may fail
        }
        $this->assertFalse($conn->inTransaction());
        $this->assertSame(1, $this->users->count(), 'reconnected again; the transaction was lost');
    }

    private function backendId(Connection $conn): int
    {
        return (int) $conn->fetchColumn($conn->driver() === 'pgsql' ? 'SELECT pg_backend_pid()' : 'SELECT CONNECTION_ID()');
    }

    private function backendIdOrNull(Connection $conn): ?int
    {
        try {
            return (int) $conn->pdo()->query($conn->driver() === 'pgsql' ? 'SELECT pg_backend_pid()' : 'SELECT CONNECTION_ID()')->fetchColumn();
        } catch (\Throwable) {
            return null;
        }
    }

    private function killFromAnotherConnection(int $id): void
    {
        $killer = Connection::fromOptions(static::options() ?? []);
        if ($killer->driver() === 'pgsql') {
            $killer->fetchColumn('SELECT pg_terminate_backend(:pid)', ['pid' => $id]);
        } else {
            $killer->pdo()->exec('KILL ' . $id);
        }
        // wait until the server has really dropped it
        for ($i = 0; $i < 50; $i++) {
            $alive = $killer->driver() === 'pgsql'
                ? (int) $killer->fetchColumn('SELECT COUNT(*) FROM pg_stat_activity WHERE pid = :pid', ['pid' => $id])
                : (int) $killer->fetchColumn('SELECT COUNT(*) FROM information_schema.processlist WHERE id = :id', ['id' => $id]);
            if ($alive === 0) {
                return;
            }
            usleep(20_000);
        }
    }

    // ---- helpers ------------------------------------------------------------------------------------

    protected function seed(): void
    {
        $ada = $this->users->create(['name' => 'Ada', 'email' => 'ada@example.com', 'age' => 36]);
        $bob = $this->users->create(['name' => 'Bob', 'email' => 'bob@example.com', 'age' => 22, 'active' => false]);
        $cyd = $this->users->create(['name' => 'Cyd', 'email' => 'cyd@example.com', 'age' => 41]);
        $this->users->save([$ada, $bob, $cyd]);

        $php = $this->tags->save($this->tags->create(['name' => 'php']));
        $orm = $this->tags->save($this->tags->create(['name' => 'orm']));
        $sql = $this->tags->save($this->tags->create(['name' => 'sql']));

        $p1 = $this->posts->create(['title' => 'Hello', 'status' => PostStatus::Published, 'views' => 10, 'price' => 9.5]);
        $p1->user = $ada;
        $p1->tags = [$php, $orm];
        $p2 = $this->posts->create(['title' => 'Second', 'views' => 3]);
        $p2->user = $ada;
        $p2->tags = [$sql];
        $p3 = $this->posts->create(['title' => 'Bob writes', 'status' => PostStatus::Published, 'views' => 7]);
        $p3->user = $bob;
        $p3->tags = [];
        $this->posts->save([$p1, $p2, $p3]);

        $profiles = $this->ds->getRepository(Profile::class);
        $profile = $profiles->create(['bio' => 'Ada bio']);
        $profile->user = $ada;
        $profiles->save($profile);
    }

    /** @param iterable<object> $entities @return list<string> */
    private function names(iterable $entities): array
    {
        $out = [];
        foreach ($entities as $e) {
            $out[] = $e->name;
        }

        return $out;
    }

    /** @param iterable<object> $entities @return list<mixed> */
    private static function pluck(iterable $entities, string $property): array
    {
        $out = [];
        foreach ($entities as $e) {
            $out[] = $e->{$property};
        }

        return $out;
    }

    private function q(string $identifier): string
    {
        return $this->ds->getConnection()->quote($identifier);
    }
}
