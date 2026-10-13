<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm;

use Nesto\Orm\Connection;
use Nesto\Orm\DataSource;
use Nesto\Orm\EntityManager;
use Nesto\Orm\MetadataRegistry;
use Nesto\Orm\MorphMap;
use Nesto\Tests\Orm\Fixtures\Relations\RelationsSeeder;
use PHPUnit\Framework\TestCase;

/** SQLite in memory with the polymorphic / through fixtures (tests/Orm/Fixtures/Relations). */
abstract class RelationFixturesTestCase extends TestCase
{
    protected DataSource $ds;
    protected EntityManager $em;
    protected Connection $conn;

    protected function setUp(): void
    {
        MetadataRegistry::clear();
        MorphMap::clear();
        $this->ds = new DataSource([
            'type' => 'sqlite',
            'database' => ':memory:',
            'entities' => RelationsSeeder::ENTITIES,
            'synchronize' => true,
            'morphMap' => RelationsSeeder::MORPH_MAP,
        ]);
        $this->ds->initialize();
        $this->em = $this->ds->getEntityManager();
        $this->conn = $this->ds->getConnection();
    }

    protected function tearDown(): void
    {
        MorphMap::clear();
    }

    /** @return array<string, object> */
    protected function seed(): array
    {
        return RelationsSeeder::seed($this->em);
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

    /** @param iterable<object> $entities @return list<string> */
    protected static function pluck(iterable $entities, string $property): array
    {
        $out = [];
        foreach ($entities as $entity) {
            $out[] = $entity->{$property};
        }

        return $out;
    }
}
