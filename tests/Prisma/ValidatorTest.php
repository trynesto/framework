<?php

declare(strict_types=1);

namespace Nesto\Tests\Prisma;

use Nesto\Prisma\Schema\Diagnostic;
use Nesto\Prisma\Schema\SchemaException;
use Nesto\Prisma\Schema\SchemaLoader;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    /** @return list<string> error messages of a schema (datasource added) */
    private static function errors(string $models, string $provider = 'postgresql', string $extra = ''): array
    {
        $url = match ($provider) {
            'sqlite' => 'file:./dev.db',
            'mysql', 'mariadb' => 'mysql://root@localhost/db',
            default => 'postgresql://postgres@localhost/db',
        };
        $source = "datasource db {\n  provider = \"$provider\"\n  url      = \"$url\"\n$extra}\n\n$models";

        return array_values(array_map(fn (Diagnostic $d) => $d->message, array_filter(SchemaLoader::diagnose($source), fn (Diagnostic $d) => $d->isError())));
    }

    public function testValidSchemaHasNoErrors(): void
    {
        self::assertSame([], self::errors(<<<'PRISMA'
            model User {
              id      Int      @id @default(autoincrement())
              email   String   @unique
              role    Role     @default(USER)
              posts   Post[]
              profile Profile?
              friends User[]   @relation("Friends")
              friendOf User[]  @relation("Friends")
              manager   User?  @relation("Reports", fields: [managerId], references: [id])
              managerId Int?
              reports   User[] @relation("Reports")
            }

            model Profile {
              id     String @id @default(uuid())
              user   User   @relation(fields: [userId], references: [id], onDelete: Cascade)
              userId Int    @unique
            }

            model Post {
              id        Int      @id @default(autoincrement())
              title     String   @db.VarChar(200)
              createdAt DateTime @default(now())
              updatedAt DateTime @updatedAt
              author    User     @relation(fields: [authorId], references: [id])
              authorId  Int
              tags      String[] @default([])
              price     Decimal  @default(1.5) @db.Decimal(10, 2)
              data      Json     @default("{}")

              @@unique([authorId, title])
              @@index([title(sort: Desc)], type: Hash)
            }

            enum Role {
              USER
              ADMIN @map("admin")

              @@map("roles")
            }
            PRISMA));
    }

    public function testUnknownTypesAndDuplicates(): void
    {
        self::assertSame([
            'Type "Strin" is neither a built-in type, nor refers to another model, composite type, or enum.',
            'Field "id" is already defined on model "User".',
            'The model "User" cannot be defined because a model with that name already exists.',
        ], self::errors("model User {\n  id Int @id\n  name Strin\n  id Int\n}\n\nmodel User {\n  id Int @id\n}\n"));
    }

    public function testAttributesAndArguments(): void
    {
        self::assertSame([
            'Error parsing attribute "@id": Fields that are marked as id must be required.',
            'Attribute not known: "@foo".',
            'Attribute "@unique" can only be defined once.',
            'Error parsing attribute "@updatedAt": Fields that are marked with @updatedAt must be of type DateTime.',
            'Error parsing attribute "@unique": No such argument: `foo`.',
            'Attribute not known: "@@foo".',
        ], self::errors("model User {\n  id Int? @id\n  a String @foo\n  b String @unique @unique\n  c String @updatedAt\n  d String @unique(foo: \"x\")\n\n  @@foo\n}\n"));
    }

    public function testIdRules(): void
    {
        self::assertSame(
            ['Error validating model "NoId": Each model must have at least one unique criteria that has only required fields. Either mark a single field with `@id`, `@unique` or add a multi field criterion with `@@id([])` or `@@unique([])` to the model.'],
            self::errors("model NoId {\n  name String\n  maybe String? @unique\n}\n"),
        );
        self::assertSame([], self::errors("model U {\n  email String @unique\n}\n"), 'a required @unique field is a unique criteria');
        self::assertSame([], self::errors("model U {\n  a Int\n  b Int\n  @@unique([a, b])\n}\n"));
        self::assertSame([], self::errors("model U {\n  a Int\n  @@ignore\n}\n"), '@@ignore models need none');
        self::assertSame([
            'Error validating model "Two": At most one field must be marked as the id field with the `@id` attribute.',
            'Error validating model "Two": At most one field must be marked as the id field with the `@id` attribute.',
        ], self::errors("model Two {\n  a Int @id\n  b Int @id\n}\n"));
        self::assertSame(
            ['Error validating model "Both": Each model must have at most one id criteria. You can\'t have `@id` and `@@id` at the same time.'],
            self::errors("model Both {\n  a Int @id\n  b Int\n  @@id([a, b])\n}\n"),
        );
        self::assertSame(
            ['Error validating model "M": The multi field id declaration refers to the unknown fields: c.'],
            self::errors("model M {\n  a Int\n  @@id([a, c])\n}\n"),
        );
        self::assertSame(
            ['Error validating model "M": The id definition refers to the optional fields: b. ID definitions must reference only required fields.'],
            self::errors("model M {\n  a Int\n  b Int?\n  @@id([a, b])\n}\n"),
        );
        self::assertSame(
            ['Error validating model "M": The index definition refers to the unknown fields: nope.'],
            self::errors("model M {\n  id Int @id\n  @@index([nope])\n}\n"),
        );
    }

    public function testDefaults(): void
    {
        self::assertSame([
            'Error parsing attribute "@default": Expected an Int value, but received string value `"x"`.',
            'Error parsing attribute "@default": Expected a String value, but received numeric value `1`.',
            'Error parsing attribute "@default": Expected a boolean value, but received constant value `yes`.',
            'Error parsing attribute "@default": The function `autoincrement()` cannot be used on fields of type `String`.',
            'Error parsing attribute "@default": The function `now()` cannot be used on fields of type `Int`.',
            'Error parsing attribute "@default": The function `foo` is not a known function. You can read about the available functions here: https://pris.ly/d/attribute-functions.',
            'Error parsing attribute "@default": The defined default value `NOPE` is not a valid value of the enum specified for the field.',
            'Error parsing attribute "@default": Expected a JSON value, but failed while parsing "{oops".',
            'Error parsing attribute "@default": The uuid() function takes an optional version argument: 4 or 7.',
        ], self::errors(<<<'PRISMA'
            model D {
              id Int @id
              a  Int      @default("x")
              b  String   @default(1)
              c  Boolean  @default(yes)
              d  String   @default(autoincrement())
              e  Int      @default(now())
              f  String   @default(foo())
              g  Role     @default(NOPE)
              h  Json     @default("{oops")
              i  String   @default(uuid(5))
              ok1 String  @default(cuid(2))
              ok2 Int     @default(dbgenerated("1 + 1"))
              ok3 DateTime @default("2024-01-01T00:00:00Z")
            }

            enum Role {
              USER
            }
            PRISMA));
    }

    public function testAutoincrementRulesPerProvider(): void
    {
        $model = "model C {\n  id Int @id\n  n Int @default(autoincrement())\n}\n";
        self::assertSame([], self::errors($model, 'postgresql'));
        self::assertSame(['Error parsing attribute "@default": The `autoincrement()` default value is used on a non-indexed field even though the datasource does not support this.'], self::errors($model, 'mysql'));
        self::assertSame(['Error parsing attribute "@default": The `autoincrement()` default value is used on a non-id field even though the datasource does not support this.'], self::errors($model, 'sqlite'));
        self::assertSame([], self::errors("model C {\n  id Int @id\n  n Int @unique @default(autoincrement())\n}\n", 'mysql'));
    }

    public function testRelationNeedsTheOppositeField(): void
    {
        self::assertSame(
            ['Error validating field `author` in model `Post`: The relation field `author` on model `Post` is missing an opposite relation field on the model `User`. Either run `prisma format` or add it manually.'],
            self::errors("model User {\n  id Int @id\n}\n\nmodel Post {\n  id Int @id\n  author User @relation(fields: [authorId], references: [id])\n  authorId Int\n}\n"),
        );
    }

    public function testAmbiguousRelationsNeedNames(): void
    {
        $errors = self::errors(<<<'PRISMA'
            model User {
              id      Int    @id
              written Post[]
              edited  Post[]
            }

            model Post {
              id       Int  @id
              author   User @relation(fields: [authorId], references: [id])
              authorId Int
              editor   User @relation(fields: [editorId], references: [id])
              editorId Int
            }
            PRISMA);
        self::assertContains('Error validating model "Post": Ambiguous relation detected. The fields `author` and `editor` in model `Post` both refer to `User`. Please provide different relation names for them by adding `@relation(<name>).', $errors);
        self::assertContains('Error validating model "User": Ambiguous relation detected. The fields `written` and `edited` in model `User` both refer to `Post`. Please provide different relation names for them by adding `@relation(<name>).', $errors);

        self::assertSame(
            [
                'Error validating model "Node": Ambiguous self relation detected. The fields `parent` and `children` in model `Node` both refer to `Node`. If they are part of the same relation add the same relation name for them with `@relation(<name>)`.',
                'Error validating model "Node": Ambiguous self relation detected. The fields `parent` and `children` in model `Node` both refer to `Node`. If they are part of the same relation add the same relation name for them with `@relation(<name>)`.',
            ],
            self::errors("model Node {\n  id Int @id\n  parent Node? @relation(fields: [parentId], references: [id])\n  parentId Int?\n  children Node[]\n}\n"),
        );
    }

    public function testFieldsAndReferences(): void
    {
        $base = "model User {\n  id Int @id\n  code String @unique\n  name String\n  posts Post[]\n}\n\nmodel Post {\n  id Int @id\n  %s\n  authorId Int\n  authorCode String\n}\n";
        self::assertSame(
            ['Error parsing attribute "@relation": You must specify the same number of fields in `fields` and `references`.'],
            self::errors(sprintf($base, 'author User @relation(fields: [authorId, authorCode], references: [id])')),
        );
        self::assertSame(
            ['Error parsing attribute "@relation": The type of the field `authorCode` in the model `Post` is not matching the type of the referenced field `id` in model `User`.'],
            self::errors(sprintf($base, 'author User @relation(fields: [authorCode], references: [id])')),
        );
        self::assertSame(
            ['Error parsing attribute "@relation": The argument `references` must refer to a unique criterion in the related model. Consider adding an `@unique` attribute to the field `name` in the model `User`.'],
            self::errors(sprintf($base, 'author User @relation(fields: [authorCode], references: [name])')),
        );
        self::assertSame(
            ['Error validating: The argument fields must refer only to existing fields. The following fields do not exist in this model: nope'],
            self::errors(sprintf($base, 'author User @relation(fields: [nope], references: [id])')),
        );
        self::assertSame(
            ['Error validating: The argument `references` must refer only to existing fields in the related model `User`. The following fields do not exist in the related model: nope'],
            self::errors(sprintf($base, 'author User @relation(fields: [authorId], references: [nope])')),
        );
        self::assertSame(
            ['Error parsing attribute "@relation": The relation field `author` on Model `Post` must specify the `fields` argument in the @relation attribute. You can run `prisma format` to fix this automatically.',
                'Error parsing attribute "@relation": The relation field `author` on Model `Post` must specify the `references` argument in the @relation attribute.'],
            self::errors(sprintf($base, 'author User')),
        );
        self::assertSame(
            ['Error parsing attribute "@relation": The relation field `author` uses the scalar fields authorId. At least one of those fields is required. Hence the relation field must be required as well.'],
            self::errors(sprintf($base, 'author User? @relation(fields: [authorId], references: [id])')),
        );
        self::assertSame(
            ['Error parsing attribute "@relation": The `onDelete` referential action of a relation must not be set to `SetNull` when a referenced field is required. Either choose another referential action, or make the referenced fields optional.'],
            self::errors(sprintf($base, 'author User @relation(fields: [authorId], references: [id], onDelete: SetNull)')),
        );
        self::assertSame(
            ['Error parsing attribute "@relation": Invalid referential action: `Delete`'],
            self::errors(sprintf($base, 'author User @relation(fields: [authorId], references: [id], onDelete: Delete)')),
        );
    }

    public function testListSideMustNotDeclareFields(): void
    {
        self::assertSame(
            ['Error parsing attribute "@relation": The relation field `posts` on Model `User` must not specify the `fields` or `references` argument in the @relation attribute. You must only specify it on the opposite field `author` on model `Post`.'],
            self::errors("model User {\n  id Int @id\n  posts Post[] @relation(fields: [id], references: [id])\n}\n\nmodel Post {\n  id Int @id\n  author User @relation(fields: [authorId], references: [id])\n  authorId Int\n}\n"),
        );
    }

    public function testOneToOneRules(): void
    {
        self::assertSame(
            ['Error parsing attribute "@relation": A one-to-one relation must use unique fields on the defining side. Either add an `@unique` attribute to the field `userId`, or change the relation to one-to-many.'],
            self::errors("model User {\n  id Int @id\n  profile Profile?\n}\n\nmodel Profile {\n  id Int @id\n  user User @relation(fields: [userId], references: [id])\n  userId Int\n}\n"),
        );
        self::assertSame(
            ['Error parsing attribute "@relation": The relation field `profile` on Model `User` is required. This is not valid because it\'s not possible to enforce this constraint on the database level. Please change the field type from `Profile` to `Profile?` to fix this.'],
            self::errors("model User {\n  id Int @id\n  profile Profile\n}\n\nmodel Profile {\n  id Int @id\n  user User @relation(fields: [userId], references: [id])\n  userId Int @unique\n}\n"),
        );
        self::assertSame(
            ['Error parsing attribute "@relation": The relation fields `profile` on Model `User` and `user` on Model `Profile` do not provide the `fields` argument in the @relation attribute. You have to provide it on one of the two fields.'],
            self::errors("model User {\n  id Int @id\n  profile Profile?\n}\n\nmodel Profile {\n  id Int @id\n  user User\n}\n"),
        );
    }

    public function testImplicitManyToManyNeedsIds(): void
    {
        self::assertSame(
            ['Error validating field `tags` in model `Post`: The relation field `tags` on Model `Post` references `Tag` which does not have an `@id` field. Models without `@id` cannot be part of a many to many relation. Use an explicit intermediate Model to represent this relationship.'],
            self::errors("model Post {\n  id Int @id\n  tags Tag[]\n}\n\nmodel Tag {\n  name String @unique\n  posts Post[]\n}\n"),
        );
    }

    public function testProviderSpecificRules(): void
    {
        $enum = "model U {\n  id Int @id\n  role Role\n}\n\nenum Role {\n  A\n}\n";
        self::assertSame(['You defined the enum `Role`. But the current connector does not support enums.'], self::errors($enum, 'sqlite'));
        self::assertSame([], self::errors($enum, 'mysql'));

        $list = "model U {\n  id Int @id\n  tags String[]\n}\n";
        self::assertSame([], self::errors($list, 'postgresql'));
        self::assertSame(['Field "tags" in model "U" can\'t be a list. The current connector does not support lists of primitive types.'], self::errors($list, 'mysql'));

        self::assertSame(['Native types are not supported with sqlite connector'], self::errors("model U {\n  id Int @id\n  a String @db.VarChar(10)\n}\n", 'sqlite'));
        self::assertSame(['Native type Text2 is not supported for postgresql connector.'], self::errors("model U {\n  id Int @id\n  a String @db.Text2\n}\n"));
        self::assertSame(['Native type VarChar is not compatible with declared field type Int, expected field type String.'], self::errors("model U {\n  id Int @id\n  a Int @db.VarChar(3)\n}\n"));
        self::assertSame(['Native type VarChar takes 1 arguments, but received 0.'], self::errors("model U {\n  id Int @id\n  a String @db.VarChar\n}\n", 'mysql'));
        self::assertSame([], self::errors("model U {\n  id Int @id @db.UnsignedInt\n  a String @db.Text\n  b Boolean @db.TinyInt(1)\n}\n", 'mysql'));
        self::assertSame(['Attribute not known: "@other.VarChar".'], self::errors("model U {\n  id Int @id\n  a String @other.VarChar(3)\n}\n"));
    }

    public function testDatasourceAndGeneratorBlocks(): void
    {
        $diagnostics = SchemaLoader::diagnose("datasource db {\n  provider = \"oracle\"\n}\n\ngenerator client {\n  output = \"x\"\n  previewFeatures = [\"nope\"]\n}\n");
        $errors = array_values(array_map(fn ($d) => $d->message, array_filter($diagnostics, fn ($d) => $d->isError())));
        $warnings = array_values(array_map(fn ($d) => $d->message, array_filter($diagnostics, fn ($d) => ! $d->isError())));
        self::assertSame([
            'Argument "url" is missing in data source block "db".',
            'Datasource provider not known: "oracle".',
            'Argument "provider" is missing in generator block "client".',
        ], $errors);
        self::assertSame(['The preview feature "nope" is not known.'], $warnings);

        self::assertSame(['Error validating datasource `db`: the URL must start with the protocol `postgresql://` or `postgres://`.'], self::urlErrors());
        self::assertSame(['You defined more than one datasource. This is not allowed yet because support for multiple databases has not been implemented yet.'],
            array_values(array_map(fn ($d) => $d->message, SchemaLoader::diagnose("datasource a {\n  provider = \"sqlite\"\n  url = \"file:a.db\"\n}\n\ndatasource b {\n  provider = \"sqlite\"\n  url = \"file:b.db\"\n}\n"))));
    }

    /** @return list<string> */
    private static function urlErrors(): array
    {
        return array_values(array_map(fn ($d) => $d->message, SchemaLoader::diagnose("datasource db {\n  provider = \"postgresql\"\n  url = \"mysql://x\"\n}\n")));
    }

    public function testDeprecatedPreviewFeaturesWarn(): void
    {
        $document = SchemaLoader::load("generator client {\n  provider = \"nesto-client\"\n  previewFeatures = [\"interactiveTransactions\", \"views\"]\n}\n\ndatasource db {\n  provider = \"sqlite\"\n  url = \"file:./test.db\"\n}\n\nmodel U {\n  id Int @id\n}\n");
        self::assertSame(['Preview feature "interactiveTransactions" is deprecated. The functionality can be used without specifying it as a preview feature.'], $document->warnings);
    }

    public function testPostgresqlExtensions(): void
    {
        $generator = "generator client {\n  provider = \"nesto-client\"\n  previewFeatures = [\"postgresqlExtensions\"]\n}\n\n";
        $datasource = fn (string $provider, string $extensions) => sprintf("datasource db {\n  provider   = \"%s\"\n  url        = \"%s\"\n  extensions = %s\n}\n\nmodel U {\n  id Int @id\n}\n", $provider, $provider === 'postgresql' ? 'postgresql://x@y/z' : 'mysql://x@y/z', $extensions);

        $document = SchemaLoader::load($generator . $datasource('postgresql', '[citext, pg_trgm(schema: "public"), uuidOssp(map: "uuid-ossp", version: "1.1")]'));
        self::assertSame([
            ['name' => 'citext', 'dbName' => 'citext', 'schema' => null, 'version' => null],
            ['name' => 'pg_trgm', 'dbName' => 'pg_trgm', 'schema' => 'public', 'version' => null],
            ['name' => 'uuidOssp', 'dbName' => 'uuid-ossp', 'schema' => null, 'version' => '1.1'],
        ], $document->datasource->extensions);
        self::assertEquals($document->datasource, \Nesto\Prisma\Dmmf\Document::fromArray($document->toArray())->datasource, 'kept by the DMMF cache');

        $errors = fn (string $source) => array_values(array_map(fn (Diagnostic $d) => $d->message, array_filter(SchemaLoader::diagnose($source), fn (Diagnostic $d) => $d->isError())));
        self::assertSame(['The `extensions` property is only available with the `postgresqlExtensions` preview feature.'], $errors($datasource('postgresql', '[citext]')));
        self::assertSame(['The `extensions` property is only supported by the postgresql provider (this datasource uses "mysql").'], $errors($generator . $datasource('mysql', '[citext]')));
        self::assertSame(['Expected an array of extensions: `extensions = [citext, pg_trgm(schema: "public")]`.'], $errors($generator . $datasource('postgresql', '"citext"')));
        self::assertSame(['Unknown argument `size` of the extension `citext` (map, schema, version).'], $errors($generator . $datasource('postgresql', '[citext(size: "1")]')));
    }

    public function testMariaDbMapsToTheMysqlProviderWithANote(): void
    {
        $document = SchemaLoader::load("datasource db {\n  provider = \"mariadb\"\n  url = env(\"DATABASE_URL\")\n}\n\nmodel U {\n  id Int @id\n}\n");
        self::assertSame('mysql', $document->provider());
        self::assertSame('mariadb', $document->datasource->activeProvider);
        self::assertStringContainsString('provider "mariadb" runs on the "mysql" provider', $document->warnings[0]);
    }

    public function testViewsNeedThePreviewFeatureAndCompositeTypesAreRejected(): void
    {
        self::assertSame(['View definitions are only available with the `views` preview feature.'], self::errors("view V {\n  id Int @unique\n}\n"));
        self::assertSame(['Composite types are not supported on Postgres.'], self::errors("type Address {\n  street String\n}\n"));
        $source = "generator client {\n  provider = \"nesto-client\"\n  previewFeatures = [\"views\"]\n}\n\ndatasource db {\n  provider = \"postgresql\"\n  url = \"postgresql://x@y/z\"\n}\n\nview V {\n  id Int @unique\n  name String\n}\n";
        $document = SchemaLoader::load($source);
        self::assertTrue($document->model('V')->isView);
        self::assertSame([], $document->models());
    }

    public function testConstraintNameCollisions(): void
    {
        self::assertSame(
            ['The given constraint name `dup` has to be unique in the following namespace: global for primary key, indexes and unique constraints. Please provide a different name using the `map` argument.'],
            self::errors("model A {\n  id Int @id\n  x Int\n  @@index([x], map: \"dup\")\n}\n\nmodel B {\n  id Int @id\n  y Int @unique(map: \"dup\")\n}\n"),
        );
        self::assertSame([], self::errors("model A {\n  id Int @id\n  x Int\n  @@index([x], map: \"dup\")\n}\n\nmodel B {\n  id Int @id\n  y Int @unique(map: \"dup\")\n}\n", 'mysql'), 'MySQL index names are per table');
        self::assertSame(
            ['The model with database name "same" could not be defined because another model or view with this name exists: "A"'],
            self::errors("model A {\n  id Int @id\n  @@map(\"same\")\n}\n\nmodel B {\n  id Int @id\n  @@map(\"same\")\n}\n"),
        );
        self::assertSame(
            ['Error validating model "A": Field "a" and field "b" both map to a database column with the name "a".'],
            self::errors("model A {\n  id Int @id\n  a Int\n  b Int @map(\"a\")\n}\n"),
        );
    }

    public function testValidationErrorsAreReportedTogether(): void
    {
        try {
            SchemaLoader::load("datasource db {\n  provider = \"sqlite\"\n  url = \"file:dev.db\"\n}\n\nmodel A {\n  id Int @id\n  b Bogus\n  c String @updatedAt\n}\n", 'prisma/schema.prisma');
            self::fail('expected a SchemaException');
        } catch (SchemaException $e) {
            self::assertCount(2, $e->messages());
            self::assertStringContainsString('prisma/schema.prisma:8:5', $e->getMessage());
            self::assertStringContainsString('Validation Error Count: 2', $e->getMessage());
        }
    }
}
