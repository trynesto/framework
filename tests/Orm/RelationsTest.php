<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm;

use Nesto\Tests\Orm\Fixtures\Post;
use Nesto\Tests\Orm\Fixtures\Tag;
use Nesto\Tests\Orm\Fixtures\User;

final class RelationsTest extends OrmTestCase
{
    public function testOneToManyEagerLoadingWithoutNPlusOne(): void
    {
        $this->seed();
        $this->startCounting();
        $users = $this->users->find(['relations' => ['posts'], 'order' => ['name' => 'ASC']]);
        $this->assertSame(2, $this->queryCount());
        $this->assertCount(2, $users[0]->posts);
        $this->assertCount(1, $users[1]->posts);
        $this->assertSame([], $users[2]->posts);
        $this->assertSame('Hello', $users[0]->posts[0]->title);
        $this->assertFalse(isset($users[0]->posts[0]->user)); // inverse not set → no JSON cycles
    }

    public function testManyToOneAndNestedPaths(): void
    {
        $this->seed();
        $this->startCounting();
        $posts = $this->posts->find(['relations' => ['user', 'tags'], 'order' => ['id' => 'ASC']]);
        $this->assertSame(3, $this->queryCount());
        $this->assertSame('Ada', $posts[0]->user->name);
        $this->assertSame($posts[0]->user, $posts[1]->user); // identity within a load
        $this->assertSame(['php', 'orm'], array_map(fn (Tag $t) => $t->name, $posts[0]->tags));
        $this->assertSame(['sql'], array_map(fn (Tag $t) => $t->name, $posts[1]->tags));
        $this->assertSame([], $posts[2]->tags);

        $this->startCounting();
        $users = $this->users->find(['relations' => ['posts.tags'], 'where' => ['name' => 'Ada']]);
        $this->assertSame(3, $this->queryCount());
        $this->assertSame(['php', 'orm'], array_map(fn (Tag $t) => $t->name, $users[0]->posts[0]->tags));

        $tags = $this->tags->find(['relations' => ['posts'], 'order' => ['name' => 'ASC']]);
        $this->assertSame(['Hello'], array_map(fn (Post $p) => $p->title, $tags[0]->posts)); // orm
    }

    public function testManyToManySyncOnSave(): void
    {
        $this->seed();
        $post = $this->posts->findOne(['where' => ['title' => 'Hello'], 'relations' => ['tags']]);
        $sql = $this->tags->findOneByOrFail(['name' => 'sql']);
        $post->tags = [$post->tags[0], $sql];
        $this->posts->save($post);
        $reloaded = $this->posts->findOne(['where' => ['id' => $post->id], 'relations' => ['tags']]);
        $this->assertSame(['php', 'sql'], array_map(fn (Tag $t) => $t->name, $reloaded->tags));

        $this->startCounting();
        $this->posts->save($reloaded); // unchanged → no join-table writes
        $this->assertSame(0, $this->queryCount());

        $reloaded->tags = [];
        $this->posts->save($reloaded);
        $this->assertSame(0, (int) $this->ds->getConnection()->fetchColumn('SELECT COUNT(*) FROM post_tags WHERE post_id = :id', ['id' => $post->id]));
    }

    public function testCascadeSavesNewRelatedEntities(): void
    {
        $user = $this->users->create(['name' => 'Cas', 'email' => 'cas@example.com']);
        $post = $this->posts->create(['title' => 'Cascade']);
        $post->user = $user;
        $tag = $this->tags->create(['name' => 'new']);
        $post->tags = [$tag];
        $this->posts->save($post);
        $this->assertSame(1, $user->id);
        $this->assertSame(1, $tag->id);
        $this->assertSame(1, (int) $this->ds->getConnection()->fetchColumn('SELECT user_id FROM posts WHERE id = :id', ['id' => $post->id]));

        // OneToMany cascade: children get the parent's foreign key
        $user2 = $this->users->create(['name' => 'Parent', 'email' => 'parent@example.com']);
        $user2->posts = [$this->posts->create(['title' => 'Child 1']), $this->posts->create(['title' => 'Child 2'])];
        $this->users->save($user2);
        $children = $this->posts->find(['where' => ['user' => $user2], 'order' => ['title' => 'ASC']]);
        $this->assertSame(['Child 1', 'Child 2'], array_map(fn (Post $p) => $p->title, $children));
    }

    public function testForeignKeyCascadeDeleteViaSchema(): void
    {
        $this->seed();
        $ada = $this->users->findOneByOrFail(['name' => 'Ada']);
        $this->users->delete($ada->id);
        $this->assertSame(1, $this->posts->count()); // ON DELETE CASCADE from synchronize()
        $this->assertSame(0, (int) $this->ds->getConnection()->fetchColumn('SELECT COUNT(*) FROM post_tags WHERE post_id IN (1, 2)'));
    }

    public function testChangingRelationUpdatesForeignKey(): void
    {
        $this->seed();
        $post = $this->posts->findOneByOrFail(['title' => 'Bob writes']);
        $cyd = $this->users->findOneByOrFail(['name' => 'Cyd']);
        $post->user = $cyd;
        $this->posts->save($post);
        $this->assertSame('Cyd', $this->posts->findOne(['where' => ['id' => $post->id], 'relations' => ['user']])->user->name);
        $this->assertCount(1, $this->users->findOne(['where' => ['name' => 'Cyd'], 'relations' => ['posts']])->posts);
    }

    public function testEntitiesSerializeWithoutInternalState(): void
    {
        $this->seed();
        $user = $this->users->findOne(['where' => ['name' => 'Ada'], 'relations' => ['posts']]);
        $vars = get_object_vars($user);
        $this->assertSame(['id', 'name', 'email', 'active', 'age', 'settings', 'createdAt', 'updatedAt', 'deletedAt', 'posts', 'profile'], array_keys($vars));
        $json = json_decode(json_encode($user), true);
        $this->assertSame('Ada', $json['name']);
        $this->assertCount(2, $json['posts']);
        $this->assertSame('published', $json['posts'][0]['status']);
        $this->assertInstanceOf(User::class, $user);
    }
}
