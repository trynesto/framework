<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\HasManyThrough;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;
use Nesto\Orm\Mapping\RelationType;
use Nesto\Orm\Mapping\ThroughPath;
use Nesto\Orm\MetadataRegistry;
use Nesto\Orm\OrmException;
use Nesto\Tests\Orm\Fixtures\Relations\Article;
use Nesto\Tests\Orm\Fixtures\Relations\Country;
use Nesto\Tests\Orm\Fixtures\Relations\Mechanic;
use Nesto\Tests\Orm\Fixtures\Relations\Owner;
use Nesto\Tests\Orm\Fixtures\Relations\Paragraph;
use Nesto\Tests\Orm\Fixtures\Relations\Writer;

final class ThroughRelationsTest extends RelationFixturesTestCase
{
    public function testPathsAreResolvedFromTheManyToOneRelations(): void
    {
        $country = MetadataRegistry::get(Country::class);
        $articles = $country->relation('articles');
        $this->assertSame(RelationType::HasManyThrough, $articles->type);
        $this->assertTrue($articles->isToMany());
        $this->assertTrue($articles->isReadOnly());
        $this->assertSame([Writer::class], $articles->through);
        $this->assertSame(['id', [['writers', 'country_id', 'id'], ['articles', 'writer_id', null]]], $this->describe($country, 'articles'));
        $this->assertSame($this->describe($country, 'articles'), $this->describe($country, 'articlesExplicit'));

        // custom FK name (owners.vehicle_id) comes from Owner's ManyToOne
        $this->assertSame(['id', [['cars', 'mechanic_id', 'id'], ['owners', 'vehicle_id', null]]], $this->describe(MetadataRegistry::get(Mechanic::class), 'carOwner'));
        $this->assertSame(['vehicle_id', [['cars', 'id', 'mechanic_id'], ['mechanics', 'id', null]]], $this->describe(MetadataRegistry::get(Owner::class), 'mechanic'));

        $paragraph = MetadataRegistry::get(Paragraph::class);
        $chain = ['article_id', [['articles', 'id', 'writer_id'], ['writers', 'id', 'country_id'], ['countries', 'id', null]]];
        $this->assertSame($chain, $this->describe($paragraph, 'country'), 'through listed from the target side');
        $this->assertSame($chain, $this->describe($paragraph, 'countryNearFirst'), 'through listed from this side');
        $this->assertSame(RelationType::BelongsToThrough, $paragraph->relation('country')->type);
        $this->assertFalse($paragraph->relation('country')->isToMany());
    }

    public function testWrongKeysAreReported(): void
    {
        $meta = MetadataRegistry::get(ThroughWrongKeyCountry::class);
        $this->expectException(OrmException::class);
        $this->expectExceptionMessage('column "nation_id" does not exist on ' . Writer::class);
        ThroughPath::for($meta, $meta->relation('articles'));
    }

    public function testHasManyThroughLoadsWithOneQuery(): void
    {
        $this->seed();
        $this->startCounting();
        $countries = $this->ds->getRepository(Country::class)->find(['relations' => ['articles'], 'order' => ['id' => 'ASC']]);
        $this->assertCount(2, $this->queries());
        $this->assertSame(['A1', 'A2', 'A3'], self::pluck($countries[0]->articles, 'title'));
        $this->assertSame(['A4'], self::pluck($countries[1]->articles, 'title'));
        $this->assertSame([], $countries[2]->articles);
        $this->assertStringContainsString('INNER JOIN "articles" AS "t" ON "t"."writer_id" = "h0"."id"', $this->queries()[1]);
    }

    public function testSoftDeletedIntermediatesAreSkippedUnlessWithDeleted(): void
    {
        $e = $this->seed();
        $this->ds->getRepository(Writer::class)->softDelete($e['bob']);
        $countries = $this->ds->getRepository(Country::class);
        $this->assertSame(['A1', 'A2'], self::pluck($countries->findOneOrFail(['where' => ['name' => 'Iraq'], 'relations' => ['articles']])->articles, 'title'));
        $this->assertSame(['A1', 'A2', 'A3'], self::pluck($countries->findOneOrFail(['where' => ['name' => 'Iraq'], 'relations' => ['articles'], 'withDeleted' => true])->articles, 'title'));
        $this->assertNull($this->ds->getRepository(Article::class)->findOneOrFail(['where' => ['title' => 'A3'], 'relations' => ['country']])->country);
    }

    public function testHasOneThrough(): void
    {
        $this->seed();
        $this->startCounting();
        $mechanics = $this->ds->getRepository(Mechanic::class)->find(['relations' => ['carOwner']]);
        $this->assertCount(2, $this->queries());
        $this->assertSame('Ow', $mechanics[0]->carOwner->name);
        $this->assertNull($mechanics[1]->carOwner);
    }

    public function testBelongsToThroughOneAndSeveralIntermediates(): void
    {
        $this->seed();
        $this->startCounting();
        $articles = $this->ds->getRepository(Article::class)->find(['relations' => ['country']]);
        $this->assertCount(2, $this->queries());
        $this->assertSame(['Iraq', 'Iraq', 'Iraq', 'Japan'], array_map(fn (Article $a) => $a->country->name, $articles));
        $this->assertSame($articles[0]->country, $articles[2]->country, 'one instance per row');

        $this->startCounting();
        $paragraphs = $this->ds->getRepository(Paragraph::class)->find(['relations' => ['country', 'countryNearFirst', 'writer']]);
        $this->assertCount(4, $this->queries());
        $this->assertSame(['Iraq', 'Iraq', 'Japan'], array_map(fn (Paragraph $p) => $p->country->name, $paragraphs));
        $this->assertSame(['Iraq', 'Iraq', 'Japan'], array_map(fn (Paragraph $p) => $p->countryNearFirst->name, $paragraphs));
        $this->assertSame(['Ada', 'Bob', 'Kei'], array_map(fn (Paragraph $p) => $p->writer->name, $paragraphs));
        $this->assertSame('Mo', $this->ds->getRepository(Owner::class)->findOneOrFail(['relations' => ['mechanic']])->mechanic->name);

        // works for a new entity whose relation object is set but not saved yet, and with no key at all
        $draft = $this->em->create(Paragraph::class, ['text' => 'draft']);
        (new \Nesto\Orm\RelationLoader($this->ds))->load(MetadataRegistry::get(Paragraph::class), [$draft], ['country']);
        $this->assertNull($draft->country);
    }

    public function testNestedPathsThroughThroughRelations(): void
    {
        $this->seed();
        $this->startCounting();
        $countries = $this->ds->getRepository(Country::class)->find(['relations' => ['articles.comments.commentable', 'articles.labels'], 'order' => ['id' => 'ASC']]);
        $this->assertCount(5, $this->queries(), 'countries, articles, comments, commentables (articles), labels');
        $this->assertSame(['a1-1', 'a1-2'], self::pluck($countries[0]->articles[0]->comments, 'body'));
        $this->assertSame('A1', $countries[0]->articles[0]->comments[0]->commentable->title);
        $this->assertSame(['php', 'orm'], self::pluck($countries[0]->articles[0]->labels, 'name'));

        $paragraphs = $this->ds->getRepository(Paragraph::class)->find(['relations' => ['country.writers', 'writer.avatar']]);
        $this->assertSame(['Ada', 'Bob'], self::pluck($paragraphs[0]->country->writers, 'name'));
        $this->assertSame('ada.png', $paragraphs[0]->writer->avatar->url);
        $this->assertNull($paragraphs[1]->writer->avatar);
    }

    public function testQueryBuilderJoinsThroughRelations(): void
    {
        $this->seed();
        $countries = $this->ds->createQueryBuilder(Country::class, 'c')
            ->leftJoinAndSelect('c.articles', 'a')
            ->orderBy('c.id')->addOrderBy('a.id')
            ->getMany();
        $this->assertSame(['A1', 'A2', 'A3'], self::pluck($countries[0]->articles, 'title'));
        $this->assertSame([], $countries[2]->articles);

        // paginated with a to-many join: the root page is computed first
        $page = $this->ds->createQueryBuilder(Country::class, 'c')->leftJoinAndSelect('c.articles', 'a')->orderBy('c.id')->take(1)->skip(1)->getMany();
        $this->assertSame(['Japan'], self::pluck($page, 'name'));
        $this->assertSame(['A4'], self::pluck($page[0]->articles, 'title'));

        $paragraphs = $this->ds->createQueryBuilder(Paragraph::class, 'p')->innerJoinAndSelect('p.country', 'c')->where('c.name = :n', ['n' => 'Japan'])->getMany();
        $this->assertSame(['P3'], self::pluck($paragraphs, 'text'));
        $this->assertSame('Japan', $paragraphs[0]->country->name);

        $owners = $this->ds->createQueryBuilder(Mechanic::class, 'm')->leftJoinAndSelect('m.carOwner', 'o')->orderBy('m.id')->getMany();
        $this->assertSame('Ow', $owners[0]->carOwner->name);
        $this->assertNull($owners[1]->carOwner);
    }

    public function testThroughRelationsAreReadOnly(): void
    {
        $e = $this->seed();
        $iraq = $this->ds->getRepository(Country::class)->findOneOrFail(['where' => ['name' => 'Iraq'], 'relations' => ['articles']]);
        $iraq->articles = [];
        $this->startCounting();
        $this->em->save($iraq);
        $this->assertSame([], $this->queries());
        $this->assertSame(4, $this->ds->getRepository(Article::class)->count());

        $created = $this->em->create(Paragraph::class, ['text' => 'x', 'country' => $e['japan']]);
        $this->assertFalse(isset($created->country) && $created->country !== null);
    }

    /** @return array{0: string, 1: list<array{0: string, 1: string, 2: ?string}>} */
    private function describe(\Nesto\Orm\EntityMetadata $meta, string $relation): array
    {
        $path = ThroughPath::for($meta, $meta->relation($relation));

        return [$path->parentKey, array_map(fn (array $h) => [$h['meta']->table, $h['in'], $h['out']], $path->hops)];
    }
}

#[Entity(name: 'through_wrong_key_countries')]
final class ThroughWrongKeyCountry
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $name;

    #[HasManyThrough(Article::class, through: Writer::class, firstKey: 'nation_id')]
    public array $articles;
}
