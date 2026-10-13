<?php

declare(strict_types=1);

namespace Nesto\Tests\Prisma;

use Nesto\Prisma\Schema\Ast\ArrayValue;
use Nesto\Prisma\Schema\Ast\Attribute;
use Nesto\Prisma\Schema\Ast\Block;
use Nesto\Prisma\Schema\Ast\Comment;
use Nesto\Prisma\Schema\Ast\ConstantValue;
use Nesto\Prisma\Schema\Ast\EmptyLine;
use Nesto\Prisma\Schema\Ast\FunctionCall;
use Nesto\Prisma\Schema\Ast\NumericValue;
use Nesto\Prisma\Schema\Ast\StringValue;
use Nesto\Prisma\Schema\Diagnostic;
use Nesto\Prisma\Schema\Parser;
use Nesto\Prisma\Schema\SchemaException;
use Nesto\Prisma\Schema\SchemaLoader;
use PHPUnit\Framework\TestCase;

final class ParserTest extends TestCase
{
    private const SCHEMA = <<<'PRISMA'
        // Top comment
        generator client {
          provider        = "nesto-client"
          previewFeatures = ["views"] // enable views
        }

        datasource db {
          provider = "postgresql"
          url      = env("DATABASE_URL")
        }

        /// A user of the blog.
        /// Second line.
        model User {
          id    Int     @id @default(autoincrement())
          /// The login email
          email String  @unique @db.VarChar(255)
          name  String? // shown publicly
          // posts written by the user
          posts Post[]
          tags  String[]
          geo   Unsupported("circle")?

          @@map("users")
          @@index([email(sort: Desc), name], map: "users_email_name")
        }

        enum Role {
          USER
          ADMIN @map("admin")
        }

        view Stats {
          id    Int @unique
          count Int
        }
        PRISMA;

    public function testParsesBlocksMembersAndComments(): void
    {
        $parser = new Parser(self::SCHEMA);
        $ast = $parser->parse();
        self::assertSame([], $parser->diagnostics);

        self::assertInstanceOf(Comment::class, $ast->items[0]);
        self::assertSame(' Top comment', $ast->items[0]->text);
        self::assertSame(['generator', 'datasource', 'model', 'enum', 'view'], array_map(fn (Block $b) => $b->kind, $ast->blocks()));

        $generator = $ast->block('generator', 'client');
        self::assertSame(' enable views', $generator->property('previewFeatures')->trailingComment);
        self::assertInstanceOf(ArrayValue::class, $generator->property('previewFeatures')->value);

        $url = $ast->block('datasource', 'db')->property('url')->value;
        self::assertInstanceOf(FunctionCall::class, $url);
        self::assertSame('env', $url->name);
        self::assertSame('DATABASE_URL', $url->arguments->get('name', 0)->value->value);

        $user = $ast->block('model', 'User');
        self::assertSame("A user of the blog.\nSecond line.", $user->documentation);
        self::assertSame(['id', 'email', 'name', 'posts', 'tags', 'geo'], array_map(fn ($f) => $f->name, $user->fields()));
        self::assertSame('The login email', $user->field('email')->documentation);
        self::assertSame(' shown publicly', $user->field('name')->trailingComment);
        self::assertTrue($user->field('name')->type->optional);
        self::assertTrue($user->field('posts')->type->list);
        self::assertSame('circle', $user->field('geo')->type->unsupported);
        self::assertTrue($user->field('geo')->type->optional);
        self::assertSame('Unsupported("circle")?', $user->field('geo')->type->toSource());

        $email = $user->field('email');
        self::assertSame(['unique', 'db.VarChar'], array_map(fn (Attribute $a) => $a->name, $email->attributes));
        self::assertInstanceOf(NumericValue::class, $email->attribute('db.VarChar')->arguments->get('x', 0)->value);
        $default = $user->field('id')->attribute('default')->arguments->get('value', 0)->value;
        self::assertInstanceOf(FunctionCall::class, $default);
        self::assertSame('autoincrement', $default->name);

        // comment lines and blank lines are members, in order
        $kinds = array_map(fn ($m) => (new \ReflectionClass($m))->getShortName(), $user->members);
        self::assertSame(['Field', 'Field', 'Field', 'Comment', 'Field', 'Field', 'Field', 'EmptyLine', 'Attribute', 'Attribute'], $kinds);

        $index = $user->attributes('index')[0];
        self::assertTrue($index->block);
        $fields = $index->arguments->get('fields', 0)->value;
        self::assertInstanceOf(ArrayValue::class, $fields);
        self::assertInstanceOf(FunctionCall::class, $fields->items[0]);
        self::assertSame('email', $fields->items[0]->name);
        self::assertInstanceOf(ConstantValue::class, $fields->items[0]->arguments->get('sort')->value);
        self::assertInstanceOf(StringValue::class, $index->arguments->get('map')->value);
        self::assertSame('@@index([email(sort: Desc), name], map: "users_email_name")', $index->toSource());

        $role = $ast->block('enum', 'Role');
        self::assertSame(['USER', 'ADMIN'], array_map(fn ($v) => $v->name, $role->values()));
        self::assertSame('admin', $role->values()[1]->attribute('map')->arguments->get('name', 0)->value->value);
        self::assertSame('view', $ast->block('view', 'Stats')->kind);
    }

    public function testSpansAreOneBased(): void
    {
        $ast = Parser::parseSource(self::SCHEMA);
        $user = $ast->block('model', 'User');
        self::assertSame(14, $user->span->line);
        self::assertSame(7, $user->nameSpan->column);
        $email = $user->field('email');
        self::assertSame(17, $email->span->line);
        self::assertSame(3, $email->span->column);
        self::assertSame(9, $email->type->span->column);
    }

    public function testStringEscapes(): void
    {
        $ast = Parser::parseSource("model A {\n  id String @id @default(\"a\\\"b\\\\c\\n\\u00e9\")\n}\n");
        $value = $ast->block('model', 'A')->field('id')->attribute('default')->arguments->get('value', 0)->value;
        self::assertSame("a\"b\\c\né", $value->value);
        self::assertSame('"a\"b\\\\c\n\\u00e9"', $value->toSource());
    }

    public function testReportsEverySyntaxErrorWithItsLocation(): void
    {
        $source = <<<'PRISMA'
            model User {
              id Int @id
              this is not a field
              name
            }

            foo bar

            enum Role {
              USER ADMIN
            }

            model Post {
              tags String[]?
            }
            PRISMA;
        $parser = new Parser($source);
        $parser->parse();
        $messages = array_map(fn (Diagnostic $d) => $d->span->line . ':' . $d->span->column . ' ' . $d->message, $parser->diagnostics);
        self::assertSame([
            '3:3 Error validating model "User": This line is not a valid field or attribute definition.',
            '4:3 Error validating model "User": This field declaration is invalid. It is either missing a name or a type.',
            '7:1 Error validating: This line is invalid. It does not start with any known Prisma schema keyword.',
            '10:3 Error validating enum "Role": This line is not an enum value definition.',
            '14:8 Optional lists are not supported. Use either `Type[]` or `Type?`.',
        ], $messages);
    }

    public function testMissingClosingBrace(): void
    {
        $parser = new Parser("model User {\n  id Int @id\n\nmodel Post {\n  id Int @id\n}\n");
        $ast = $parser->parse();
        self::assertCount(1, $parser->diagnostics);
        self::assertStringContainsString('model "User": This block is invalid. It is missing a closing curly brace', $parser->diagnostics[0]->message);
        self::assertSame(['User', 'Post'], array_map(fn (Block $b) => $b->name, $ast->blocks()));
    }

    public function testSchemaExceptionRendersLikePrisma(): void
    {
        try {
            SchemaLoader::parse("model User {\n  id Int @id\n  ???\n}\n", 'prisma/schema.prisma');
            self::fail('expected a SchemaException');
        } catch (SchemaException $e) {
            self::assertStringContainsString('Prisma schema validation - Error code: P1012', $e->getMessage());
            self::assertStringContainsString("error: Error validating model \"User\": This line is not a valid field or attribute definition.\n  -->  prisma/schema.prisma:3:3", $e->getMessage());
            self::assertStringContainsString("3 |   ???\n  |   ^^^", $e->getMessage());
            self::assertStringEndsWith('Validation Error Count: 1', $e->getMessage());
            self::assertSame(['3:3: Error validating model "User": This line is not a valid field or attribute definition.'], $e->messages());
        }
    }

    public function testEmptyLinesBetweenBlocks(): void
    {
        $ast = Parser::parseSource("model A {\n  id Int @id\n}\n\n\nmodel B {\n  id Int @id\n}\n");
        self::assertInstanceOf(Block::class, $ast->items[0]);
        self::assertInstanceOf(EmptyLine::class, $ast->items[1]);
        self::assertInstanceOf(Block::class, $ast->items[3]);
    }
}
