<?php

declare(strict_types=1);

namespace Nesto\Prisma\Migrate;

use Nesto\Orm\Connection;
use Nesto\Orm\Migration\Schema;
use Nesto\Prisma\PrismaException;
use Nesto\Prisma\PrismaProject;
use Nesto\Prisma\Schema\SchemaLoader;

/**
 * `prisma migrate diff`: the difference between two schema sources, as a summary or a SQL script.
 *
 *   sources: ['empty'], ['schema-datamodel', 'prisma/schema.prisma'], ['schema-datasource', 'prisma/schema.prisma'],
 *            ['migrations', 'prisma/migrations'], ['url', 'postgresql://...']
 *
 *   $result = (new MigrateDiff($rootDir))->diff(['empty'], ['schema-datamodel', 'prisma/schema.prisma']);
 *   $result['summary'];   $result['sql'];   $result['empty'];
 *
 * The "from" side is materialized in a database (a shadow database for empty / datamodel / migrations, the
 * live database for url / datasource, read-only) and compared with the "to" side. Shadow databases are created
 * on `shadowDatabaseUrl`'s server when given, else next to the first database URL the sources name.
 */
final class MigrateDiff
{
    /** @var list<ShadowDatabase> */
    private array $shadows = [];

    public function __construct(private readonly string $rootDir, private readonly ?string $shadowDatabaseUrl = null) {}

    /**
     * @param array{0: string, 1?: string} $from
     * @param array{0: string, 1?: string} $to
     * @return array{empty: bool, summary: string, sql: string, statements: list<string>}
     */
    public function diff(array $from, array $to): array
    {
        try {
            $provider = $this->provider($from) ?? $this->provider($to);
            if ($provider === null) {
                return ['empty' => true, 'summary' => 'No difference detected.', 'sql' => "-- This is an empty migration.\n", 'statements' => []];
            }
            foreach ([$from, $to] as $source) {
                $other = $this->provider($source);
                if ($other !== null && $other !== $provider) {
                    throw new PrismaException(sprintf('The two sides of the diff use different providers (%s and %s).', $provider, $other));
                }
            }
            $baseUrl = $this->baseUrl($provider, [$from, $to]);
            $connection = $this->materialize($from, $provider, $baseUrl);
            $target = $this->schemaOf($to, $provider, $baseUrl);
            $plan = (new Differ($connection))->plan(DatabaseSchema::introspect($connection), $target);
            $statements = $plan->isEmpty() ? [] : $plan->statements(new Schema($connection));

            return [
                'empty' => $plan->isEmpty(),
                'summary' => DiffSummary::describe($plan),
                'sql' => SqlScript::render($statements, [], $provider),
                'statements' => $statements,
            ];
        } finally {
            foreach ($this->shadows as $shadow) {
                $shadow->drop();
            }
            $this->shadows = [];
        }
    }

    /** @param array{0: string, 1?: string} $source */
    private function provider(array $source): ?string
    {
        return match ($source[0]) {
            'empty' => null,
            'url' => DatabaseUrl::providerOf((string) ($source[1] ?? '')),
            'schema-datamodel', 'schema-datasource' => $this->project((string) ($source[1] ?? ''))->datasource()->provider,
            'migrations' => (new MigrationsDirectory($this->path((string) ($source[1] ?? ''))))->lockedProvider(),
            default => throw new PrismaException("Unknown diff source \"{$source[0]}\"."),
        };
    }

    /** @param list<array{0: string, 1?: string}> $sources */
    private function baseUrl(string $provider, array $sources): ?DatabaseUrl
    {
        if ($this->shadowDatabaseUrl !== null) {
            return new DatabaseUrl($this->shadowDatabaseUrl, $provider, $this->rootDir);
        }
        foreach ($sources as $source) {
            if ($source[0] === 'url') {
                return new DatabaseUrl((string) $source[1], $provider, $this->rootDir);
            }
            if ($source[0] === 'schema-datamodel' || $source[0] === 'schema-datasource') {
                try {
                    return $this->project((string) $source[1])->databaseUrl();
                } catch (PrismaException) {
                    // no usable URL in this schema
                }
            }
        }
        if ($provider === 'sqlite') {
            return null;
        }
        throw new PrismaException('A shadow database is needed to compute this diff: pass --shadow-database-url (or a source with a database URL).');
    }

    /** A database holding the "from" state. @param array{0: string, 1?: string} $source */
    private function materialize(array $source, string $provider, ?DatabaseUrl $baseUrl): Connection
    {
        if ($source[0] === 'url') {
            return (new DatabaseUrl((string) $source[1], $provider, $this->rootDir))->connect();
        }
        if ($source[0] === 'schema-datasource') {
            return $this->project((string) $source[1])->connect();
        }
        $shadow = $this->shadow($provider, $baseUrl);
        $connection = $shadow->connection();
        if ($source[0] === 'schema-datamodel') {
            $target = SchemaTranslator::translate($this->project((string) $source[1])->document());
            (new Differ($connection))->plan(DatabaseSchema::empty(), $target)->apply(new Schema($connection));
        } elseif ($source[0] === 'migrations') {
            $runner = new MigrationRunner($connection);
            foreach ((new MigrationsDirectory($this->path((string) $source[1])))->migrations() as $migration) {
                $runner->apply($migration, false);
            }
        }

        return $connection;
    }

    /** The "to" state. @param array{0: string, 1?: string} $source */
    private function schemaOf(array $source, string $provider, ?DatabaseUrl $baseUrl): DatabaseSchema
    {
        return match ($source[0]) {
            'empty' => DatabaseSchema::empty(),
            'schema-datamodel' => SchemaTranslator::translate($this->project((string) $source[1])->document()),
            'url', 'schema-datasource', 'migrations' => DatabaseSchema::introspect($this->materialize($source, $provider, $baseUrl)),
            default => throw new PrismaException("Unknown diff source \"{$source[0]}\"."),
        };
    }

    private function shadow(string $provider, ?DatabaseUrl $baseUrl): ShadowDatabase
    {
        $base = $baseUrl ?? new DatabaseUrl('file:' . sys_get_temp_dir() . '/prisma_diff.db', $provider);
        $shadow = ShadowDatabase::create($base);
        $this->shadows[] = $shadow;

        return $shadow;
    }

    private function project(string $path): PrismaProject
    {
        $project = new PrismaProject($this->path($path), $this->rootDir);
        $project->loadEnv();

        return $project;
    }

    private function path(string $path): string
    {
        if ($path === '') {
            throw new PrismaException('The diff source needs a path.');
        }

        return str_starts_with($path, '/') ? $path : $this->rootDir . '/' . $path;
    }
}
