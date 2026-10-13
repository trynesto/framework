<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm;

use Nesto\Orm\Connection;
use Nesto\Orm\DataSource;
use Nesto\Orm\EntityManager;
use Nesto\Orm\MetadataRegistry;
use Nesto\Tests\Orm\Fixtures\Library\LibrarySeeder;
use PHPUnit\Framework\TestCase;

/** SQLite in memory with the relation-behaviour fixtures (tests/Orm/Fixtures/Library). */
abstract class LibraryTestCase extends TestCase
{
    protected DataSource $ds;
    protected EntityManager $em;
    protected Connection $conn;

    protected function setUp(): void
    {
        MetadataRegistry::clear();
        $this->ds = new DataSource(['type' => 'sqlite', 'database' => ':memory:', 'entities' => LibrarySeeder::ENTITIES, 'synchronize' => true]);
        $this->ds->initialize();
        $this->em = $this->ds->getEntityManager();
        $this->conn = $this->ds->getConnection();
    }

    /** @return array<string, object> */
    protected function seed(): array
    {
        return LibrarySeeder::seed($this->em);
    }

    protected function startCounting(): void
    {
        $this->conn->enableQueryLog()->flushQueryLog();
    }

    /** @return list<string> */
    protected function queries(): array
    {
        return array_column($this->conn->getQueryLog(), 'sql');
    }

    /** @return list<mixed> one column of a raw query */
    protected function column(string $sql, array $params = []): array
    {
        return array_map(fn (array $row) => reset($row), $this->conn->fetchAll($sql, $params));
    }

    /** @param iterable<object> $entities @return list<mixed> */
    protected static function pluck(iterable $entities, string $property): array
    {
        $out = [];
        foreach ($entities as $entity) {
            $out[] = $entity->{$property};
        }

        return $out;
    }
}
