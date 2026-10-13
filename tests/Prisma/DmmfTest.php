<?php

declare(strict_types=1);

namespace Nesto\Tests\Prisma;

use Nesto\Prisma\Dmmf\DmmfCache;
use Nesto\Prisma\Dmmf\Document;
use Nesto\Prisma\Dmmf\Field;
use Nesto\Prisma\Dmmf\FieldDefault;
use Nesto\Prisma\Dmmf\Index;
use Nesto\Prisma\Schema\SchemaLoader;
use PHPUnit\Framework\TestCase;

final class DmmfTest extends TestCase
{
    public const SCHEMA = <<<'PRISMA'
        generator client {
          provider        = "nesto-client"
          output          = "../src/Generated/Prisma"
          previewFeatures = ["relationJoins"]
          customOption    = "yes"
        }

        datasource db {
          provider = "postgresql"
          url      = env("DATABASE_URL")
        }

        /// Blog users
        model User {
          id        Int       @id @default(autoincrement())
          email     String    @unique @db.VarChar(320)
          name      String?   @map("full_name")
          role      Role      @default(USER)
          posts     Post[]
          profile   Profile?
          createdAt DateTime  @default(now()) @map("created_at")
          updatedAt DateTime  @updatedAt

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
          slug       String
          author     User       @relation(fields: [authorId], references: [id])
          authorId   Int
          categories Category[]
          tags       String[]

          @@unique([authorId, slug], name: "authorSlug")
          @@index([title])
        }

        model Category {
          id    Int    @id @default(autoincrement())
          name  String
          posts Post[]
        }

        model Membership {
          userId  Int
          groupId Int
          role    String @default("member")

          @@id([userId, groupId])
        }

        enum Role {
          USER
          ADMIN @map("admin")
        }
        PRISMA;

    public function testFieldsLikePrismasDmmf(): void
    {
        $document = SchemaLoader::load(self::SCHEMA);
        $user = $document->model('User');
        self::assertSame('users', $user->dbName);
        self::assertSame('users', $user->tableName());
        self::assertSame('Blog users', $user->documentation);

        $id = $user->field('id');
        self::assertSame(['scalar', 'Int', true, true, true], [$id->kind, $id->type, $id->isId, $id->isRequired, $id->hasDefaultValue]);
        self::assertEquals(new FieldDefault('autoincrement'), $id->default);
        self::assertTrue($id->isAutoIncrement());

        $email = $user->field('email');
        self::assertTrue($email->isUnique);
        self::assertSame(['VarChar', ['320']], $email->nativeType);

        $name = $user->field('name');
        self::assertFalse($name->isRequired);
        self::assertSame('full_name', $name->dbName);
        self::assertSame('full_name', $name->columnName());

        $role = $user->field('role');
        self::assertSame([Field::ENUM, 'Role', 'USER'], [$role->kind, $role->type, $role->default]);
        self::assertTrue($user->field('updatedAt')->isUpdatedAt);

        $posts = $user->field('posts');
        self::assertSame([Field::OBJECT, 'Post', true, true, 'PostToUser', [], []], [$posts->kind, $posts->type, $posts->isList, $posts->isRequired, $posts->relationName, $posts->relationFromFields, $posts->relationToFields]);
        self::assertSame('ProfileToUser', $user->field('profile')->relationName);
        self::assertFalse($user->field('profile')->isRequired);

        $profileUser = $document->model('Profile')->field('user');
        self::assertSame(['userId'], $profileUser->relationFromFields);
        self::assertSame(['id'], $profileUser->relationToFields);
        self::assertSame('Cascade', $profileUser->relationOnDelete);
        self::assertTrue($document->model('Profile')->field('userId')->isReadOnly);
        self::assertEquals(new FieldDefault('uuid'), $document->model('Profile')->field('id')->default);
        self::assertSame('', $document->model('Profile')->field('bio')->default);

        $post = $document->model('Post');
        self::assertSame([['authorId', 'slug']], $post->uniqueFields);
        self::assertSame([['name' => 'authorSlug', 'fields' => ['authorId', 'slug']]], $post->uniqueIndexes);
        self::assertNull($post->primaryKey);
        self::assertTrue($post->field('tags')->isList);
        self::assertSame('CategoryToPost', $post->field('categories')->relationName);
        self::assertSame($post->field('author'), $document->oppositeField($document->model('User'), $posts));
        self::assertSame($posts, $document->oppositeField($post, $post->field('author')));

        $membership = $document->model('Membership');
        self::assertSame(['name' => null, 'fields' => ['userId', 'groupId']], $membership->primaryKey);
        self::assertSame(['userId', 'groupId'], $membership->primaryKeyFields());

        $enum = $document->enum('Role');
        self::assertSame(['USER', 'admin'], $enum->databaseValues());
        self::assertSame('Role', $enum->databaseName());
    }

    public function testDatasourceGeneratorAndImplicitManyToMany(): void
    {
        $document = SchemaLoader::load(self::SCHEMA);
        self::assertSame('postgresql', $document->provider());
        self::assertSame('DATABASE_URL', $document->datasource->url->fromEnvVar);
        self::assertSame('foreignKeys', $document->datasource->relationMode);
        $generator = $document->generator('client');
        self::assertSame('nesto-client', $generator->provider->value);
        self::assertSame('../src/Generated/Prisma', $generator->output->value);
        self::assertSame(['relationJoins'], $generator->previewFeatures);
        self::assertSame(['customOption' => 'yes'], $generator->config);

        self::assertCount(1, $document->manyToMany);
        $m2m = $document->manyToMany[0];
        self::assertSame(['CategoryToPost', '_CategoryToPost', 'Category', 'posts', 'Post', 'categories'], [$m2m->relationName, $m2m->table, $m2m->modelA, $m2m->fieldA, $m2m->modelB, $m2m->fieldB]);
        self::assertSame($m2m, $document->manyToManyOf('CategoryToPost'));
    }

    public function testIndexesCarryPrismaDatabaseNames(): void
    {
        $document = SchemaLoader::load(self::SCHEMA);
        $names = array_map(fn (Index $i) => $i->model . ' ' . $i->type . ' ' . $i->dbName, $document->indexes);
        self::assertSame([
            'User id users_pkey',
            'User unique users_email_key',
            'Profile id Profile_pkey',
            'Profile unique Profile_userId_key',
            'Post id Post_pkey',
            'Post unique Post_authorId_slug_key',
            'Post normal Post_title_idx',
            'Category id Category_pkey',
            'Membership id Membership_pkey',
        ], $names);
        $unique = $document->indexesOf('Post')[1];
        self::assertSame('authorSlug', $unique->name);
        self::assertSame(['authorId', 'slug'], $unique->fieldNames());
    }

    public function testArrayRoundTripAndPrismaShape(): void
    {
        $document = SchemaLoader::load(self::SCHEMA);
        $array = $document->toArray();
        self::assertSame(['datamodel', 'datasources', 'generators', 'nesto'], array_keys($array));
        self::assertSame(['enums', 'models', 'types', 'views', 'indexes'], array_keys($array['datamodel']));
        $id = $array['datamodel']['models'][0]['fields'][0];
        self::assertSame(['name' => 'autoincrement', 'args' => []], $id['default']);
        self::assertArrayNotHasKey('default', $array['datamodel']['models'][0]['fields'][1]);
        self::assertSame('PostToUser', $array['datamodel']['models'][0]['fields'][4]['relationName']);

        $copy = Document::fromArray($array);
        self::assertEquals($document->toArray(), $copy->toArray());
        self::assertEquals(new FieldDefault('autoincrement'), $copy->model('User')->field('id')->default);
        self::assertSame(json_decode($document->toJson(), true), json_decode(json_encode($copy->toArray()), true));
    }

    public function testCacheFileIsReusedUntilTheSchemaChanges(): void
    {
        $dir = sys_get_temp_dir() . '/nesto_prisma_cache_' . bin2hex(random_bytes(4));
        mkdir($dir);
        $schema = $dir . '/schema.prisma';
        $cache = $dir . '/cache/dmmf.php';
        file_put_contents($schema, self::SCHEMA);
        try {
            $first = DmmfCache::load($schema, $cache);
            self::assertFileExists($cache);
            $data = include $cache;
            self::assertSame(DmmfCache::hash(self::SCHEMA), $data['hash']);
            self::assertEquals($first->toArray(), DmmfCache::load($schema, $cache)->toArray());

            file_put_contents($schema, str_replace('model Category {', "model Tag {\n  id Int @id\n}\n\nmodel Category {", self::SCHEMA));
            self::assertNotNull(DmmfCache::load($schema, $cache)->model('Tag'), 'a changed schema is recompiled');
            // production mode trusts the cache file
            file_put_contents($schema, 'broken');
            self::assertNotNull(DmmfCache::load($schema, $cache, checkSource: false)->model('Tag'));
        } finally {
            @unlink($cache);
            @rmdir($dir . '/cache');
            @unlink($schema);
            @rmdir($dir);
        }
    }

    public function testRelationModePrismaAndExplicitNames(): void
    {
        $document = SchemaLoader::load(<<<'PRISMA'
            datasource db {
              provider     = "mysql"
              url          = "mysql://root@localhost/app"
              relationMode = "prisma"
            }

            model User {
              id      Int    @id
              written Post[] @relation("Written")
              edited  Post[] @relation("Edited")
            }

            model Post {
              id       Int  @id
              author   User @relation("Written", fields: [authorId], references: [id], map: "post_author")
              authorId Int
              editor   User @relation("Edited", fields: [editorId], references: [id], onUpdate: NoAction)
              editorId Int

              @@index([authorId])
              @@index([editorId])
            }
            PRISMA);
        self::assertSame('prisma', $document->datasource->relationMode);
        $post = $document->model('Post');
        self::assertSame('Written', $post->field('author')->relationName);
        self::assertSame('post_author', $post->field('author')->relationFkName);
        self::assertSame('NoAction', $post->field('editor')->relationOnUpdate);
        self::assertNull($document->indexesOf('Post')[0]->dbName, 'MySQL primary keys have no name');
    }
}
