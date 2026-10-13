<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\JoinColumn;
use Nesto\Orm\Attributes\JoinTable;
use Nesto\Orm\Attributes\ManyToMany;
use Nesto\Orm\Attributes\ManyToOne;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;
use Nesto\Orm\DataSource;
use Nesto\Orm\MetadataRegistry;
use Nesto\Tests\Orm\Drivers\Fixtures\Member;
use Nesto\Tests\Orm\Drivers\Fixtures\Team;
use Nesto\Tests\Orm\Fixtures\Profile;
use Nesto\Tests\Orm\Fixtures\Tag;

/** Regressions for gaps found while reviewing OneToOne / OneToMany / ManyToMany. */
final class RelationFixesTest extends OrmTestCase
{
    public function testOneToManyCascadeLinksChildrenWhoseInverseDefaultsToNull(): void
    {
        $ds = new DataSource(['type' => 'sqlite', 'database' => ':memory:', 'entities' => [Team::class, Member::class], 'synchronize' => true]);
        $teams = $ds->getRepository(Team::class);
        $team = $teams->create(['name' => 'Red']);
        $team->members = [$ds->getRepository(Member::class)->create(['name' => 'Ada'])];   // Member::$team = null by default
        $teams->save($team);
        $this->assertSame($team->id, (int) $ds->getConnection()->fetchColumn('SELECT team_id FROM members'));
        $this->assertSame(['Ada'], array_map(fn (Member $m) => $m->name, $teams->findOneOrFail(['relations' => ['members']])->members));

        // an explicitly assigned parent still wins
        $blue = $teams->save($teams->create(['name' => 'Blue']));
        $member = $ds->getRepository(Member::class)->create(['name' => 'Bob']);
        $member->team = $blue;
        $team->members = [$member];
        $teams->save($team);
        $this->assertSame($blue->id, (int) $ds->getConnection()->fetchColumn("SELECT team_id FROM members WHERE name = 'Bob'"));
    }

    public function testInverseOneToOneCascadesTheOwningSide(): void
    {
        $user = $this->users->create(['name' => 'Ada', 'email' => 'ada@example.com']);
        $user->profile = $this->ds->getRepository(Profile::class)->create(['bio' => 'hello']);
        $this->users->save($user);
        $loaded = $this->users->findOneOrFail(['where' => ['id' => $user->id], 'relations' => ['profile']]);
        $this->assertSame('hello', $loaded->profile->bio);

        $this->startCounting();
        $this->users->save($loaded);
        $this->assertSame(0, $this->queryCount(), 'unchanged: nothing written');
    }

    public function testSelfReferencingManyToManyGetsDistinctDefaultColumns(): void
    {
        $meta = MetadataRegistry::get(FixPerson::class);
        $friends = $meta->relation('friends');
        $this->assertSame(['fix_people_fix_persons', 'fix_person_id', 'related_fix_person_id'], [$friends->joinTable, $friends->joinTableColumn, $friends->inverseJoinColumn]);

        $ds = new DataSource(['type' => 'sqlite', 'database' => ':memory:', 'entities' => [FixPerson::class], 'synchronize' => true]);
        $people = $ds->getRepository(FixPerson::class);
        $ada = $people->create(['name' => 'Ada']);
        $ada->friends = [$people->create(['name' => 'Bob']), $people->create(['name' => 'Cyd'])];
        $people->save($ada);
        $this->assertSame(['Bob', 'Cyd'], array_map(fn (FixPerson $p) => $p->name, $people->findOneOrFail(['where' => ['name' => 'Ada'], 'relations' => ['friends']])->friends));
    }

    public function testManyToManyJoinedWithTheQueryBuilderIsNotRewrittenOnSave(): void
    {
        $this->seed();
        $post = $this->posts->createQueryBuilder('p')->leftJoinAndSelect('p.tags', 't')->where(['title' => 'Hello'])->getOne();
        $this->assertSame(['php', 'orm'], array_map(fn (Tag $t) => $t->name, $post->tags));
        $this->startCounting();
        $this->posts->save($post);
        $this->assertSame(0, $this->queryCount());

        $post->tags = [$post->tags[0]];
        $this->posts->save($post);
        $this->assertSame(1, (int) $this->ds->getConnection()->fetchColumn('SELECT COUNT(*) FROM post_tags WHERE post_id = :id', ['id' => $post->id]));
    }

    public function testJoinUsesTheReferencedColumnName(): void
    {
        $ds = new DataSource(['type' => 'sqlite', 'database' => ':memory:', 'entities' => [FixCountry::class, FixCity::class], 'synchronize' => true]);
        $ds->getConnection()->execute("INSERT INTO fix_countries (id, iso_code, name) VALUES (1, 'IQ', 'Iraq')");
        $ds->getConnection()->execute("INSERT INTO fix_cities (id, name, country_code) VALUES (1, 'Basra', 'IQ')");
        $city = $ds->createQueryBuilder(FixCity::class, 'c')->leftJoinAndSelect('c.country', 'k')->getOne();
        $this->assertSame('Iraq', $city->country->name);
        $this->assertSame('Iraq', $ds->getRepository(FixCity::class)->findOneOrFail(['relations' => ['country']])->country->name);
    }

    public function testForeignKeyColumnsCanBeGivenByName(): void
    {
        $this->seed();
        $bob = $this->users->findOneByOrFail(['name' => 'Bob']);
        $post = $this->posts->save($this->posts->create(['title' => 'By id', 'user_id' => $bob->id]));
        $this->assertSame('Bob', $this->posts->findOneOrFail(['where' => ['id' => $post->id], 'relations' => ['user']])->user->name);
    }
}

#[Entity(name: 'fix_people')]
final class FixPerson
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $name;

    #[ManyToMany(FixPerson::class, cascade: ['insert'])]
    #[JoinTable]
    public array $friends;
}

#[Entity(name: 'fix_countries')]
final class FixCountry
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column(name: 'iso_code', unique: true)]
    public string $isoCode;

    #[Column]
    public string $name;
}

#[Entity(name: 'fix_cities')]
final class FixCity
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $name;

    #[ManyToOne(FixCountry::class)]
    #[JoinColumn(name: 'country_code', referencedColumnName: 'isoCode')]
    public ?FixCountry $country = null;
}
