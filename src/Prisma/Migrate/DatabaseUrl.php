<?php

declare(strict_types=1);

namespace Nesto\Prisma\Migrate;

use Nesto\Orm\Connection;
use Nesto\Prisma\PrismaException;

/**
 * A datasource URL in Prisma's formats, turned into ORM connections:
 *
 *   postgresql://user:pass@host:5432/db?schema=public&sslmode=require     (postgres:// too)
 *   mysql://user:pass@host:3306/db                                         (mariadb:// accepted)
 *   file:./dev.db                                                          (SQLite, relative to the schema file)
 *
 *   $url = new DatabaseUrl('postgresql://postgres@127.0.0.1/app', 'postgresql');
 *   $connection = $url->connect();
 *   $url->database();                    // "app"
 *   $url->withDatabase('app_shadow');    // same server, another database
 *   $url->createDatabase();              // CREATE DATABASE when it does not exist
 */
final class DatabaseUrl
{
    public function __construct(
        public readonly string $url,
        public readonly string $provider,
        private readonly ?string $baseDir = null,
    ) {
        $ok = match ($provider) {
            'postgresql' => preg_match('#^postgres(ql)?://#i', $url) === 1,
            'mysql' => preg_match('#^(mysql|mariadb)://#i', $url) === 1,
            'sqlite' => str_starts_with($url, 'file:') || preg_match('#^sqlite3?:#i', $url) === 1,
            default => false,
        };
        if (! $ok) {
            throw new PrismaException(sprintf('Error validating datasource `db`: %s', match ($provider) {
                'postgresql' => 'the URL must start with the protocol `postgresql://` or `postgres://`.',
                'mysql' => 'the URL must start with the protocol `mysql://`.',
                'sqlite' => 'the URL must start with the protocol `file:`.',
                default => "unsupported provider \"$provider\".",
            }));
        }
    }

    /** Provider of a URL by its scheme (for `--from-url` without a schema). */
    public static function providerOf(string $url): string
    {
        return match (true) {
            preg_match('#^postgres(ql)?://#i', $url) === 1 => 'postgresql',
            preg_match('#^(mysql|mariadb)://#i', $url) === 1 => 'mysql',
            str_starts_with($url, 'file:') => 'sqlite',
            default => throw new PrismaException("Unsupported database URL: it must start with postgresql://, mysql:// or file:."),
        };
    }

    public function connect(): Connection
    {
        return Connection::fromOptions($this->options());
    }

    /** Connect, creating the database first when it does not exist (Prisma's migrate dev / db push). */
    public function connectOrCreate(?callable $created = null): Connection
    {
        try {
            return $this->connect();
        } catch (\PDOException $e) {
            if (! self::isUnknownDatabase($e) || $this->provider === 'sqlite') {
                throw $e;
            }
            $this->createDatabase();
            if ($created !== null) {
                $created($this->database());
            }

            return $this->connect();
        }
    }

    /** @return array<string, mixed> Connection::fromOptions() options */
    public function options(): array
    {
        if ($this->provider === 'sqlite') {
            return ['type' => 'sqlite', 'database' => $this->sqlitePath()];
        }
        $parts = parse_url(preg_replace('#^[a-z]+://#i', 'http://', $this->url) ?? '');
        if ($parts === false || ! isset($parts['host'])) {
            throw new PrismaException('The provided database string is invalid. The URL could not be parsed.');
        }
        parse_str($parts['query'] ?? '', $query);
        $options = [
            'type' => $this->provider === 'postgresql' ? 'pgsql' : (str_starts_with(strtolower($this->url), 'mariadb:') ? 'mariadb' : 'mysql'),
            'host' => rawurldecode($parts['host']),
            'port' => $parts['port'] ?? null,
            'username' => isset($parts['user']) ? rawurldecode($parts['user']) : null,
            'password' => isset($parts['pass']) ? rawurldecode($parts['pass']) : null,
            'database' => $this->database(),
        ];
        if ($this->provider === 'postgresql') {
            $extra = [];
            $schema = $this->schema();
            if ($schema !== 'public') {
                $extra[] = "options='--search_path=" . str_replace(["'", ' '], '', $schema) . "'";
            }
            foreach (['sslmode', 'sslcert', 'sslkey', 'sslrootcert', 'connect_timeout', 'application_name'] as $key) {
                if (isset($query[$key]) && is_string($query[$key])) {
                    $extra[] = $key . '=' . $query[$key];
                }
            }
            if ($extra !== []) {
                $options['url'] = sprintf('pgsql:host=%s;port=%d;dbname=%s;%s', $options['host'], (int) ($options['port'] ?? 5432), $options['database'], implode(';', $extra));
                $options['type'] = 'pgsql';
            }
        }

        return $options;
    }

    /** The database name (SQLite: the file path). */
    public function database(): string
    {
        if ($this->provider === 'sqlite') {
            return $this->sqlitePath();
        }
        $path = (string) parse_url(preg_replace('#^[a-z]+://#i', 'http://', $this->url) ?? '', PHP_URL_PATH);

        return rawurldecode(ltrim($path, '/'));
    }

    /** PostgreSQL schema (`?schema=`), "public" by default. */
    public function schema(): string
    {
        parse_str((string) parse_url($this->url, PHP_URL_QUERY), $query);

        return isset($query['schema']) && is_string($query['schema']) && $query['schema'] !== '' ? $query['schema'] : 'public';
    }

    /** The same server with another database (SQLite: another file). */
    public function withDatabase(string $database): self
    {
        if ($this->provider === 'sqlite') {
            return new self('file:' . $database, 'sqlite', $this->baseDir);
        }
        $url = (string) preg_replace('#^([a-z]+://[^/?]*)(/[^?]*)?#i', '$1/' . rawurlencode($database), $this->url);

        return new self($url, $this->provider, $this->baseDir);
    }

    /** "host:port" (or the file) for messages: `the "public" schema at "localhost:5432"`. */
    public function location(): string
    {
        if ($this->provider === 'sqlite') {
            return $this->sqlitePath();
        }
        $parts = parse_url(preg_replace('#^[a-z]+://#i', 'http://', $this->url) ?? '');

        return ($parts['host'] ?? 'localhost') . ':' . ($parts['port'] ?? ($this->provider === 'postgresql' ? 5432 : 3306));
    }

    public function createDatabase(): void
    {
        $name = $this->database();
        $server = $this->serverConnection();
        if ($this->provider === 'postgresql') {
            $exists = $server->fetchColumn('SELECT 1 FROM pg_database WHERE datname = :n', ['n' => $name]);
            if ($exists === false || $exists === null) {
                $server->execute('CREATE DATABASE ' . $server->quote($name));
            }
        } else {
            $server->execute('CREATE DATABASE IF NOT EXISTS ' . $server->quote($name) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        }
    }

    public function dropDatabase(): void
    {
        if ($this->provider === 'sqlite') {
            $path = $this->sqlitePath();
            if ($path !== ':memory:' && is_file($path)) {
                @unlink($path);
            }

            return;
        }
        $server = $this->serverConnection();
        if ($this->provider === 'postgresql') {
            $server->execute('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = :n AND pid <> pg_backend_pid()', ['n' => $this->database()]);
        }
        $server->execute('DROP DATABASE IF EXISTS ' . $server->quote($this->database()));
    }

    /** A connection to the server without the database (PostgreSQL: the "postgres" database). */
    private function serverConnection(): Connection
    {
        $options = $this->options();
        unset($options['url']);
        $options['database'] = $this->provider === 'postgresql' ? 'postgres' : '';

        return Connection::fromOptions($options);
    }

    private function sqlitePath(): string
    {
        $path = (string) preg_replace('#^(file:|sqlite3?:(//)?)#i', '', $this->url);
        $path = (string) preg_replace('/\?.*$/', '', $path);
        if ($path === '' || $path === ':memory:') {
            return ':memory:';
        }
        if (! str_starts_with($path, '/') && $this->baseDir !== null) {
            $path = rtrim($this->baseDir, '/') . '/' . (str_starts_with($path, './') ? substr($path, 2) : $path);
        }

        return $path;
    }

    private static function isUnknownDatabase(\PDOException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'Unknown database') || (str_contains($message, 'database "') && str_contains($message, 'does not exist'))
            || $e->getCode() === '3D000' || str_contains($message, '[1049]');
    }
}
