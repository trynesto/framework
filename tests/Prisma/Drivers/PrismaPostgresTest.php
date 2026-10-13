<?php

declare(strict_types=1);

namespace Nesto\Tests\Prisma\Drivers;

use Nesto\Prisma\Migrate\BufferedReporter;
use Nesto\Prisma\Migrate\DatabaseReset;
use Nesto\Prisma\Migrate\DbPush;
use Nesto\Prisma\Migrate\Migrate;
use Nesto\Prisma\PrismaProject;

final class PrismaPostgresTest extends PrismaDriverTestCase
{
    protected static function databaseUrl(): ?string
    {
        return self::urlFromEnv('NESTO_TEST_PGSQL_URL');
    }

    protected static function provider(): string
    {
        return 'postgresql';
    }

    private function extensionsSchema(string $extensions): PrismaProject
    {
        file_put_contents($this->dir . '/prisma/schema.prisma', sprintf(
            "generator client {\n  provider        = \"nesto-client\"\n  previewFeatures = [\"postgresqlExtensions\"]\n}\n\ndatasource db {\n  provider   = \"postgresql\"\n  url        = \"%s\"\n  extensions = %s\n}\n\nmodel Tag {\n  id   Int    @id @default(autoincrement())\n  name String @db.Citext\n}\n",
            $this->url,
            $extensions,
        ));

        return new PrismaProject($this->dir . '/prisma/schema.prisma', $this->dir);
    }

    /** @return array<string, string> extension => version */
    private function installedExtensions(PrismaProject $project): array
    {
        $rows = $project->connect()->fetchAll("SELECT extname AS name, extversion AS version FROM pg_extension WHERE extname <> 'plpgsql' ORDER BY extname");

        return array_column($rows, 'version', 'name');
    }

    public function testDbPushAndMigrateCreateTheDatasourceExtensions(): void
    {
        $project = $this->extensionsSchema('[citext, pg_trgm(schema: "public"), uuidOssp(map: "uuid-ossp")]');
        (new DbPush($project, new BufferedReporter()))->push();
        self::assertSame(['citext', 'pg_trgm', 'uuid-ossp'], array_keys($this->installedExtensions($project)));
        self::assertTrue($this->inSync($project));
        $connection = $project->connect();
        $connection->execute('INSERT INTO "Tag" (name) VALUES (\'PHP\')');
        self::assertSame(1, (int) $connection->fetchColumn('SELECT COUNT(*) FROM "Tag" WHERE name = \'php\''), 'citext is usable');

        // migrate dev writes the extensions into the migration, before the tables using them
        DatabaseReset::reset($project->connect());
        self::assertSame([], $this->installedExtensions($project), 'reset drops the extensions of the schema, like Prisma');
        $result = (new Migrate($project, new BufferedReporter()))->dev('init');
        $sql = $result->created->sql();
        self::assertStringContainsString("-- CreateExtension\nCREATE EXTENSION IF NOT EXISTS \"citext\";", $sql);
        self::assertStringContainsString("-- CreateExtension\nCREATE EXTENSION IF NOT EXISTS \"pg_trgm\" WITH SCHEMA \"public\";", $sql);
        self::assertStringContainsString("-- CreateExtension\nCREATE EXTENSION IF NOT EXISTS \"uuid-ossp\";", $sql);
        self::assertLessThan(strpos($sql, 'CREATE TABLE'), strpos($sql, 'CREATE EXTENSION'));
        self::assertSame(['citext', 'pg_trgm', 'uuid-ossp'], array_keys($this->installedExtensions($project)));
        $reporter = new BufferedReporter();
        self::assertNull((new Migrate($project, $reporter))->dev('again')->created, $reporter->output());

        // the installed version pinned: nothing to do; an extension left out of the list is not dropped
        $installed = $this->installedExtensions($project)['citext'];
        $project = $this->extensionsSchema('[citext(version: "' . $installed . '"), uuidOssp(map: "uuid-ossp")]');
        $reporter = new BufferedReporter();
        self::assertNull((new Migrate($project, $reporter))->dev('same version')->created, $reporter->output());
        self::assertSame(['citext', 'pg_trgm', 'uuid-ossp'], array_keys($this->installedExtensions($project)));
    }
}
