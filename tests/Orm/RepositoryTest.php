<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm;

use DateTimeImmutable;
use Nesto\Common\Exceptions\NotFoundException;
use Nesto\Orm\EntityNotFoundException;
use Nesto\Orm\OrmException;
use Nesto\Orm\Pagination;
use Nesto\Tests\Orm\Fixtures\Post;
use Nesto\Tests\Orm\Fixtures\PostStatus;
use Nesto\Tests\Orm\Fixtures\Profile;
use Nesto\Tests\Orm\Fixtures\User;

final class RepositoryTest extends OrmTestCase
{
    public function testSynchronizeCreatesTables(): void
    {
        $schema = $this->ds->schema();
        foreach (['users', 'posts', 'tags', 'post_tags', 'profiles'] as $table) {
            $this->assertTrue($schema->hasTable($table), "$table missing");
        }
        $this->assertContains('user_id', $schema->columns('posts'));
        $this->assertContains('deleted_at', $schema->columns('users'));
        // idempotent
        $this->ds->synchronize();
        $this->assertTrue($schema->hasTable('users'));
    }

    public function testSaveInsertsAndFillsIdAndTimestamps(): void
    {
        $user = $this->users->create(['name' => 'Ada', 'email' => 'ada@example.com', 'age' => '36', 'settings' => ['theme' => 'dark']]);
        $this->assertSame(36, $user->age);
        $this->assertFalse(isset($user->id));
        $this->users->save($user);
        $this->assertSame(1, $user->id);
        $this->assertInstanceOf(DateTimeImmutable::class, $user->createdAt);
        $this->assertInstanceOf(DateTimeImmutable::class, $user->updatedAt);
        $this->assertNull($user->deletedAt);

        $found = $this->users->findOneBy(['email' => 'ada@example.com']);
        $this->assertInstanceOf(User::class, $found);
        $this->assertSame('Ada', $found->name);
        $this->assertSame(['theme' => 'dark'], $found->settings);
        $this->assertTrue($found->active);
        $this->assertSame(36, $found->age);
        $this->assertInstanceOf(DateTimeImmutable::class, $found->createdAt);
    }

    public function testSaveUpdatesOnlyChangedColumns(): void
    {
        $user = $this->users->save($this->users->create(['name' => 'Ada', 'email' => 'ada@example.com']));
        $this->startCounting();
        $this->users->save($user); // nothing changed → no UPDATE
        $this->assertSame(0, $this->queryCount());

        $user->name = 'Ada Lovelace';
        $this->users->save($user);
        $log = $this->ds->getConnection()->getQueryLog();
        $this->assertCount(1, $log);
        $this->assertStringContainsString('UPDATE "users" SET "name" = :v0, "updated_at" = :v1 WHERE "id" = :nesto_pk', $log[0]['sql']);
        $this->assertSame('Ada Lovelace', $this->users->findById($user->id)->name);
    }

    public function testEnumDecimalAndBoolRoundTrip(): void
    {
        $this->seed();
        $post = $this->posts->findOneByOrFail(['title' => 'Hello']);
        $this->assertSame(PostStatus::Published, $post->status);
        $this->assertSame(9.5, $post->price);
        $this->assertSame(10, $post->views);
        $bob = $this->users->findOneByOrFail(['name' => 'Bob']);
        $this->assertFalse($bob->active);
        $this->assertSame([], $this->users->findBy(['active' => false, 'name' => 'Ada']));
        $this->assertCount(2, $this->posts->findBy(['status' => PostStatus::Published]));
    }

    public function testFindWithOperators(): void
    {
        $this->seed();
        $this->assertCount(2, $this->users->find(['where' => ['age' => ['>', 30]]]));
        $this->assertCount(1, $this->users->find(['where' => ['age' => ['Between', 20, 30]]]));
        $this->assertCount(2, $this->users->find(['where' => ['name' => ['In', ['Ada', 'Bob']]]]));
        $this->assertCount(2, $this->users->find(['where' => ['name' => ['Ada', 'Bob']]]));
        $this->assertCount(2, $this->users->find(['where' => ['name' => ['Not', 'Ada']]]));
        $this->assertCount(1, $this->users->find(['where' => ['name' => ['Not', ['Ada', 'Bob']]]]));
        $this->assertCount(1, $this->users->find(['where' => ['name' => ['Like', 'A%']]]));
        $this->assertCount(3, $this->users->find(['where' => ['settings' => ['IsNull']]]));
        $this->assertCount(0, $this->users->find(['where' => ['settings' => ['IsNotNull']]]));
        $this->assertCount(3, $this->users->find(['where' => ['settings' => null]]));
        $this->assertCount(2, $this->users->find(['where' => [['name' => 'Ada'], ['age' => ['<', 30]]]])); // OR
        $ada = $this->users->findOneByOrFail(['name' => 'Ada']);
        $this->assertCount(2, $this->posts->find(['where' => ['user' => $ada]]));
        $this->assertCount(2, $this->posts->find(['where' => ['user' => $ada->id]]));

        $ordered = $this->users->find(['order' => ['age' => 'DESC'], 'take' => 2, 'skip' => 1]);
        $this->assertSame(['Ada', 'Bob'], array_map(fn (User $u) => $u->name, $ordered));
        $this->assertSame('Ada', $this->users->findOne(['where' => ['active' => true], 'order' => ['name' => 'ASC']])->name);
    }

    public function testUnknownOptionThrows(): void
    {
        $this->expectException(OrmException::class);
        $this->users->find(['wher' => []]);
    }

    public function testCountExistsFindAndCount(): void
    {
        $this->seed();
        $this->assertSame(3, $this->users->count());
        $this->assertSame(2, $this->users->count(['where' => ['active' => true]]));
        $this->assertTrue($this->users->exists(['where' => ['email' => 'bob@example.com']]));
        $this->assertFalse($this->users->existsBy(['email' => 'nobody@example.com']));
        [$rows, $total] = $this->users->findAndCount(['take' => 1, 'order' => ['name' => 'ASC']]);
        $this->assertCount(1, $rows);
        $this->assertSame(3, $total);
    }

    public function testFindOneByOrFailIs404(): void
    {
        $this->seed();
        try {
            $this->users->findOneByOrFail(['email' => 'missing@example.com']);
            $this->fail('expected exception');
        } catch (EntityNotFoundException $e) {
            $this->assertInstanceOf(NotFoundException::class, $e);
            $this->assertSame(404, $e->getStatus());
            $this->assertStringContainsString('User not found', $e->getMessage());
        }
    }

    public function testInsertUpdateDelete(): void
    {
        $id = $this->users->insert(['name' => 'Ins', 'email' => 'ins@example.com', 'active' => false]);
        $this->assertSame(1, $id);
        $ids = $this->users->insert([['name' => 'A', 'email' => 'a@example.com'], ['name' => 'B', 'email' => 'b@example.com']]);
        $this->assertSame([2, 3], $ids);
        $this->assertInstanceOf(DateTimeImmutable::class, $this->users->findById(2)->createdAt);

        $this->assertSame(1, $this->users->update(1, ['name' => 'Updated', 'active' => true]));
        $this->assertSame('Updated', $this->users->findById(1)->name);
        $this->assertSame(2, $this->users->update(['name' => ['In', ['A', 'B']]], ['age' => 50]));
        $this->assertSame(2, $this->users->count(['where' => ['age' => 50]]));

        $this->assertSame(1, $this->users->delete(1));
        $this->assertNull($this->users->findById(1));
        $this->assertSame(2, $this->users->delete(['age' => 50]));
        $this->assertSame(0, $this->users->count());
    }

    public function testRemoveEntity(): void
    {
        $user = $this->users->save($this->users->create(['name' => 'X', 'email' => 'x@example.com']));
        $this->users->remove($user);
        $this->assertSame(0, $this->users->count(['withDeleted' => true]));
    }

    public function testSoftDeleteAndRestore(): void
    {
        $this->seed();
        $bob = $this->users->findOneByOrFail(['name' => 'Bob']);
        $this->assertSame(1, $this->users->softDelete($bob->id));
        $this->assertNull($this->users->findOneBy(['name' => 'Bob']));
        $this->assertSame(2, $this->users->count());
        $this->assertSame(3, $this->users->count(['withDeleted' => true]));
        $deleted = $this->users->findOne(['where' => ['name' => 'Bob'], 'withDeleted' => true]);
        $this->assertInstanceOf(DateTimeImmutable::class, $deleted->deletedAt);
        $this->assertNull($this->users->createQueryBuilder('u')->where('u.name = :n', ['n' => 'Bob'])->getOne());
        $this->assertNotNull($this->users->createQueryBuilder('u')->withDeleted()->where('u.name = :n', ['n' => 'Bob'])->getOne());

        $this->assertSame(1, $this->users->restore($bob->id));
        $this->assertNotNull($this->users->findOneBy(['name' => 'Bob']));

        $this->users->softDelete($bob);
        $this->assertNotNull($bob->deletedAt);
        $this->assertSame(2, $this->users->count());
        $this->users->restore(['name' => 'Bob']);
        $this->assertSame(3, $this->users->count());

        $this->expectException(OrmException::class);
        $this->posts->softDelete(1);
    }

    public function testIncrementDecrement(): void
    {
        $this->seed();
        $post = $this->posts->findOneByOrFail(['title' => 'Hello']);
        $this->posts->increment($post->id, 'views', 5);
        $this->assertSame(15, $this->posts->findById($post->id)->views);
        $this->posts->decrement(['status' => PostStatus::Published], 'views');
        $this->assertSame(14, $this->posts->findById($post->id)->views);
        $this->assertSame(6, $this->posts->findOneByOrFail(['title' => 'Bob writes'])->views);
    }

    public function testPaginate(): void
    {
        $this->seed();
        $page = $this->users->paginate(2, 2, ['order' => ['name' => 'ASC']]);
        $this->assertInstanceOf(Pagination::class, $page);
        $this->assertSame(3, $page->total);
        $this->assertSame(2, $page->lastPage);
        $this->assertCount(1, $page->data);
        $this->assertSame('Cyd', $page->data[0]->name);
        $json = json_decode(json_encode($page), true);
        $this->assertSame(['total' => 3, 'page' => 2, 'perPage' => 2, 'lastPage' => 2], $json['meta']);
        $this->assertSame('Cyd', $json['data'][0]['name']);
        $this->assertArrayNotHasKey('posts', $json['data'][0]); // uninitialized relation stays invisible
        $this->assertIsString($json['data'][0]['createdAt']);
    }

    public function testUuidPrimaryAndOneToOne(): void
    {
        $user = $this->users->save($this->users->create(['name' => 'Ada', 'email' => 'ada@example.com']));
        $profiles = $this->ds->getRepository(Profile::class);
        $profile = $profiles->create(['bio' => 'hi']);
        $profile->user = $user;
        $profiles->save($profile);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $profile->id);

        $loaded = $profiles->findOne(['where' => ['id' => $profile->id], 'relations' => ['user']]);
        $this->assertSame('Ada', $loaded->user->name);
        $withProfile = $this->users->findOne(['where' => ['id' => $user->id], 'relations' => ['profile']]);
        $this->assertSame('hi', $withProfile->profile->bio);

        $profile->bio = 'changed';
        $profiles->save($profile);
        $this->assertSame('changed', $profiles->findById($profile->id)->bio);
    }

    public function testCreateWithNestedRelationsAndEntityManager(): void
    {
        $em = $this->ds->getEntityManager();
        $post = $em->create(Post::class, [
            'title' => 'Nested',
            'user' => ['name' => 'New', 'email' => 'new@example.com'],
            'tags' => [['name' => 't1'], ['name' => 't2']],
        ]);
        $em->save($post);
        $this->assertSame(1, $post->user->id);
        $reloaded = $em->findOne(Post::class, ['where' => ['id' => $post->id], 'relations' => ['user', 'tags']]);
        $this->assertSame('New', $reloaded->user->name);
        $this->assertSame(['t1', 't2'], array_map(fn ($t) => $t->name, $reloaded->tags));
        $this->assertSame(1, $em->count(Post::class));
        $rows = $em->query('SELECT COUNT(*) AS n FROM post_tags WHERE post_id = :id', ['id' => $post->id]);
        $this->assertSame(2, (int) $rows[0]['n']);
        $em->remove($post);
        $this->assertSame(0, $em->count(Post::class));
    }
}
