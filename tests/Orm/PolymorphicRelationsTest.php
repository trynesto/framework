<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\MorphMany;
use Nesto\Orm\Attributes\MorphTo;
use Nesto\Orm\Attributes\MorphToMany;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;
use Nesto\Orm\DataSource;
use Nesto\Orm\Mapping\RelationType;
use Nesto\Orm\MetadataRegistry;
use Nesto\Orm\Migration\Blueprint;
use Nesto\Orm\MorphMap;
use Nesto\Orm\OrmException;
use Nesto\Tests\Orm\Fixtures\Relations\Article;
use Nesto\Tests\Orm\Fixtures\Relations\Comment;
use Nesto\Tests\Orm\Fixtures\Relations\Image;
use Nesto\Tests\Orm\Fixtures\Relations\Label;
use Nesto\Tests\Orm\Fixtures\Relations\MorphIntDoc;
use Nesto\Tests\Orm\Fixtures\Relations\MorphNote;
use Nesto\Tests\Orm\Fixtures\Relations\MorphUuidDoc;
use Nesto\Tests\Orm\Fixtures\Relations\MorphUuidOnlyNote;
use Nesto\Tests\Orm\Fixtures\Relations\RelationsSeeder;
use Nesto\Tests\Orm\Fixtures\Relations\Video;
use Nesto\Tests\Orm\Fixtures\Relations\Writer;

final class PolymorphicRelationsTest extends RelationFixturesTestCase
{
    // ---- metadata -------------------------------------------------------------------------------

    public function testMorphToMetadataAddsTypeAndIdColumnsWithACompositeIndex(): void
    {
        $comment = MetadataRegistry::get(Comment::class);
        $relation = $comment->relation('commentable');
        $this->assertSame(RelationType::MorphTo, $relation->type);
        $this->assertSame('commentable', $relation->morphName);
        $this->assertSame('commentable_type', $relation->morphTypeColumn);
        $this->assertSame('commentable_id', $relation->morphIdColumn);
        $this->assertTrue($relation->isMorph());
        $this->assertFalse($relation->isToMany());
        $this->assertSame('string', $comment->columns['commentable_type']->type);
        $this->assertSame('type', $comment->columns['commentable_type']->morph);
        $this->assertSame('bigint', $comment->columns['commentable_id']->type);
        $this->assertSame('commentable', $comment->columns['commentable_id']->relation);
        $this->assertTrue($comment->columns['commentable_id']->nullable);
        $this->assertSame([['columns' => ['commentable_type', 'commentable_id'], 'unique' => false, 'name' => null, 'where' => null]], $comment->indexes);

        // custom column names, picked up by the MorphOne parents
        $image = MetadataRegistry::get(Image::class);
        $this->assertArrayHasKey('imageable_kind', $image->columns);
        $this->assertArrayHasKey('imageable_ref', $image->columns);
        $cover = MetadataRegistry::get(Article::class)->relation('cover');
        $this->assertSame(RelationType::MorphOne, $cover->type);
        $this->assertSame(['imageable_kind', 'imageable_ref'], [$cover->morphTypeColumn, $cover->morphIdColumn]);
        $this->assertSame(Article::class, $cover->morphClass);

        $comments = MetadataRegistry::get(Video::class)->relation('comments');
        $this->assertSame(RelationType::MorphMany, $comments->type);
        $this->assertTrue($comments->isToMany());
        $this->assertSame(['commentable_type', 'commentable_id'], [$comments->morphTypeColumn, $comments->morphIdColumn]);
    }

    public function testMorphToManyMetadataUsesLaravelPivotDefaults(): void
    {
        $labels = MetadataRegistry::get(Article::class)->relation('labels');
        $this->assertSame(RelationType::MorphToMany, $labels->type);
        $this->assertSame(['labelables', 'labelable_id', 'label_id', 'labelable_type'], [$labels->joinTable, $labels->joinTableColumn, $labels->inverseJoinColumn, $labels->morphTypeColumn]);
        $this->assertSame(Article::class, $labels->morphClass);

        $articles = MetadataRegistry::get(Label::class)->relation('articles');
        $this->assertSame(RelationType::MorphedByMany, $articles->type);
        $this->assertSame(['labelables', 'label_id', 'labelable_id', 'labelable_type'], [$articles->joinTable, $articles->joinTableColumn, $articles->inverseJoinColumn, $articles->morphTypeColumn]);
        $this->assertSame(Article::class, $articles->morphClass);

        $custom = MetadataRegistry::get(MorphCustomPivotPost::class)->relation('tags');
        $this->assertSame(['pivot_tags', 'owner_ref', 'tag_ref', 'owner_kind'], [$custom->joinTable, $custom->joinTableColumn, $custom->inverseJoinColumn, $custom->morphTypeColumn]);
    }

    // ---- morph map ------------------------------------------------------------------------------

    public function testMorphMapAliasesAttributeAliasesAndClassNames(): void
    {
        $this->assertSame('video', MorphMap::aliasFor(Video::class));          // morphMap option
        $this->assertSame('article', MorphMap::aliasFor(Article::class));      // #[MorphAlias]
        $this->assertSame(Writer::class, MorphMap::aliasFor(Writer::class));   // none: class name
        $this->assertSame(Video::class, MorphMap::classFor('video'));
        $this->assertSame(Article::class, MorphMap::classFor('article'));
        $this->assertSame(Writer::class, MorphMap::classFor(Writer::class));
        $this->assertSame('article', MetadataRegistry::get(Article::class)->morphAlias);

        $this->seed();
        $types = $this->conn->fetchAll('SELECT DISTINCT commentable_type FROM comments WHERE commentable_type IS NOT NULL ORDER BY commentable_type');
        $this->assertSame(['article', 'video'], array_column($types, 'commentable_type'));
        $kinds = array_column($this->conn->fetchAll('SELECT imageable_kind FROM images ORDER BY id'), 'imageable_kind');
        $this->assertSame(['article', Writer::class], $kinds);
        $this->assertSame(['article', 'video'], array_column($this->conn->fetchAll('SELECT DISTINCT labelable_type FROM labelables ORDER BY labelable_type'), 'labelable_type'));
    }

    public function testUnknownAndConflictingAliasesThrow(): void
    {
        try {
            MorphMap::classFor('podcast');
            $this->fail('unknown alias');
        } catch (OrmException $e) {
            $this->assertStringContainsString('Unknown morph type "podcast"', $e->getMessage());
        }
        $this->expectException(OrmException::class);
        MorphMap::map(['video' => Article::class]);
    }

    public function testMorphMapOptionOnOrmModule(): void
    {
        $module = new \Nesto\Orm\OrmModule(type: 'sqlite', database: ':memory:', morphMap: ['clip' => Video::class]);
        $provider = $module->toDynamicModule()->providers[0];
        $this->assertSame(['clip' => Video::class], $provider->useValue['morphMap']);
    }

    // ---- saving ---------------------------------------------------------------------------------

    public function testAssigningAnEntityToMorphToSetsBothColumns(): void
    {
        $e = $this->seed();
        $comments = $this->ds->getRepository(Comment::class);
        $comment = $comments->create(['body' => 'new']);
        $comment->commentable = $e['v2'];
        $comments->save($comment);
        $this->assertSame(['video', 2], $this->morphColumns($comment->id));

        $comment->commentable = $e['a3'];
        $comments->save($comment);
        $this->assertSame(['article', 3], $this->morphColumns($comment->id));

        // loaded without the relation: saving keeps the columns (the property is "not loaded", not null)
        $loaded = $comments->findOneByOrFail(['body' => 'new']);
        $this->assertFalse(isset($loaded->commentable));
        $loaded->body = 'edited';
        $comments->save($loaded);
        $this->assertSame(['article', 3], $this->morphColumns($comment->id));

        $loaded->commentable = null;
        $comments->save($loaded);
        $this->assertSame([null, null], $this->morphColumns($comment->id));

        // an unsaved target is inserted first
        $comment2 = $comments->create(['body' => 'cascade']);
        $comment2->commentable = $this->em->create(Video::class, ['title' => 'V3']);
        $comments->save($comment2);
        $this->assertSame(3, $comment2->commentable->id);
        $this->assertSame(['video', 3], $this->morphColumns($comment2->id));

        // by column name, like a request body
        $comment3 = $comments->save($comments->create(['body' => 'plain', 'commentable_type' => 'article', 'commentable_id' => 2]));
        $this->assertSame(['article', 2], $this->morphColumns($comment3->id));
        $this->assertSame('A2', $comments->findOneOrFail(['where' => ['id' => $comment3->id], 'relations' => ['commentable']])->commentable->title);
    }

    public function testMorphToRejectsScalarsAndArrays(): void
    {
        $this->expectException(OrmException::class);
        $this->ds->getRepository(Comment::class)->create(['body' => 'x', 'commentable' => ['title' => 'nope']]);
    }

    public function testMorphManyAndMorphOneSaveTheirChildren(): void
    {
        $e = $this->seed();
        $this->assertSame(
            [['a1-1', 'article', 1], ['a1-2', 'article', 1], ['a2-1', 'article', 2], ['v1-1', 'video', 1], ['v1-2', 'video', 1], ['orphan', null, null]],
            array_map(fn (array $r) => [$r['body'], $r['commentable_type'], $r['commentable_id'] === null ? null : (int) $r['commentable_id']], $this->conn->fetchAll('SELECT * FROM comments ORDER BY id')),
        );

        // moving a loaded comment to another parent through the parent's list
        $v2 = $this->ds->getRepository(Video::class)->findOneOrFail(['where' => ['id' => $e['v2']->id], 'relations' => ['comments']]);
        $moved = $this->ds->getRepository(Comment::class)->findOneByOrFail(['body' => 'a2-1']);
        $v2->comments[] = $moved;
        $this->em->save($v2);
        $this->assertSame(['video', 2], $this->morphColumns($moved->id));

        // MorphOne
        $a3 = $e['a3'];
        $a3->cover = $this->em->create(Image::class, ['url' => 'a3.png']);
        $this->em->save($a3);
        $this->assertSame(['article', 3], array_values($this->conn->fetchOne('SELECT imageable_kind, imageable_ref FROM images WHERE url = :u', ['u' => 'a3.png'])));
    }

    public function testMorphToManySyncsOnlyItsOwnTypeAndSkipsUnchangedLists(): void
    {
        $e = $this->seed();
        $articles = $this->ds->getRepository(Article::class);
        // article 1 and video 1 share id 1: syncing the article must leave the video's rows alone
        $a1 = $articles->findOneOrFail(['where' => ['id' => 1], 'relations' => ['labels']]);
        $this->assertSame(['php', 'orm'], self::pluck($a1->labels, 'name'));
        $a1->labels = [$e['sql']];
        $articles->save($a1);
        $this->assertSame(['sql'], self::pluck($articles->findOneOrFail(['where' => ['id' => 1], 'relations' => ['labels']])->labels, 'name'));
        $this->assertSame(['php'], self::pluck($this->ds->getRepository(Video::class)->findOneOrFail(['where' => ['id' => 1], 'relations' => ['labels']])->labels, 'name'));

        $this->startCounting();
        $articles->save($a1);
        $this->assertSame([], $this->queries(), 'unchanged labels: no pivot writes');

        // the inverse side (MorphedByMany) syncs too, scoped to its type
        $php = $this->ds->getRepository(Label::class)->findOneOrFail(['where' => ['name' => 'php'], 'relations' => ['articles', 'videos']]);
        $this->assertSame([], $php->articles);
        $this->assertSame(['V1'], self::pluck($php->videos, 'title'));
        $php->articles = [$e['a2'], $e['a4']];
        $this->em->save($php);
        $this->assertSame(['A2', 'A4'], self::pluck($this->ds->getRepository(Label::class)->findOneOrFail(['where' => ['name' => 'php'], 'relations' => ['articles']])->articles, 'title'));
        $this->assertSame(['V1'], self::pluck($this->ds->getRepository(Label::class)->findOneOrFail(['where' => ['name' => 'php'], 'relations' => ['videos']])->videos, 'title'));

        // new labels are inserted, deleting a label cascades its pivot rows
        $a1->labels = [$this->em->create(Label::class, ['name' => 'new'])];
        $articles->save($a1);
        $this->assertSame(['new'], self::pluck($articles->findOneOrFail(['where' => ['id' => 1], 'relations' => ['labels']])->labels, 'name'));
        $this->ds->getRepository(Label::class)->delete(['name' => 'new']);
        $this->assertSame(0, (int) $this->conn->fetchColumn("SELECT COUNT(*) FROM labelables WHERE labelable_type = 'article' AND labelable_id = 1"));
    }

    // ---- eager loading --------------------------------------------------------------------------

    public function testMorphToLoadsOneQueryPerTypeForAHundredComments(): void
    {
        $articles = $this->ds->getRepository(Article::class);
        $videos = $this->ds->getRepository(Video::class);
        $writer = $this->em->save($this->em->create(Writer::class, ['name' => 'W']));
        $parents = [];
        for ($i = 1; $i <= 10; $i++) {
            $article = $articles->create(['title' => "A$i"]);
            $article->writer = $writer;
            $parents[] = $articles->save($article);
            $parents[] = $videos->save($videos->create(['title' => "V$i"]));
        }
        $rows = [];
        for ($i = 0; $i < 100; $i++) {
            $parent = $parents[$i % 20];
            $rows[] = ['body' => "c$i", 'commentable_type' => MorphMap::aliasFor($parent::class), 'commentable_id' => $parent->id];
        }
        $this->ds->getRepository(Comment::class)->insert($rows);

        $this->startCounting();
        $comments = $this->ds->getRepository(Comment::class)->find(['relations' => ['commentable']]);
        $this->assertCount(100, $comments);
        $this->assertCount(3, $this->queries(), 'comments + one query for articles + one for videos');
        $this->assertStringContainsString('"articles"', $this->queries()[1] . $this->queries()[2]);
        $this->assertStringContainsString('"videos"', $this->queries()[1] . $this->queries()[2]);
        foreach ($comments as $i => $comment) {
            $this->assertSame($parents[$i % 20]::class, $comment->commentable::class);
            $this->assertSame($parents[$i % 20]->id, $comment->commentable->id);
        }
        $this->assertSame($comments[0]->commentable, $comments[20]->commentable, 'one instance per row');
    }

    public function testMorphManyMorphOneAndMorphToManyEagerLoading(): void
    {
        $this->seed();
        $this->startCounting();
        $articles = $this->ds->getRepository(Article::class)->find(['relations' => ['comments', 'cover', 'labels']]);
        $this->assertCount(4, $this->queries());
        $this->assertSame(['a1-1', 'a1-2'], self::pluck($articles[0]->comments, 'body'));  // not v1-* although video 1 has id 1
        $this->assertSame(['a2-1'], self::pluck($articles[1]->comments, 'body'));
        $this->assertSame([], $articles[2]->comments);
        $this->assertSame('a1.png', $articles[0]->cover->url);
        $this->assertNull($articles[1]->cover);
        $this->assertSame(['php', 'orm'], self::pluck($articles[0]->labels, 'name'));
        $this->assertSame([], $articles[3]->labels);

        $videos = $this->ds->getRepository(Video::class)->find(['relations' => ['comments', 'labels']]);
        $this->assertSame(['v1-1', 'v1-2'], self::pluck($videos[0]->comments, 'body'));
        $this->assertSame(['php'], self::pluck($videos[0]->labels, 'name'));
        $this->assertSame([], $videos[1]->comments);

        $ada = $this->ds->getRepository(Writer::class)->findOneOrFail(['where' => ['name' => 'Ada'], 'relations' => ['avatar']]);
        $this->assertSame('ada.png', $ada->avatar->url);  // type stored as the class name

        $images = $this->ds->getRepository(Image::class)->find(['relations' => ['imageable']]);
        $this->assertInstanceOf(Article::class, $images[0]->imageable);
        $this->assertInstanceOf(Writer::class, $images[1]->imageable);

        $orphan = $this->ds->getRepository(Comment::class)->findOneOrFail(['where' => ['body' => 'orphan'], 'relations' => ['commentable']]);
        $this->assertNull($orphan->commentable);
    }

    public function testNestedPathsThroughPolymorphicRelations(): void
    {
        $this->seed();
        $this->startCounting();
        $articles = $this->ds->getRepository(Article::class)->find(['relations' => ['comments.commentable', 'country'], 'order' => ['id' => 'ASC']]);
        $this->assertCount(4, $this->queries(), 'articles, comments, commentable (articles only), country');
        $this->assertSame($articles[0]->comments[0]->commentable, $articles[0]->comments[1]->commentable);
        $this->assertSame('A1', $articles[0]->comments[0]->commentable->title);
        $this->assertSame('Iraq', $articles[0]->country->name);

        // per class: "writer" exists on Article only, "labels" on both
        $this->startCounting();
        $comments = $this->ds->getRepository(Comment::class)->find(['relations' => ['commentable.labels', 'commentable.writer.country']]);
        $this->assertCount(7, $this->queries(), 'comments, articles, videos, labels ×2, writers, countries');
        $this->assertSame(['php', 'orm'], self::pluck($comments[0]->commentable->labels, 'name'));
        $this->assertSame('Ada', $comments[0]->commentable->writer->name);
        $this->assertSame('Iraq', $comments[0]->commentable->writer->country->name);
        $this->assertSame(['php'], self::pluck($comments[3]->commentable->labels, 'name'));
        $this->assertFalse(isset($comments[3]->commentable->writer));

        $labels = $this->ds->getRepository(Label::class)->find(['relations' => ['articles.comments', 'videos.comments'], 'order' => ['name' => 'ASC']]);
        $this->assertSame(['A1'], self::pluck($labels[0]->articles, 'title'));  // orm
        $this->assertSame(['a1-1', 'a1-2'], self::pluck($labels[0]->articles[0]->comments, 'body'));
        $this->assertSame(['v1-1', 'v1-2'], self::pluck($labels[1]->videos[0]->comments, 'body')); // php

        $this->expectException(OrmException::class);
        $this->ds->getRepository(Comment::class)->find(['relations' => ['commentable.nope']]);
    }

    public function testQueryBuilderJoinsPolymorphicRelations(): void
    {
        $this->seed();
        $articles = $this->ds->createQueryBuilder(Article::class, 'a')
            ->leftJoinAndSelect('a.comments', 'c')
            ->leftJoinAndSelect('a.labels', 'l')
            ->leftJoinAndSelect('a.cover', 'img')
            ->orderBy('a.id')->addOrderBy('c.id')->addOrderBy('l.id')
            ->getMany();
        $this->assertSame(['a1-1', 'a1-2'], self::pluck($articles[0]->comments, 'body'));
        $this->assertSame(['php', 'orm'], self::pluck($articles[0]->labels, 'name'));
        $this->assertSame('a1.png', $articles[0]->cover->url);
        $this->assertNull($articles[1]->cover);
        $this->assertSame([], $articles[2]->comments);

        // a joined MorphToMany counts as loaded: saving it unchanged writes nothing
        $this->startCounting();
        $this->em->save($articles[0]);
        $this->assertSame([], array_values(array_filter($this->queries(), fn (string $sql) => str_contains($sql, 'labelables'))));

        $labels = $this->ds->createQueryBuilder(Label::class, 'l')->innerJoinAndSelect('l.videos', 'v')->getMany();
        $this->assertSame(['php'], self::pluck($labels, 'name'));
        $this->assertSame(['V1'], self::pluck($labels[0]->videos, 'title'));

        $withComments = $this->ds->createQueryBuilder(Video::class, 'v')->innerJoin('v.comments', 'c')->where('c.body = :b', ['b' => 'v1-2'])->getMany();
        $this->assertSame(['V1'], self::pluck($withComments, 'title'));

        $this->expectException(OrmException::class);
        $this->ds->createQueryBuilder(Comment::class, 'c')->leftJoinAndSelect('c.commentable', 'x');
    }

    public function testWhereOnAMorphToProperty(): void
    {
        $e = $this->seed();
        $comments = $this->ds->getRepository(Comment::class);
        $this->assertSame(['v1-1', 'v1-2'], self::pluck($comments->find(['where' => ['commentable' => $e['v1']]]), 'body'));
        $this->assertSame(['a1-1', 'a1-2'], self::pluck($comments->find(['where' => ['commentable' => $e['a1']]]), 'body'));
        $this->assertSame(['a2-1', 'v1-1', 'v1-2'], self::pluck($comments->find(['where' => ['commentable' => [$e['a2'], $e['v1']]]]), 'body'));
        $this->assertSame(['orphan'], self::pluck($comments->find(['where' => ['commentable' => null]]), 'body'));
        $this->assertSame(2, $comments->count(['where' => ['commentable' => $e['v1']]]));
    }

    public function testEntitiesSerializeWithTheirPolymorphicRelations(): void
    {
        $this->seed();
        $comment = $this->ds->getRepository(Comment::class)->findOneOrFail(['where' => ['body' => 'v1-1'], 'relations' => ['commentable']]);
        $json = json_decode((string) json_encode($comment), true);
        $this->assertSame(['id', 'body', 'commentable'], array_keys($json));
        $this->assertSame('V1', $json['commentable']['title']);
    }

    // ---- schema ---------------------------------------------------------------------------------

    public function testSynchronizeCreatesMorphColumnsIndexAndPivot(): void
    {
        $schema = $this->ds->schema();
        $this->assertSame(['id', 'body', 'commentable_type', 'commentable_id'], $schema->columns('comments'));
        $this->assertSame(['label_id', 'labelable_type', 'labelable_id'], $schema->columns('labelables'));
        $ddl = implode("\n", array_column($this->conn->fetchAll("SELECT sql FROM sqlite_master WHERE sql IS NOT NULL ORDER BY name"), 'sql'));
        $this->assertStringContainsString('CREATE TABLE "comments" ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "body" VARCHAR(255) NOT NULL, "commentable_type" VARCHAR(255), "commentable_id" INTEGER)', $ddl);
        $this->assertStringContainsString('CREATE INDEX "idx_comments_commentable_type_commentable_id" ON "comments" ("commentable_type", "commentable_id")', $ddl);
        $this->assertStringContainsString('CREATE INDEX "idx_images_imageable_kind_imageable_ref" ON "images" ("imageable_kind", "imageable_ref")', $ddl);
        $this->assertStringContainsString('CREATE TABLE "labelables" ("label_id" INTEGER NOT NULL, "labelable_type" VARCHAR(255) NOT NULL, "labelable_id" INTEGER NOT NULL, PRIMARY KEY ("label_id", "labelable_type", "labelable_id"), FOREIGN KEY ("label_id") REFERENCES "labels" ("id") ON DELETE CASCADE)', $ddl);
        $this->assertStringContainsString('CREATE INDEX "idx_labelables_labelable_type_labelable_id" ON "labelables" ("labelable_type", "labelable_id")', $ddl);

        $this->startCounting();
        $this->ds->synchronize();
        $this->assertSame([], array_values(array_filter($this->queries(), fn (string $sql) => preg_match('/^\s*(CREATE|ALTER)\b/i', $sql) === 1)));

        $this->ds->dropDatabase();
        $this->assertFalse($schema->hasTable('labelables'));
        $this->assertFalse($schema->hasTable('comments'));
    }

    public function testMorphIdColumnTypeFollowsTheParentsKeys(): void
    {
        $ds = new DataSource(['type' => 'sqlite', 'database' => ':memory:', 'entities' => [MorphUuidDoc::class, MorphIntDoc::class, MorphNote::class, MorphUuidOnlyNote::class], 'synchronize' => true]);
        $details = fn (string $table) => array_column($ds->schema()->columnDetails($table), 'type', 'name');
        $this->assertSame('varchar(255)', strtolower($details('morph_notes')['notable_id']), 'int and uuid parents: varchar');
        $this->assertSame('char(36)', strtolower($details('morph_uuid_only_notes')['notable_id']), 'MorphTo(types: [uuid entity]): uuid');
        $this->assertSame('char(36)', strtolower($details('morph_uuid_docs_tags')['owner_id']));

        // and it works end to end with both key types
        $uuidDoc = $ds->getRepository(MorphUuidDoc::class)->save($ds->getRepository(MorphUuidDoc::class)->create(['title' => 'u']));
        $intDoc = $ds->getRepository(MorphIntDoc::class)->save($ds->getRepository(MorphIntDoc::class)->create(['title' => 'i']));
        $notes = $ds->getRepository(MorphNote::class);
        foreach ([$uuidDoc, $intDoc] as $doc) {
            $note = $notes->create(['text' => $doc->title]);
            $note->notable = $doc;
            $notes->save($note);
        }
        $loaded = $notes->find(['relations' => ['notable']]);
        $this->assertSame($uuidDoc->id, $loaded[0]->notable->id);
        $this->assertSame($intDoc->id, $loaded[1]->notable->id);
        $this->assertSame(['u'], self::pluck($ds->getRepository(MorphUuidDoc::class)->findOneOrFail(['relations' => ['notes']])->notes, 'text'));
        $this->assertSame(['i'], self::pluck($ds->getRepository(MorphIntDoc::class)->findOneOrFail(['relations' => ['notes']])->notes, 'text'));
    }

    public function testBlueprintMorphsHelpers(): void
    {
        $schema = $this->ds->schema();
        $schema->createTable('activities', function (Blueprint $t): void {
            $t->id();
            $t->morphs('subject');
            $t->nullableUuidMorphs('causer');
        });
        $details = array_column($schema->columnDetails('activities'), null, 'name');
        $this->assertSame(['id', 'subject_type', 'subject_id', 'causer_type', 'causer_id'], array_keys($details));
        $this->assertFalse($details['subject_type']['nullable']);
        $this->assertTrue($details['causer_id']['nullable']);
        $this->assertSame('char(36)', strtolower($details['causer_id']['type']));
        $indexes = array_column($this->conn->fetchAll("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'activities' ORDER BY name"), 'name');
        $this->assertSame(['idx_activities_causer_type_causer_id', 'idx_activities_subject_type_subject_id'], $indexes);
    }

    public function testMorphColumnsDeclaredAsProperties(): void
    {
        $ds = new DataSource(['type' => 'sqlite', 'database' => ':memory:', 'entities' => [MorphExplicitComment::class, MorphPlainLike::class, MorphLikeablePost::class, ...RelationsSeeder::ENTITIES], 'synchronize' => true]);
        $this->assertSame(['id', 'commentable_type', 'commentable_id'], $ds->schema()->columns('morph_explicit_comments'));
        $articles = $ds->getRepository(Article::class);
        $writer = $ds->getEntityManager()->save($ds->getEntityManager()->create(Writer::class, ['name' => 'W']));
        $article = $articles->create(['title' => 'T']);
        $article->writer = $writer;
        $articles->save($article);

        $repo = $ds->getRepository(MorphExplicitComment::class);
        $byProperties = $repo->create(['commentableType' => 'article', 'commentableId' => $article->id]);
        $repo->save($byProperties);
        $this->assertSame('T', $repo->findOneOrFail(['where' => ['id' => $byProperties->id], 'relations' => ['commentable']])->commentable->title);

        $byEntity = $repo->create();
        $byEntity->commentable = $article;
        $repo->save($byEntity);
        $this->assertSame(['article', $article->id], [$byEntity->commentableType, $byEntity->commentableId], 'properties follow the assigned entity');
        $loaded = $repo->findOneOrFail(['where' => ['id' => $byEntity->id]]);
        $this->assertSame(['article', $article->id], [$loaded->commentableType, $loaded->commentableId]);

        $posts = $ds->getRepository(MorphLikeablePost::class);
        $post = $posts->create();
        $post->likes = [$ds->getRepository(MorphPlainLike::class)->create()];
        $posts->save($post);
        $this->assertSame([MorphLikeablePost::class, $post->id], [$post->likes[0]->likeableType, $post->likes[0]->likeableId]);
        $this->assertCount(1, $posts->findOneOrFail(['relations' => ['likes']])->likes);
    }

    /** @return array{0: ?string, 1: ?int} */
    private function morphColumns(int $commentId): array
    {
        $row = $this->conn->fetchOne('SELECT commentable_type, commentable_id FROM comments WHERE id = :id', ['id' => $commentId]);

        return [$row['commentable_type'], $row['commentable_id'] === null ? null : (int) $row['commentable_id']];
    }
}

#[Entity(name: 'morph_custom_pivot_posts')]
final class MorphCustomPivotPost
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[MorphToMany(Label::class, 'owner', table: 'pivot_tags', foreignPivotKey: 'owner_ref', relatedPivotKey: 'tag_ref', type: 'owner_kind')]
    public array $tags;
}

#[Entity(name: 'morph_explicit_comments')]
final class MorphExplicitComment
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column(nullable: true)]
    public ?string $commentableType = null;

    #[Column(nullable: true)]
    public ?int $commentableId = null;

    #[MorphTo]
    public ?object $commentable = null;
}

/** Morph columns as plain properties, no #[MorphTo]: MorphMany still fills them. */
#[Entity(name: 'morph_plain_likes')]
final class MorphPlainLike
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column(nullable: true)]
    public ?string $likeableType = null;

    #[Column(nullable: true)]
    public ?int $likeableId = null;
}

#[Entity(name: 'morph_likeable_posts')]
final class MorphLikeablePost
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[MorphMany(MorphPlainLike::class, 'likeable')]
    public array $likes;
}
