<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Drivers;

use Nesto\Orm\DataSource;
use Nesto\Orm\Migration\Blueprint;
use Nesto\Orm\Migration\MigrationGenerator;
use Nesto\Orm\Migration\Migrator;
use Nesto\Orm\OrmException;
use Nesto\Orm\Schema\Comparison\Comparator;
use Nesto\Tests\Orm\Fixtures\Post;
use Nesto\Tests\Orm\Fixtures\Profile;
use Nesto\Tests\Orm\Fixtures\User;
use Nesto\Tests\Orm\Fixtures\WriteSafety\WsBook;
use Nesto\Tests\Orm\Fixtures\WriteSafety\WsShelf;

/**
 * Write paths that must never touch more than asked: empty criteria, saving entities that were never loaded,
 * cascades from ids / partial children, relation values in update(), Blueprint ->change(), and options that
 * used to be ignored (upsertType, indexPredicate, save reload / chunk, NULLS FIRST / LAST). Used by
 * DriverTestCase; the ws_* fixtures live on their own DataSource (dropped after each scenario).
 */
trait WriteSafetyScenarios
{
    private ?DataSource $wsDs = null;

    private function wsDataSource(): DataSource
    {
        $this->wsDs = new DataSource(static::options() + ['entities' => [WsShelf::class, WsBook::class], 'dropSchema' => true, 'synchronize' => true]);

        return $this->wsDs->initialize();
    }

    #[\PHPUnit\Framework\Attributes\After]
    protected function dropWriteSafetySchema(): void
    {
        if ($this->wsDs !== null) {
            $this->wsDs->dropDatabase();
            $this->wsDs->destroy();
            $this->wsDs = null;
        }
    }

    // ---- empty criteria ---------------------------------------------------------------------------

    public function testEmptyCriteriaAreRejectedByTheRepositoryAndManagerWriteMethods(): void
    {
        $this->seed();
        $em = $this->ds->getEntityManager();
        $before = $this->ds->getConnection()->fetchAll('SELECT * FROM ' . $this->q('users') . ' ORDER BY id');
        $cases = [
            'update' => [
                fn () => $this->users->update([], ['age' => 1]),
                fn () => $this->users->update(null, ['age' => 1]),
                fn () => $this->users->update('', ['age' => 1]),
                fn () => $this->users->update([null], ['age' => 1]),
                fn () => $this->users->update([[]], ['age' => 1]),
                fn () => $em->update(User::class, [], ['age' => 1]),
            ],
            'delete' => [
                fn () => $this->users->delete([]),
                fn () => $this->users->delete(null),
                fn () => $this->users->delete(''),
                fn () => $this->users->delete(['', 1]),
                fn () => $em->delete(User::class, []),
            ],
            'softDelete' => [
                fn () => $this->users->softDelete([]),
                fn () => $this->users->softDelete($this->users->create(['name' => 'never saved'])),
                fn () => $em->softDelete(User::class, []),
            ],
            'restore' => [
                fn () => $this->users->restore([]),
                fn () => $em->restore(User::class, null),
            ],
            'increment' => [
                fn () => $this->users->increment([], 'age'),
                fn () => $em->increment(User::class, [], 'age'),
            ],
            'decrement' => [
                fn () => $this->users->decrement([], 'age', 2),
                fn () => $em->decrement(User::class, [], 'age'),
            ],
        ];
        foreach ($cases as $method => $calls) {
            foreach ($calls as $i => $call) {
                try {
                    $call();
                    $this->fail("$method #$i must refuse empty criteria");
                } catch (OrmException $e) {
                    $this->assertSame("Empty criteria(s) are not allowed for the $method method.", $e->getMessage(), "$method #$i");
                }
            }
        }
        $this->assertSame($before, $this->ds->getConnection()->fetchAll('SELECT * FROM ' . $this->q('users') . ' ORDER BY id'), 'no row was touched');

        // ids: a scalar, a list of ids (TypeORM's whereInIds), a list of criteria (OR)
        $ids = $this->ds->getConnection()->fetchAll('SELECT id FROM ' . $this->q('users') . ' ORDER BY id');
        [$ada, $bob, $cyd] = array_map(fn (array $r) => (int) $r['id'], $ids);
        $this->assertSame(2, $this->users->update([$ada, $bob], ['age' => 50]));
        $this->assertSame(1, $this->users->update([['name' => 'Cyd'], ['id' => -1]], ['age' => 51]));
        $this->assertSame(1, $this->users->increment([$cyd], 'age', 1));
        $this->assertSame([50, 50, 52], array_map('intval', array_column($this->ds->getConnection()->fetchAll('SELECT age FROM ' . $this->q('users') . ' ORDER BY id'), 'age')));

        // the QueryBuilder keeps TypeORM's behaviour: an UPDATE / DELETE without where() covers every row
        $this->assertSame(3, $this->users->createQueryBuilder('u')->update()->set(['age' => 9])->execute());
        $this->assertSame(3, $this->users->countBy(['age' => 9]));
    }

    // ---- saving an entity that was never loaded ---------------------------------------------------

    public function testSavingAnEntityThatWasNeverLoadedOnlyWritesWhatItSets(): void
    {
        $this->seed();
        $conn = $this->ds->getConnection();
        $row = fn (string $email): array => $conn->fetchOne('SELECT * FROM ' . $this->q('users') . ' WHERE email = :e', ['e' => $email]);
        $ada = $row('ada@example.com');
        $bob = $row('bob@example.com');

        $u = $this->users->create(['id' => (int) $ada['id'], 'name' => 'Ada L.']);
        $this->users->save($u);
        $after = $row('ada@example.com');
        $this->assertSame('Ada L.', $after['name']);
        $this->assertSame(36, (int) $after['age'], 'the class default (0) is not written');
        $this->assertNotNull($after['created_at'], 'createdAt is kept');
        $this->assertSame((string) $ada['created_at'], (string) $after['created_at']);
        $this->assertSame((int) $ada['active'], (int) $after['active']);
        $this->assertSame(1, $this->ds->getRepository(Profile::class)->countBy(['user' => (int) $ada['id']]), 'the profile (inverse OneToOne left at its default) stays linked');
        $this->assertSame(2, $this->posts->countBy(['user' => (int) $ada['id']]));
        // the untouched properties now hold the stored values: a second save writes only what changes
        $this->assertSame(36, $u->age);
        $this->assertNotNull($u->createdAt);
        $u->name = 'Ada Lovelace';
        $this->users->save($u);
        $this->assertSame(['Ada Lovelace', 36], [$row('ada@example.com')['name'], (int) $row('ada@example.com')['age']]);

        // Bob is inactive: the class default `active = true` must not reactivate him
        $this->users->save($this->users->create(['id' => (int) $bob['id'], 'name' => 'Bobby']));
        $this->assertSame(0, (int) $row('bob@example.com')['active']);
        // a value given explicitly is written, even when it equals the class default
        $this->users->save($this->users->create(['id' => (int) $bob['id'], 'active' => true]));
        $this->assertSame(1, (int) $row('bob@example.com')['active']);
        $this->assertSame('Bobby', $row('bob@example.com')['name']);

        // `new` + assignments behaves the same
        $cyd = $row('cyd@example.com');
        $plain = new User;
        $plain->id = (int) $cyd['id'];
        $plain->age = 42;
        $this->users->save($plain);
        $this->assertSame(['Cyd', 42, (string) $cyd['created_at']], [$row('cyd@example.com')['name'], (int) $row('cyd@example.com')['age'], (string) $row('cyd@example.com')['created_at']]);

        // no row with that id: inserted (TypeORM's save() inserts what it cannot find)
        $new = $this->users->create(['id' => 900, 'name' => 'Zed', 'email' => 'zed@example.com']);
        $this->users->save($new);
        $this->assertSame('Zed', $row('zed@example.com')['name']);
        $this->assertSame(900, (int) $row('zed@example.com')['id']);
    }

    public function testCascadedChildrenGivenByIdOrPartiallyOnlyGetTheirForeignKeyAndTheirOwnValues(): void
    {
        $ds = $this->wsDataSource();
        $shelves = $ds->getRepository(WsShelf::class);
        $books = $ds->getRepository(WsBook::class);
        $conn = $ds->getConnection();
        $shelf = $shelves->save($shelves->create(['name' => 'S', 'color' => 'red']));
        $one = $books->save($books->create(['title' => 'One', 'rating' => 5, 'note' => 'n1']));
        $two = $books->save($books->create(['title' => 'Two', 'rating' => 7]));
        $books->softRemove($two);
        $book = fn (int $id): array => $conn->fetchOne('SELECT * FROM ' . $conn->quote('ws_books') . ' WHERE id = :id', ['id' => $id]);
        $deletedAt = $book($two->id)['deleted_at'];

        // the shelf and its books given by id only
        $shelves->save($shelves->create(['id' => $shelf->id, 'books' => [$one->id, $two->id]]));
        $this->assertSame(['One', 5, 'n1', $shelf->id], [$book($one->id)['title'], (int) $book($one->id)['rating'], $book($one->id)['note'], (int) $book($one->id)['shelf_id']]);
        $this->assertSame(['Two', 7, $shelf->id], [$book($two->id)['title'], (int) $book($two->id)['rating'], (int) $book($two->id)['shelf_id']]);
        $this->assertNotNull($book($two->id)['deleted_at'], 'the soft-deleted child stays deleted');
        $this->assertSame((string) $deletedAt, (string) $book($two->id)['deleted_at']);
        $this->assertNotNull($book($one->id)['created_at']);
        $shelfRow = $conn->fetchOne('SELECT * FROM ' . $conn->quote('ws_shelves'));
        $this->assertSame(['S', 'red'], [$shelfRow['name'], $shelfRow['color']], 'the shelf keeps its columns');
        $this->assertNotNull($shelfRow['created_at']);

        // a partial child: only the properties it sets are written (cascade "update")
        $shelves->save($shelves->create(['id' => $shelf->id, 'books' => [['id' => $one->id, 'rating' => 9]]]));
        $this->assertSame(['One', 9, 'n1', $shelf->id, 18], [$book($one->id)['title'], (int) $book($one->id)['rating'], $book($one->id)['note'], (int) $book($one->id)['shelf_id'], (int) $book($one->id)['doubled']]);
        $this->assertNull($book($two->id)['shelf_id'], 'left out of the collection: orphaned (nullify)');
        $this->assertNotNull($book($two->id)['deleted_at']);
    }

    // ---- relation values in update() / QueryBuilder set() -----------------------------------------

    public function testUpdateWritesTheForeignKeyOfRelationValues(): void
    {
        $this->seed();
        $conn = $this->ds->getConnection();
        $ada = $this->users->findOneByOrFail(['name' => 'Ada']);
        $bob = $this->users->findOneByOrFail(['name' => 'Bob']);
        $hello = $this->posts->findOneByOrFail(['title' => 'Hello']);
        $owner = fn (): int => (int) $conn->fetchColumn('SELECT user_id FROM ' . $this->q('posts') . ' WHERE id = :id', ['id' => $hello->id]);

        $this->assertSame(1, $this->posts->update($hello->id, ['user' => ['id' => $bob->id]]));
        $this->assertSame($bob->id, $owner());
        $this->posts->update($hello->id, ['user' => $ada]);
        $this->assertSame($ada->id, $owner());
        $this->posts->update($hello->id, ['user' => $bob->id]);
        $this->assertSame($bob->id, $owner());
        $this->ds->getEntityManager()->update(Post::class, $hello->id, ['user' => ['id' => $ada->id]]);
        $this->assertSame($ada->id, $owner());
        $this->posts->createQueryBuilder('p')->update()->set(['user' => ['id' => $bob->id], 'views' => 99])->where(['id' => $hello->id])->execute();
        $this->assertSame($bob->id, $owner());
        $this->assertSame(99, (int) $conn->fetchColumn('SELECT views FROM ' . $this->q('posts') . ' WHERE id = :id', ['id' => $hello->id]));
        // criteria by relation, value by entity: every post of Bob moves to Ada
        $this->assertSame(2, $this->posts->update(['user' => $bob], ['user' => $ada]));
        $this->assertSame(3, $this->posts->countBy(['user' => $ada]));

        try {
            $this->posts->update($hello->id, ['user' => ['title' => 'no key']]);
            $this->fail('a relation value without the referenced key');
        } catch (OrmException $e) {
            $this->assertStringContainsString('user', $e->getMessage());
        }
        try {
            $this->users->update($ada->id, ['posts' => [1, 2]]);
            $this->fail('the inverse side has no column');
        } catch (OrmException $e) {
            $this->assertStringContainsString('posts', $e->getMessage());
        }
        // QueryBuilder insert values: same conversions
        $this->posts->createQueryBuilder('p')->insert()->values(['title' => 'Inserted', 'views' => 1, 'price' => 1, 'status' => 'draft', 'user' => ['id' => $bob->id]])->execute();
        $this->assertSame($bob->id, (int) $conn->fetchColumn('SELECT user_id FROM ' . $this->q('posts') . " WHERE title = 'Inserted'"));
    }

    // ---- Blueprint ->change() ---------------------------------------------------------------------

    public function testBlueprintChangeKeepsTheAttributesItDoesNotRepeat(): void
    {
        $schema = $this->ds->schema();
        $schema->createTable('authors', function (Blueprint $t): void {
            $t->id();
            $t->integer('votes')->unsigned()->nullable()->default(3)->comment('Up votes');
            $t->string('name', 50)->nullable();
        });
        $schema->table('authors', fn (Blueprint $t) => $t->bigInteger('votes')->change());
        $votes = $schema->introspect('authors')->column('votes');
        $comparator = new Comparator($schema->grammar());
        $this->assertFalse($comparator->columnChanged($votes, (new Blueprint('x'))->bigInteger('votes')->unsigned()->nullable()->default(3)->comment('Up votes')), 'only the type changed');

        // listed attributes do change; noDefault() / comment(null) / nullable(false) remove
        $schema->table('authors', function (Blueprint $t): void {
            $t->bigInteger('votes')->nullable(false)->noDefault()->comment(null)->change();
            $t->string('name', 80)->change();
        });
        $table = $schema->introspect('authors');
        $this->assertFalse($comparator->columnChanged($table->column('votes'), (new Blueprint('x'))->bigInteger('votes')->unsigned()));
        $this->assertFalse($comparator->columnChanged($table->column('name'), (new Blueprint('x'))->string('name', 80)->nullable()), 'still nullable');
    }

    public function testGeneratedMigrationsCarryTheWholeColumnDefinition(): void
    {
        $schema = $this->ds->schema();
        $schema->createTable('authors', function (Blueprint $t): void {
            $t->id();
            $t->integer('votes')->unsigned()->nullable()->default(3)->comment('Up votes');
            $t->string('name', 50)->comment('Shown');
        });
        $wanted = new Blueprint('authors');
        $wanted->id();
        $wanted->bigInteger('votes')->unsigned()->comment('Up votes');   // NOT NULL, no default now
        $wanted->string('name', 50);                                     // no comment now
        $dir = sys_get_temp_dir() . '/nesto-ws-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $generator = new MigrationGenerator($schema);
            $class = 'WsChange' . ucfirst(static::platform()) . bin2hex(random_bytes(3));
            $code = (string) $generator->generate(['authors' => $wanted->toTable()], $class);
            $this->assertStringContainsString("\$table->bigInteger('votes')->nullable(false)->unsigned()->noDefault()->comment('Up votes')->change();", $code);
            if (static::platform() !== 'sqlite') {
                $this->assertStringContainsString("\$table->string('name', 50)->comment(null)->change();", $code);
            }
            file_put_contents("$dir/2026_01_01_000000_ws.php", $code);
            $migrator = new Migrator($this->ds->getConnection(), [$dir], 'ws_migrations');
            $migrator->run();
            $this->assertNull($generator->generate(['authors' => $wanted->toTable()], $class . 'Again'), "nothing left to generate\n$code");
            $migrator->revert();
            $votes = $schema->introspect('authors')->column('votes');
            $this->assertFalse((new Comparator($schema->grammar()))->columnChanged($votes, (new Blueprint('x'))->integer('votes')->unsigned()->nullable()->default(3)->comment('Up votes')), "down() restores\n$code");
        } finally {
            array_map('unlink', glob("$dir/*.php") ?: []);
            rmdir($dir);
            $schema->dropTableIfExists('ws_migrations');
        }
    }

    // ---- options that used to be ignored ----------------------------------------------------------

    public function testUpsertTypeAndIndexPredicate(): void
    {
        $ds = $this->wsDataSource();
        $books = $ds->getRepository(WsBook::class);
        $mysql = in_array(static::platform(), ['mysql', 'mariadb'], true);
        $native = $mysql ? 'on-duplicate-key-update' : 'on-conflict-do-update';
        $books->upsert([['title' => 'A', 'rating' => 1]], ['conflictPaths' => ['title'], 'upsertType' => $native]);
        $books->upsert([['title' => 'A', 'rating' => 2]], ['conflictPaths' => ['title'], 'upsertType' => $native]);
        $this->assertSame(2, $books->findOneByOrFail(['title' => 'A'])->rating);

        foreach (['primary-key', $mysql ? 'on-conflict-do-update' : 'on-duplicate-key-update'] as $unsupported) {
            try {
                $books->upsert([['title' => 'A', 'rating' => 3]], ['conflictPaths' => ['title'], 'upsertType' => $unsupported]);
                $this->fail("upsertType $unsupported");
            } catch (OrmException $e) {
                $this->assertStringContainsString("Upsert type \"$unsupported\" is not supported", $e->getMessage());
                $this->assertStringContainsString($native, $e->getMessage());
            }
        }
        try {
            $books->upsert([['title' => 'A']], ['conflictPaths' => ['title'], 'upsertType' => 'merge']);
            $this->fail('unknown upsertType');
        } catch (OrmException $e) {
            $this->assertStringContainsString('"merge"', $e->getMessage());
        }

        $predicate = ['conflictPaths' => ['title'], 'indexPredicate' => 'rating >= 0'];
        if (static::platform() !== 'pgsql') {
            $this->expectException(OrmException::class);
            $this->expectExceptionMessage('indexPredicate option is not supported by the current database driver');
        }
        $books->upsert([['title' => 'A', 'rating' => 4]], $predicate);
        $this->assertSame(4, $books->findOneByOrFail(['title' => 'A'])->rating);
    }

    public function testSaveOptionsReloadChunkAndUnknownKeys(): void
    {
        $ds = $this->wsDataSource();
        $books = $ds->getRepository(WsBook::class);

        $reloaded = $books->save($books->create(['title' => 'R1', 'rating' => 4]));
        $this->assertSame(8, $reloaded->doubled, 'generated value read back');
        $plain = $books->save($books->create(['title' => 'R2', 'rating' => 4]), ['reload' => false]);
        $this->assertGreaterThan(0, $plain->id, 'the key is always assigned');
        if (static::platform() === 'pgsql') {
            $this->assertSame(8, $plain->doubled, 'RETURNING costs no extra query (TypeORM: reload only matters without RETURNING)');
        } else {
            $this->assertFalse((new \ReflectionProperty($plain, 'doubled'))->isInitialized($plain), 'reload: false skips the SELECT');
        }
        $plain->rating = 5;
        $books->save($plain, ['reload' => false]);
        $this->assertSame(10, (int) $ds->getConnection()->fetchColumn('SELECT doubled FROM ' . $ds->getConnection()->quote('ws_books') . ' WHERE id = :id', ['id' => $plain->id]));

        $many = [];
        for ($i = 0; $i < 5; $i++) {
            $many[] = $books->create(['title' => "C$i", 'rating' => $i]);
        }
        $books->save($many, ['chunk' => 2]);
        $this->assertSame(7, $books->count());
        $this->assertSame([3, 4, 5, 6, 7], array_map(fn (WsBook $b) => $b->id, $many));
        $books->remove(array_slice($many, 0, 3), ['chunk' => 2]);
        $this->assertSame(4, $books->count());

        foreach ([['chunk' => 0], ['chunk' => 'x'], ['reload' => 'no'], ['relaod' => false]] as $options) {
            try {
                $books->save($books->create(['title' => 'bad']), $options);
                $this->fail('invalid save options ' . json_encode($options));
            } catch (OrmException $e) {
                $this->assertStringContainsString((string) array_key_first($options), $e->getMessage());
            }
        }
        $this->assertSame(4, $books->count());
    }

    public function testFindOrderNullsFirstAndLast(): void
    {
        $ds = $this->wsDataSource();
        $books = $ds->getRepository(WsBook::class);
        $books->save([
            $books->create(['title' => 'b', 'note' => 'b']),
            $books->create(['title' => 'n', 'note' => null]),
            $books->create(['title' => 'a', 'note' => 'a']),
        ]);
        $titles = fn (array $order): array => array_map(fn (WsBook $b) => $b->title, $books->find(['order' => $order]));
        $this->assertSame(['n', 'a', 'b'], $titles(['note' => ['direction' => 'ASC', 'nulls' => 'FIRST']]));
        $this->assertSame(['a', 'b', 'n'], $titles(['note' => ['direction' => 'asc', 'nulls' => 'last']]));
        $this->assertSame(['n', 'b', 'a'], $titles(['note' => ['direction' => 'DESC', 'nulls' => 'first']]));
        $this->assertSame(['b', 'a', 'n'], $titles(['note' => ['direction' => 'DESC', 'nulls' => 'LAST']]));
        $this->assertSame(['a', 'b', 'n'], $titles(['note' => ['nulls' => 'last']]), 'direction defaults to ASC');
        // with pagination over a to-many join (ordered id pre-query)
        $this->assertSame(['n', 'a'], array_map(fn (WsBook $b) => $b->title, $books->find(['order' => ['note' => ['direction' => 'ASC', 'nulls' => 'first']], 'take' => 2, 'relations' => ['shelf']])));

        $qb = fn (string $direction, ?string $nulls): array => array_map(fn (WsBook $b) => $b->title, $books->createQueryBuilder('b')->orderBy('b.note', $direction, $nulls)->getMany());
        $this->assertSame(['b', 'a', 'n'], $qb('DESC', 'NULLS LAST'));
        $this->assertSame(['n', 'a', 'b'], $qb('ASC', 'NULLS FIRST'));
        $this->assertSame(['n', 'b', 'a'], array_map(fn (WsBook $b) => $b->title, $books->createQueryBuilder('b')->orderBy('b.title', 'ASC')->orderBy('b.note', 'DESC', 'NULLS FIRST')->getMany()));
        try {
            $books->find(['order' => ['note' => ['direction' => 'ASC', 'nulls' => 'middle']]]);
            $this->fail('invalid nulls');
        } catch (OrmException $e) {
            $this->assertStringContainsString('middle', $e->getMessage());
        }
    }
}
