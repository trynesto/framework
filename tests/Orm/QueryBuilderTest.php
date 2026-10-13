<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm;

use Nesto\Orm\EntityNotFoundException;
use Nesto\Tests\Orm\Fixtures\Post;
use Nesto\Tests\Orm\Fixtures\PostStatus;
use Nesto\Tests\Orm\Fixtures\Tag;
use Nesto\Tests\Orm\Fixtures\User;

final class QueryBuilderTest extends OrmTestCase
{
    public function testSqlAndParameters(): void
    {
        $qb = $this->users->createQueryBuilder('u')
            ->select('u.id, u.name')
            ->where('u.age > :age', ['age' => 18])
            ->andWhere(['active' => true])
            ->orWhere('u.name LIKE :name', ['name' => 'A%'])
            ->orderBy('u.createdAt', 'DESC')->addOrderBy('u.id')
            ->take(5)->skip(10);
        $this->assertSame(
            'SELECT "u"."id", "u"."name" FROM "users" AS "u" WHERE ("u"."age" > :age) AND ("u"."active" = :nesto_p0) OR ("u"."name" LIKE :name) AND ("u"."deleted_at" IS NULL) ORDER BY "u"."created_at" DESC, "u"."id" ASC LIMIT 5 OFFSET 10',
            $qb->getSql(),
        );
        $this->assertSame(['age' => 18, 'nesto_p0' => true, 'name' => 'A%'], $qb->getParameters());
    }

    public function testGetManyGetOneRawAndCount(): void
    {
        $this->seed();
        $qb = $this->users->createQueryBuilder('u')->where('u.age >= :min', ['min' => 30])->orderBy('u.age', 'DESC');
        $users = $qb->getMany();
        $this->assertSame(['Cyd', 'Ada'], array_map(fn (User $u) => $u->name, $users));
        $this->assertSame(2, $qb->getCount());
        $this->assertSame('Cyd', $qb->getOne()->name);
        $this->assertCount(2, $qb->getManyAndCount()[0]);

        $raw = $this->posts->createQueryBuilder('p')
            ->select('p.status AS status, COUNT(p.id) AS total, SUM(p.views) AS views')
            ->groupBy('p.status')->having('COUNT(p.id) > :n', ['n' => 0])
            ->orderBy('p.status')
            ->getRawMany();
        $this->assertSame([['status' => 'draft', 'total' => 1, 'views' => 3], ['status' => 'published', 'total' => 2, 'views' => 17]], $raw);
        $this->assertSame(['n' => 3], $this->users->createQueryBuilder('u')->select('COUNT(*) AS n')->getRawOne());

        $this->expectException(EntityNotFoundException::class);
        $this->users->createQueryBuilder('u')->where(['name' => 'nobody'])->getOneOrFail();
    }

    public function testJoinsAndJoinAndSelect(): void
    {
        $this->seed();
        $this->startCounting();
        $users = $this->users->createQueryBuilder('u')
            ->leftJoinAndSelect('u.posts', 'p')
            ->leftJoinAndSelect('p.tags', 't')
            ->orderBy('u.name')->addOrderBy('p.id')->addOrderBy('t.id')
            ->getMany();
        $this->assertSame(1, $this->queryCount());
        $this->assertCount(3, $users);
        $this->assertCount(2, $users[0]->posts);
        $this->assertSame(['php', 'orm'], array_map(fn (Tag $t) => $t->name, $users[0]->posts[0]->tags));
        $this->assertSame([], $users[2]->posts);

        $published = $this->users->createQueryBuilder('u')
            ->innerJoin('u.posts', 'p', 'p.status = :status', ['status' => PostStatus::Published])
            ->select('u')->distinct()
            ->orderBy('u.name')
            ->getMany();
        $this->assertSame(['Ada', 'Bob'], array_map(fn (User $u) => $u->name, $published));

        $withUser = $this->posts->createQueryBuilder('p')->innerJoinAndSelect('p.user', 'u')->where('u.name = :n', ['n' => 'Bob'])->getOne();
        $this->assertSame('Bob writes', $withUser->title);
        $this->assertSame('Bob', $withUser->user->name);

        $viaTable = $this->posts->createQueryBuilder('p')
            ->innerJoin('post_tags', 'pt', 'pt.post_id = p.id')
            ->innerJoin(Tag::class, 't', 't.id = pt.tag_id')
            ->where('t.name = :tag', ['tag' => 'sql'])
            ->getMany();
        $this->assertSame(['Second'], array_map(fn (Post $p) => $p->title, $viaTable));
    }

    public function testPaginationWithToManyJoinUsesRootIds(): void
    {
        $this->seed();
        $qb = $this->users->createQueryBuilder('u')->leftJoinAndSelect('u.posts', 'p')->orderBy('u.name')->take(1)->skip(0);
        $users = $qb->getMany();
        $this->assertCount(1, $users);
        $this->assertSame('Ada', $users[0]->name);
        $this->assertCount(2, $users[0]->posts); // all posts of the page's user, not 1 row
        $this->assertSame(3, $qb->getCount());
    }

    public function testWhereHelpers(): void
    {
        $this->seed();
        $this->assertCount(2, $this->users->createQueryBuilder('u')->whereIn('name', ['Ada', 'Cyd'])->getMany());
        $this->assertCount(1, $this->users->createQueryBuilder('u')->whereNotIn('u.name', ['Ada', 'Cyd'])->getMany());
        $this->assertCount(3, $this->users->createQueryBuilder('u')->whereNull('settings')->getMany());
        $this->assertCount(0, $this->users->createQueryBuilder('u')->whereNotNull('u.settings')->getMany());
        $this->assertCount(1, $this->users->createQueryBuilder('u')->where(['age' => ['>=', 40]])->getMany());
        $this->assertCount(2, $this->users->createQueryBuilder('u')->where('u.active = :a')->setParameter('a', true)->getMany());
    }

    public function testInsertUpdateDeleteBuilders(): void
    {
        $affected = $this->ds->createQueryBuilder()->insert()->into(User::class)->values([
            ['name' => 'Q1', 'email' => 'q1@example.com', 'active' => false],
            ['name' => 'Q2', 'email' => 'q2@example.com'],
        ])->execute();
        $this->assertSame(2, $affected);
        $this->assertSame(2, $this->users->count());

        $updated = $this->ds->createQueryBuilder()->update(User::class, 'u')->set(['active' => true, 'age' => 9])
            ->where('u.name = :n', ['n' => 'Q1'])->execute();
        $this->assertSame(1, $updated);
        $this->assertTrue($this->users->findOneByOrFail(['name' => 'Q1'])->active);

        $deleted = $this->ds->createQueryBuilder()->delete()->from(User::class)->where(['age' => 9])->execute();
        $this->assertSame(1, $deleted);
        $this->assertSame(1, $this->users->count());

        // raw table, no entity
        $this->ds->createQueryBuilder()->insert()->into('users')->values(['name' => 'Raw', 'email' => 'raw@example.com', 'active' => 1, 'age' => 1])->execute();
        $rows = $this->ds->createQueryBuilder('users', 'x')->select('x.name')->where('x.email = :e', ['e' => 'raw@example.com'])->getRawMany();
        $this->assertSame([['name' => 'Raw']], $rows);
    }
}
