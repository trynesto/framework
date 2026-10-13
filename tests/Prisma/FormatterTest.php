<?php

declare(strict_types=1);

namespace Nesto\Tests\Prisma;

use Nesto\Prisma\Migrate\MigrationWarning;
use Nesto\Prisma\Migrate\SqlScript;
use Nesto\Prisma\Schema\Formatter;
use Nesto\Prisma\Schema\SchemaException;
use Nesto\Prisma\Schema\SchemaLoader;
use PHPUnit\Framework\TestCase;

final class FormatterTest extends TestCase
{
    public function testAlignsAndNormalizesLikePrismaFormat(): void
    {
        $source = <<<'PRISMA'
            // This is your Prisma schema file
            generator client {
              provider = "nesto-client"
              previewFeatures = ["views"]   // views
            }
            datasource db {
                provider = "postgresql"
                url = env("DATABASE_URL")
            }



            /// A user
            model User {
              id Int @id @default(autoincrement())
              /// the email
              email String @unique @db.VarChar(255)
              name String? // optional
              // a comment line
              createdAt DateTime @default( now() )


              posts Post[]
              @@map( "users" )
              @@index([email(sort:Desc),name],map:"idx")
            }
            enum Role {
              USER
              ADMINISTRATOR @map("admin")
            }
            model Post {
              id Int @id
              author User @relation(fields:[authorId],references:[id])
              authorId Int
            }
            PRISMA;
        $expected = <<<'PRISMA'
            // This is your Prisma schema file
            generator client {
              provider        = "nesto-client"
              previewFeatures = ["views"] // views
            }

            datasource db {
              provider = "postgresql"
              url      = env("DATABASE_URL")
            }

            /// A user
            model User {
              id        Int      @id @default(autoincrement())
              /// the email
              email     String   @unique @db.VarChar(255)
              name      String? // optional
              // a comment line
              createdAt DateTime @default(now())

              posts Post[]
              @@map("users")
              @@index([email(sort: Desc), name], map: "idx")
            }

            enum Role {
              USER
              ADMINISTRATOR @map("admin")
            }

            model Post {
              id       Int  @id
              author   User @relation(fields: [authorId], references: [id])
              authorId Int
            }

            PRISMA;
        $formatted = Formatter::format($source);
        self::assertSame($expected, $formatted);
        self::assertSame($formatted, Formatter::format($formatted), 'formatting is idempotent');
        // the formatted schema means the same thing
        self::assertEquals(SchemaLoader::load(str_replace('env("DATABASE_URL")', '"postgresql://x@y/z"', $source))->toArray(), SchemaLoader::load(str_replace('env("DATABASE_URL")', '"postgresql://x@y/z"', $formatted))->toArray());
    }

    public function testAddsMissingBackRelationFields(): void
    {
        $source = <<<'PRISMA'
            model User {
              id Int @id @default(autoincrement())
            }

            model Post {
              id       Int  @id @default(autoincrement())
              author   User @relation(fields: [authorId], references: [id])
              authorId Int
            }

            model Tag {
              id    Int    @id
              notes Note[]
            }

            model Note {
              id Int @id
            }
            PRISMA;
        $expected = <<<'PRISMA'
            model User {
              id   Int    @id @default(autoincrement())
              Post Post[]
            }

            model Post {
              id       Int  @id @default(autoincrement())
              author   User @relation(fields: [authorId], references: [id])
              authorId Int
            }

            model Tag {
              id    Int    @id
              notes Note[]
            }

            model Note {
              id    Int  @id
              Tag   Tag? @relation(fields: [tagId], references: [id])
              tagId Int?
            }

            PRISMA;
        self::assertSame($expected, Formatter::format($source));
        self::assertSame([], array_filter(SchemaLoader::diagnose("datasource db {\n  provider = \"sqlite\"\n  url = \"file:x\"\n}\n\n" . $expected), fn ($d) => $d->isError()));
    }

    public function testKeepsDocumentationAndBlockComments(): void
    {
        $source = "/// doc of the enum\nenum Role { // trailing\n  /// first\n  A // a\n  BB\n}\n\n// between blocks\n\n// another\nmodel X {\n  id Int @id\n  /// on the attribute\n  @@map(\"x\") // mapped\n}\n";
        $formatted = Formatter::format($source);
        self::assertSame("/// doc of the enum\nenum Role { // trailing\n  /// first\n  A // a\n  BB\n}\n\n// between blocks\n\n// another\nmodel X {\n  id Int @id\n  /// on the attribute\n  @@map(\"x\") // mapped\n}\n", $formatted);
    }

    public function testRefusesSyntaxErrors(): void
    {
        $this->expectException(SchemaException::class);
        Formatter::format("model X {\n  id Int @id\n  ???\n}\n");
    }

    public function testMigrationScriptLayout(): void
    {
        $sql = SqlScript::render([
            'CREATE TYPE "Role" AS ENUM (\'USER\', \'ADMIN\')',
            'CREATE TABLE "User" ("id" SERIAL NOT NULL, "role" "Role" NOT NULL DEFAULT \'USER\', CONSTRAINT "User_pkey" PRIMARY KEY ("id"))',
            'CREATE UNIQUE INDEX "User_email_key" ON "User" ("email")',
            'ALTER TABLE "Post" ADD CONSTRAINT "Post_authorId_fkey" FOREIGN KEY ("authorId") REFERENCES "User" ("id") ON DELETE RESTRICT ON UPDATE CASCADE',
            'ALTER TABLE "Post" DROP CONSTRAINT "Post_authorId_fkey"',
            'DROP INDEX "x"',
            'ALTER TABLE "User" ADD COLUMN "bio" TEXT',
            'DROP TABLE "Old"',
        ], [new MigrationWarning('You are about to drop the `Old` table. If the table is not empty, all the data it contains will be lost.')]);
        self::assertSame(<<<'SQL'
            /*
              Warnings:

              - You are about to drop the `Old` table. If the table is not empty, all the data it contains will be lost.

            */

            -- CreateEnum
            CREATE TYPE "Role" AS ENUM ('USER', 'ADMIN');

            -- CreateTable
            CREATE TABLE "User" (
                "id" SERIAL NOT NULL,
                "role" "Role" NOT NULL DEFAULT 'USER',

                CONSTRAINT "User_pkey" PRIMARY KEY ("id")
            );

            -- CreateIndex
            CREATE UNIQUE INDEX "User_email_key" ON "User"("email");

            -- AddForeignKey
            ALTER TABLE "Post" ADD CONSTRAINT "Post_authorId_fkey" FOREIGN KEY ("authorId") REFERENCES "User" ("id") ON DELETE RESTRICT ON UPDATE CASCADE;

            -- DropForeignKey
            ALTER TABLE "Post" DROP CONSTRAINT "Post_authorId_fkey";

            -- DropIndex
            DROP INDEX "x";

            -- AlterTable
            ALTER TABLE "User" ADD COLUMN "bio" TEXT;

            -- DropTable
            DROP TABLE "Old";

            SQL, $sql);
        self::assertSame("-- This is an empty migration.\n", SqlScript::render([]));
    }

    public function testSplitsScriptsIntoStatements(): void
    {
        $script = "-- comment; with semicolon\nCREATE TABLE \"a\" (\"x\" TEXT DEFAULT 'a;b');\n/* block; comment */\nDO \$\$ BEGIN CREATE TYPE \"t\" AS ENUM ('a'); EXCEPTION WHEN duplicate_object THEN NULL; END \$\$;\nPRAGMA foreign_keys=OFF;\nINSERT INTO a VALUES ('it''s');";
        self::assertSame([
            "CREATE TABLE \"a\" (\"x\" TEXT DEFAULT 'a;b')",
            "DO \$\$ BEGIN CREATE TYPE \"t\" AS ENUM ('a'); EXCEPTION WHEN duplicate_object THEN NULL; END \$\$",
            'PRAGMA foreign_keys=OFF',
            "INSERT INTO a VALUES ('it''s')",
        ], SqlScript::split($script));
        self::assertSame(["INSERT INTO a VALUES ('a\\';b')", 'SELECT 1'], SqlScript::split("INSERT INTO a VALUES ('a\\';b'); SELECT 1;", true));
    }
}
