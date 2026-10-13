<?php

declare(strict_types=1);

namespace Nesto\Orm\Cache;

use Nesto\Orm\Connection;
use Nesto\Orm\DataSource;
use Nesto\Orm\Migration\Blueprint;
use Nesto\Orm\QueryRunner;
use Nesto\Orm\Schema\Table;

/**
 * TypeORM's database query cache: entries in the "query-result-cache" table (`cache.tableName`) of the
 * DataSource's database, always on the master. The table is part of the schema model, so `synchronize`,
 * `schema:log` and `migration:generate` create it like an entity table.
 */
final class DbQueryResultCache implements QueryResultCache
{
    public const DEFAULT_TABLE = 'query-result-cache';

    public function __construct(private readonly DataSource $dataSource, private readonly string $tableName = self::DEFAULT_TABLE) {}

    public function tableName(): string
    {
        return $this->tableName;
    }

    /** The cache table in the neutral schema model (id, identifier, time, duration, query, result — TypeORM's columns). */
    public function table(): Table
    {
        $table = new Blueprint($this->tableName);
        $table->increments('id');
        $table->string('identifier')->nullable();
        $table->bigInteger('time');
        $table->integer('duration');
        $table->longText('query');
        $table->longText('result');

        return $table->toTable();
    }

    public function connect(): void {}

    public function disconnect(): void {}

    public function synchronize(?QueryRunner $queryRunner = null): void
    {
        $schema = $this->dataSource->schema();
        if (! $schema->hasTable($this->tableName)) {
            $schema->createFromBlueprint(Blueprint::fromTable($this->table()));
        }
    }

    public function getFromCache(array $options, ?QueryRunner $queryRunner = null): ?array
    {
        $conn = $this->connection();
        $base = 'SELECT * FROM ' . $conn->quote($this->tableName) . ' WHERE ';
        $row = match (true) {
            ($options['identifier'] ?? null) !== null => $conn->fetchOne($base . $conn->quote('identifier') . ' = :v', ['v' => $options['identifier']]),
            ($options['query'] ?? null) !== null => $conn->fetchOne($base . $conn->quote('query') . ' = :v', ['v' => $options['query']]),
            default => null,
        };
        if ($row === null) {
            return null;
        }

        return [
            'identifier' => $row['identifier'] ?? null,
            'query' => $row['query'] ?? null,
            'time' => (int) $row['time'],
            'duration' => (int) $row['duration'],
            'result' => (string) $row['result'],
        ];
    }

    public function storeInCache(array $options, ?array $savedCache, ?QueryRunner $queryRunner = null): void
    {
        $conn = $this->connection();
        $values = [
            'identifier' => $options['identifier'] ?? null,
            'time' => (int) $options['time'],
            'duration' => (int) $options['duration'],
            'query' => (string) $options['query'],
            'result' => (string) $options['result'],
        ];
        $table = $conn->quote($this->tableName);
        if ($savedCache !== null && (($savedCache['identifier'] ?? null) !== null || ($savedCache['query'] ?? null) !== null)) {
            $by = ($savedCache['identifier'] ?? null) !== null ? 'identifier' : 'query';
            $sets = implode(', ', array_map(fn (string $c) => $conn->quote($c) . ' = :' . $c, array_keys($values)));
            $conn->execute("UPDATE $table SET $sets WHERE " . $conn->quote($by) . ' = :nesto_by', $values + ['nesto_by' => $values[$by]]);

            return;
        }
        $columns = implode(', ', array_map($conn->quote(...), array_keys($values)));
        $conn->execute("INSERT INTO $table ($columns) VALUES (:" . implode(', :', array_keys($values)) . ')', $values);
    }

    public function isExpired(array $savedCache): bool
    {
        return (int) $savedCache['time'] + (int) $savedCache['duration'] < (int) floor(microtime(true) * 1000);
    }

    public function clear(?QueryRunner $queryRunner = null): void
    {
        $conn = $this->connection();
        $conn->execute('DELETE FROM ' . $conn->quote($this->tableName));
    }

    public function remove(array $identifiers, ?QueryRunner $queryRunner = null): void
    {
        if ($identifiers === []) {
            return;
        }
        $conn = $this->connection();
        $conn->execute('DELETE FROM ' . $conn->quote($this->tableName) . ' WHERE ' . $conn->quote('identifier') . ' IN (:ids)', ['ids' => array_values($identifiers)]);
    }

    private function connection(): Connection
    {
        return $this->dataSource->getConnection();
    }
}
