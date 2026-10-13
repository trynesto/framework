<?php

declare(strict_types=1);

namespace Nesto\Prisma;

use Nesto\Config\Env;
use Nesto\Orm\Connection;
use Nesto\Prisma\Dmmf\Datasource;
use Nesto\Prisma\Dmmf\Document;
use Nesto\Prisma\Migrate\DatabaseUrl;
use Nesto\Prisma\Migrate\MigrationsDirectory;
use Nesto\Prisma\Schema\SchemaLoader;

/**
 * Where the Prisma files of a project are and how to reach its database: the schema file (`--schema`,
 * nesto.config.php `prisma.schema`, composer.json `extra.prisma.schema`, else prisma/schema.prisma or
 * ./schema.prisma), prisma/migrations next to it, `.env` (project root, then the schema folder; existing
 * variables win), the datasource URL and the seed command (`prisma.seed`).
 *
 *   $project = PrismaProject::resolve($rootDir, $schemaOption, $config['prisma'] ?? []);
 *   $document = $project->document();
 *   $connection = $project->connect();
 */
final class PrismaProject
{
    private ?Document $document = null;
    private bool $envLoaded = false;

    /** @param array<string, mixed> $config nesto.config.php "prisma" section */
    public function __construct(public readonly string $schemaPath, public readonly string $rootDir, private readonly array $config = []) {}

    /** @param array<string, mixed> $config */
    public static function resolve(string $rootDir, ?string $schema = null, array $config = []): self
    {
        $rootDir = rtrim($rootDir, '/');
        $candidates = [];
        if ($schema !== null && $schema !== '') {
            $candidates[] = $schema;
        } else {
            if (isset($config['schema']) && is_string($config['schema'])) {
                $candidates[] = $config['schema'];
            }
            $composer = $rootDir . '/composer.json';
            if (is_file($composer)) {
                $json = json_decode((string) file_get_contents($composer), true);
                if (is_array($json) && is_string($json['extra']['prisma']['schema'] ?? null)) {
                    $candidates[] = $json['extra']['prisma']['schema'];
                }
            }
            $candidates[] = 'prisma/schema.prisma';
            $candidates[] = 'schema.prisma';
        }
        foreach ($candidates as $candidate) {
            $path = str_starts_with($candidate, '/') ? $candidate : $rootDir . '/' . $candidate;
            if (is_file($path) || $schema !== null) {
                return new self($path, $rootDir, $config);
            }
        }

        return new self($rootDir . '/' . $candidates[0], $rootDir, $config);
    }

    public function schemaDir(): string
    {
        return dirname($this->schemaPath);
    }

    public function exists(): bool
    {
        return is_file($this->schemaPath);
    }

    /** Path relative to the project root, for messages. */
    public function relative(string $path): string
    {
        return str_starts_with($path, $this->rootDir . '/') ? substr($path, strlen($this->rootDir) + 1) : $path;
    }

    public function loadEnv(): void
    {
        if ($this->envLoaded) {
            return;
        }
        $this->envLoaded = true;
        Env::load([$this->rootDir . '/.env', $this->schemaDir() . '/.env']);
    }

    public function source(): string
    {
        if (! $this->exists()) {
            throw new PrismaException(sprintf(
                "Could not find Prisma Schema that is required for this command.\nYou can either provide it with `--schema`, or create it with `php nesto prisma:init`.\nChecked following paths:\n\n%s: file not found",
                $this->relative($this->schemaPath),
            ));
        }

        return (string) file_get_contents($this->schemaPath);
    }

    /** The validated schema (parsed once per project instance). */
    public function document(): Document
    {
        if ($this->document === null) {
            $this->loadEnv();
            $this->document = SchemaLoader::load($this->source(), $this->relative($this->schemaPath));
        }

        return $this->document;
    }

    /** Forget the parsed schema (after the file changed, e.g. db pull). */
    public function refresh(): void
    {
        $this->document = null;
    }

    public function datasource(): Datasource
    {
        return $this->document()->requireDatasource();
    }

    /**
     * The database the CLI works on (migrate, db push / pull, migrate diff): the datasource's `directUrl` when it
     * declares one (like Prisma: `url` may go through a connection pooler, migrations need a direct
     * connection), else `url`. The client always uses `url`.
     */
    public function databaseUrl(): DatabaseUrl
    {
        $this->loadEnv();
        $datasource = $this->datasource();
        $url = $datasource->directUrl !== null ? $datasource->directUrl->require('datasource directUrl') : $datasource->url->require();

        return new DatabaseUrl($url, $datasource->provider, $this->schemaDir());
    }

    public function shadowDatabaseUrl(): ?DatabaseUrl
    {
        $datasource = $this->datasource();
        $url = $datasource->shadowDatabaseUrl?->resolve();

        return $url !== null ? new DatabaseUrl($url, $datasource->provider, $this->schemaDir()) : null;
    }

    public function migrations(): MigrationsDirectory
    {
        return new MigrationsDirectory($this->schemaDir() . '/migrations');
    }

    /** Connect to the datasource; with $create, create the database first when it does not exist. */
    public function connect(bool $create = false, ?callable $created = null): Connection
    {
        $url = $this->databaseUrl();
        $connection = $create ? $url->connectOrCreate($created) : $url->connect();
        if ($url->provider === 'postgresql' && $url->schema() !== 'public' && $create) {
            $connection->execute('CREATE SCHEMA IF NOT EXISTS ' . $connection->quote($url->schema()));
        }

        return $connection;
    }

    /**
     * The seed command: nesto.config.php `prisma.seed`, composer.json `extra.prisma.seed`, else
     * prisma/seed.php when it exists. A `.php` file is run with the current PHP binary.
     */
    public function seedCommand(): ?string
    {
        $seed = $this->config['seed'] ?? null;
        if (! is_string($seed) || $seed === '') {
            $composer = $this->rootDir . '/composer.json';
            if (is_file($composer)) {
                $json = json_decode((string) file_get_contents($composer), true);
                $seed = is_array($json) && is_string($json['extra']['prisma']['seed'] ?? null) ? $json['extra']['prisma']['seed'] : null;
            }
        }
        if ((! is_string($seed) || $seed === '') && is_file($this->schemaDir() . '/seed.php')) {
            $seed = $this->relative($this->schemaDir() . '/seed.php');
        }
        if (! is_string($seed) || $seed === '') {
            return null;
        }
        if (preg_match('/\.php$/', $seed) === 1 && ! str_contains($seed, ' ')) {
            $file = str_starts_with($seed, '/') ? $seed : $this->rootDir . '/' . $seed;

            return escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file);
        }

        return $seed;
    }

    /** Where the compiled schema is cached for the runtime (see Dmmf\DmmfCache). */
    public function dmmfCacheFile(): string
    {
        $configured = $this->config['cache'] ?? null;

        return is_string($configured) && $configured !== '' ? (str_starts_with($configured, '/') ? $configured : $this->rootDir . '/' . $configured)
            : $this->rootDir . '/storage/cache/prisma-dmmf.php';
    }
}
