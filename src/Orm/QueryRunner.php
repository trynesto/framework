<?php

declare(strict_types=1);

namespace Nesto\Orm;

/**
 * TypeORM's QueryRunner: manual transaction control with an EntityManager bound to it.
 *
 *   $qr = $dataSource->createQueryRunner();
 *   $qr->connect();
 *   $qr->startTransaction();                    // or startTransaction('SERIALIZABLE')
 *   try {
 *       $qr->manager->save($user);
 *       $qr->manager->getRepository(Photo::class)->save($photo);
 *       $qr->commitTransaction();
 *   } catch (\Throwable $e) {
 *       $qr->rollbackTransaction();
 *       throw $e;
 *   } finally {
 *       $qr->release();
 *   }
 *
 * PHP runs one request at a time on one PDO connection per DataSource, so every master QueryRunner shares
 * that connection (TypeORM takes one from a pool): a transaction started while another one is active becomes
 * a SAVEPOINT. With replication, `createQueryRunner('slave')` gives a runner on one (random) replica. Worker mode: a runner never outlives its scope with a transaction open — when it is released,
 * or garbage-collected without release(), its open transaction levels are rolled back.
 */
final class QueryRunner
{
    /** The EntityManager whose operations run through this runner (TypeORM's queryRunner.manager). */
    public readonly EntityManager $manager;
    /** Free-form storage shared with subscribers during the runner's life (TypeORM's queryRunner.data). */
    public array $data = [];
    private int $depth = 0;
    private bool $released = false;
    /** The slave node of a 'slave' runner, chosen on first use and kept for the runner's life. */
    private ?Connection $node = null;

    /** @param 'master'|'slave' $mode replication mode (TypeORM's createQueryRunner(mode)) */
    public function __construct(private readonly DataSource $dataSource, ?EntityManager $manager = null, private readonly string $mode = 'master')
    {
        if (! in_array($mode, Replication::MODES, true)) {
            throw new OrmException("Replication mode must be \"master\" or \"slave\", got \"$mode\".");
        }
        $this->manager = $manager ?? new EntityManager($dataSource, $this);
    }

    /** 'master' | 'slave' (TypeORM's getReplicationMode()). */
    public function getReplicationMode(): string
    {
        return $this->mode;
    }

    /**
     * The connection this runner works on: the master, or for a 'slave' runner one slave picked at random on
     * first use (the master when the DataSource has no replication).
     */
    public function connection(): Connection
    {
        if ($this->mode === 'master') {
            return $this->dataSource->getConnection();
        }

        return $this->node ??= $this->dataSource->slaveConnection();
    }

    public function getDataSource(): DataSource
    {
        return $this->dataSource;
    }

    /** The connection (the DataSource's shared master, or this runner's slave); TypeORM's connect() acquires a pooled one. */
    public function connect(): Connection
    {
        $this->assertNotReleased();

        return $this->connection();
    }

    /** Start a transaction (a SAVEPOINT when this runner or another one already has one open). */
    public function startTransaction(?string $isolationLevel = null): void
    {
        $this->assertNotReleased();
        $connection = $this->connection();
        $this->dataSource->enterQueryRunner($this);
        try {
            $connection->beginTransaction($isolationLevel);
        } catch (\Throwable $e) {
            if ($this->depth === 0) {
                $this->dataSource->leaveQueryRunner($this);
            }
            throw $e;
        }
        $this->depth++;
    }

    public function commitTransaction(): void
    {
        $this->assertNotReleased();
        if ($this->depth === 0) {
            throw new TransactionNotStartedException;
        }
        try {
            $this->connection()->commit();
        } finally {
            $this->closeLevel();
        }
    }

    public function rollbackTransaction(): void
    {
        $this->assertNotReleased();
        if ($this->depth === 0) {
            throw new TransactionNotStartedException;
        }
        try {
            $this->connection()->rollBack();
        } finally {
            $this->closeLevel();
        }
    }

    public function isTransactionActive(): bool
    {
        return $this->depth > 0;
    }

    /** Open transaction levels of this runner (1 = transaction, more = savepoints). */
    public function transactionDepth(): int
    {
        return $this->depth;
    }

    public function isReleased(): bool
    {
        return $this->released;
    }

    /** Raw SQL with bound parameters: rows for statements returning columns, the affected row count otherwise. */
    public function query(string $sql, array $params = []): array|int
    {
        $this->assertNotReleased();
        $stmt = $this->connection()->query($sql, $params);

        return $stmt->columnCount() > 0 ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : $stmt->rowCount();
    }

    /** Roll back whatever this runner left open and make it unusable. Safe to call twice. */
    public function release(): void
    {
        if ($this->released) {
            return;
        }
        try {
            while ($this->depth > 0) {
                $this->rollbackTransaction();
            }
        } finally {
            $this->depth = 0;
            $this->released = true;
            $this->dataSource->leaveQueryRunner($this);
        }
    }

    public function __destruct()
    {
        if (! $this->released && $this->depth > 0) {
            try {
                $this->release();
            } catch (\Throwable) {
                // shutting down with a dead connection: nothing left to roll back
            }
        }
    }

    private function closeLevel(): void
    {
        $this->depth--;
        if ($this->depth === 0) {
            $this->dataSource->leaveQueryRunner($this);
        }
    }

    private function assertNotReleased(): void
    {
        if ($this->released) {
            throw new QueryRunnerAlreadyReleasedException;
        }
    }
}
