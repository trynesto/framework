<?php

declare(strict_types=1);

namespace Nesto\Cache\Stores;

use Nesto\Orm\Connection;
use Nesto\Orm\Migration\Blueprint;
use Nesto\Orm\Migration\Schema;
use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 store in a database table (CACHE_DRIVER=database), Laravel's `cache` table: key, value, expiration.
 * It runs on the application's ORM connection (or the Prisma client's, or the connection of config/database.php)
 * on SQLite, MySQL, MariaDB and PostgreSQL.
 *
 *   new DatabaseStore($connection, table: 'cache', prefix: 'app_cache_', defaultTtl: 3600);
 *   new DatabaseStore(fn () => $dataSource->getConnection());   // resolved on first use
 *
 * The table is created on first use when it does not exist (CREATE TABLE IF NOT EXISTS; set `autoCreate: false`
 * and create it in a migration if your database user cannot run DDL):
 *
 *   key         varchar(255) primary key    the prefixed cache key
 *   value       long text                   base64 of serialize($value)
 *   expiration  bigint                      unix timestamp, 0 = forever
 *
 * Expired rows are deleted when they are read, by gc() (`php nesto cache:clear --expired`), and by a 2%
 * lottery on writes.
 */
final class DatabaseStore implements CacheInterface
{
    use Psr16Support;

    private ?Connection $connection = null;
    private bool $ready = false;

    /**
     * @param Connection|\Closure(): Connection $connection
     * @param array{0: int, 1: int}           $lottery    [chances, out of]: a write runs gc() with this probability
     */
    public function __construct(
        private readonly Connection|\Closure $connectionOrFactory,
        private readonly string $table = 'cache',
        private readonly string $prefix = '',
        ?int $defaultTtl = null,
        private readonly bool $autoCreate = true,
        private readonly array $lottery = [2, 100],
    ) {
        $this->defaultTtl = $defaultTtl;
    }

    public function table(): string
    {
        return $this->table;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function connection(): Connection
    {
        if ($this->connection === null) {
            $connection = $this->connectionOrFactory instanceof Connection ? $this->connectionOrFactory : ($this->connectionOrFactory)();
            if (! $connection instanceof Connection) {
                throw new \Nesto\Cache\CacheException('DatabaseStore: the connection factory must return a ' . Connection::class . '.');
            }
            $this->connection = $connection;
        }

        return $this->connection;
    }

    /** The cache table's definition (for a migration: `$schema->createFromBlueprint(DatabaseStore::blueprint('cache'))`). */
    public static function blueprint(string $table = 'cache'): Blueprint
    {
        $blueprint = new Blueprint($table);
        $blueprint->string('key', 255)->primary();
        $blueprint->longText('value');
        $blueprint->bigInteger('expiration')->index();

        return $blueprint;
    }

    /** Create the table when it is missing (done automatically on first use unless autoCreate is false). */
    public function createTable(): void
    {
        $schema = new Schema($this->connection());
        if (! $schema->hasTable($this->table)) {
            $schema->createFromBlueprint(self::blueprint($this->table), ifNotExists: true);
        }
        $this->ready = true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);
        $conn = $this->ready();
        $row = $conn->fetchOne(
            'SELECT ' . $conn->quote('value') . ', ' . $conn->quote('expiration') . ' FROM ' . $conn->quote($this->table) . ' WHERE ' . $conn->quote('key') . ' = :k',
            ['k' => $this->prefix . $key],
        );
        if ($row === null) {
            return $default;
        }
        if ($this->expired((int) $row['expiration'])) {
            $this->delete($key);

            return $default;
        }

        return $this->decode((string) $row['value'], $default);
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $list = [];
        foreach ($keys as $key) {
            $this->validateKey((string) $key);
            $list[] = (string) $key;
        }
        $out = array_fill_keys($list, $default);
        if ($list === []) {
            return $out;
        }
        $conn = $this->ready();
        $rows = $conn->fetchAll(
            'SELECT ' . $conn->quote('key') . ', ' . $conn->quote('value') . ', ' . $conn->quote('expiration') . ' FROM ' . $conn->quote($this->table) . ' WHERE ' . $conn->quote('key') . ' IN (:keys)',
            ['keys' => array_map(fn (string $k) => $this->prefix . $k, $list)],
        );
        $length = strlen($this->prefix);
        foreach ($rows as $row) {
            $key = substr((string) $row['key'], $length);
            if (! $this->expired((int) $row['expiration'])) {
                $out[$key] = $this->decode((string) $row['value'], $default);
            }
        }

        return $out;
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->validateKey($key);
        $conn = $this->ready();
        $seconds = $this->ttlSeconds($ttl) ?? $this->defaultTtl;
        $expiration = $seconds === null || $seconds === 0 ? 0 : time() + $seconds;
        $table = $conn->quote($this->table);
        [$k, $v, $e] = [$conn->quote('key'), $conn->quote('value'), $conn->quote('expiration')];
        $sql = "INSERT INTO $table ($k, $v, $e) VALUES (:k, :v, :e) " . ($conn->driver() === 'mysql'
            ? "ON DUPLICATE KEY UPDATE $v = VALUES($v), $e = VALUES($e)"
            : "ON CONFLICT ($k) DO UPDATE SET $v = excluded.$v, $e = excluded.$e");
        $conn->execute($sql, ['k' => $this->prefix . $key, 'v' => base64_encode(serialize($value)), 'e' => $expiration]);
        if ($this->lottery[0] > 0 && random_int(1, max(1, $this->lottery[1])) <= $this->lottery[0]) {
            $this->gc();
        }

        return true;
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);
        $conn = $this->ready();
        $conn->execute('DELETE FROM ' . $conn->quote($this->table) . ' WHERE ' . $conn->quote('key') . ' = :k', ['k' => $this->prefix . $key]);

        return true;
    }

    public function clear(): bool
    {
        $conn = $this->ready();
        if ($this->prefix === '') {
            $conn->execute('DELETE FROM ' . $conn->quote($this->table));

            return true;
        }
        $conn->execute(
            'DELETE FROM ' . $conn->quote($this->table) . ' WHERE ' . $conn->quote('key') . " LIKE :p ESCAPE '!'",
            ['p' => strtr($this->prefix, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%'],
        );

        return true;
    }

    public function has(string $key): bool
    {
        $sentinel = new \stdClass;

        return $this->get($key, $sentinel) !== $sentinel;
    }

    /** Delete every expired row (of every prefix); returns how many were removed. */
    public function gc(): int
    {
        $conn = $this->ready();
        $e = $conn->quote('expiration');

        return $conn->execute('DELETE FROM ' . $conn->quote($this->table) . " WHERE $e > 0 AND $e <= :now", ['now' => time()]);
    }

    private function ready(): Connection
    {
        $conn = $this->connection();
        if (! $this->ready) {
            if ($this->autoCreate) {
                $this->createTable();
            }
            $this->ready = true;
        }

        return $conn;
    }

    private function expired(int $expiration): bool
    {
        return $expiration > 0 && $expiration <= time();
    }

    private function decode(string $raw, mixed $default): mixed
    {
        $serialized = base64_decode($raw, true);
        if ($serialized === false) {
            return $default;
        }
        if ($serialized === 'b:0;') {
            return false;
        }
        $value = @unserialize($serialized);

        return $value === false ? $default : $value;
    }
}
