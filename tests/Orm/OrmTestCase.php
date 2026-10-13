<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm;

use Nesto\Orm\DataSource;
use Nesto\Orm\MetadataRegistry;
use Nesto\Orm\Repository;
use Nesto\Tests\Orm\Fixtures\Post;
use Nesto\Tests\Orm\Fixtures\PostStatus;
use Nesto\Tests\Orm\Fixtures\Profile;
use Nesto\Tests\Orm\Fixtures\Tag;
use Nesto\Tests\Orm\Fixtures\User;
use PHPUnit\Framework\TestCase;

abstract class OrmTestCase extends TestCase
{
    protected DataSource $ds;
    protected Repository $users;
    protected Repository $posts;
    protected Repository $tags;

    protected function setUp(): void
    {
        MetadataRegistry::clear();
        $this->ds = new DataSource([
            'type' => 'sqlite',
            'database' => ':memory:',
            'entities' => [User::class, Post::class, Tag::class, Profile::class],
            'synchronize' => true,
        ]);
        $this->ds->initialize();
        $this->users = $this->ds->getRepository(User::class);
        $this->posts = $this->ds->getRepository(Post::class);
        $this->tags = $this->ds->getRepository(Tag::class);
    }

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
    }

    protected function queryCount(): int
    {
        return $this->ds->getConnection()->queryCount();
    }

    protected function startCounting(): void
    {
        $this->ds->getConnection()->enableQueryLog()->flushQueryLog();
    }
}
