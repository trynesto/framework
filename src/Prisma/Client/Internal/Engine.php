<?php

declare(strict_types=1);

namespace Nesto\Prisma\Client\Internal;

use Closure;
use Nesto\Orm\Connection;
use Nesto\Prisma\Client\Errors\PrismaClientInitializationError;
use Nesto\Prisma\Dmmf\Document;

/**
 * The query engine of a PrismaClient (shared by its extended and transaction clients): the connection
 * (opened lazily, or on connect()), the data model, value conversion and statement execution with query
 * events. Reader / Writer compile Prisma queries on top of it. Holds no per-request state: a worker can keep
 * one engine for its whole life.
 *
 * @internal
 */
final class Engine
{
    public readonly DataModel $dataModel;
    private ?Connection $connection;
    private ?ValueCodec $codecInstance = null;
    private ?string $platformName = null;
    private bool $started = false;

    /** @var array<string, list<Closure(array<string, mixed>): void>> level (query, info, warn, error) => listeners */
    private array $listeners = [];

    /**
     * @param (Closure(): Connection)|null $connector opens the connection on first use
     * @param array<string, class-string<\BackedEnum>> $enumClasses
     */
    public function __construct(
        Document $document,
        ?Connection $connection,
        private ?Closure $connector,
        private readonly array $enumClasses = [],
    ) {
        $this->dataModel = new DataModel($document);
        $this->connection = $connection;
    }

    public function connection(): Connection
    {
        $this->start();
        if ($this->connection === null) {
            if ($this->connector === null) {
                throw new PrismaClientInitializationError('The client has no datasource URL. Set the datasource `url` (env("DATABASE_URL")) or pass `datasourceUrl`.');
            }
            try {
                $this->connection = ($this->connector)();
            } catch (\PDOException $e) {
                throw ErrorMapper::initialization($e);
            }
        }

        return $this->connection;
    }

    /**
     * Use another database from now on (test helpers): the current connection is closed, the next query opens
     * one through $connector. Every client sharing this engine (extended / transaction clients) follows.
     *
     * @param Closure(): Connection $connector
     */
    public function useConnector(Closure $connector): void
    {
        if ($this->connection !== null && $this->connection->inTransaction()) {
            throw new \Nesto\Prisma\PrismaException('The client cannot switch databases inside a transaction.');
        }
        $this->connector = $connector;
        $this->connection = null;
        $this->platformName = null;
        $this->codecInstance = null;
    }

    /** Prisma's `warn` log level: the schema's warnings (deprecated preview features, ...) once, when the client starts. */
    private function start(): void
    {
        if ($this->started) {
            return;
        }
        $this->started = true;
        foreach ($this->dataModel->document->warnings as $warning) {
            $this->warn($warning, 'nesto.prisma');
        }
    }

    /** Send a `warn` event (log level "warn" / $on('warn')). */
    public function warn(string $message, string $target): void
    {
        $this->start();
        if ($this->hasListeners('warn')) {
            $this->emitEvent('warn', ['timestamp' => new \DateTimeImmutable(), 'message' => $message, 'target' => $target]);
        }
    }

    public function isConnected(): bool
    {
        return $this->connection !== null;
    }

    /** Drop the connection (it is reopened on the next query when the engine owns a connector). */
    public function disconnect(): void
    {
        if ($this->connector !== null) {
            $this->connection = null;
        }
    }

    /** 'pgsql' | 'mysql' | 'mariadb' | 'sqlite' */
    public function platform(): string
    {
        return $this->platformName ??= $this->connection()->platform();
    }

    public function __get(string $name): mixed
    {
        return match ($name) {
            'platform' => $this->platform(),
            'codec' => $this->codecInstance ??= new ValueCodec($this->platform(), $this->dataModel->document, $this->enumClasses),
            default => throw new \LogicException("Unknown engine property $name"),
        };
    }

    public function quote(string $identifier): string
    {
        return $this->platform() === 'mysql' || $this->platform() === 'mariadb'
            ? '`' . str_replace('`', '``', $identifier) . '`'
            : '"' . str_replace('"', '""', $identifier) . '"';
    }

    public function model(string $name): ModelInfo
    {
        return $this->dataModel->model($name);
    }

    // ---- statements ------------------------------------------------------------------------------

    /** @param list<mixed> $params @return list<array<string, mixed>> */
    public function select(string $sql, array $params = []): array
    {
        $start = hrtime(true);
        $rows = $this->connection()->fetchAll($sql, $params);
        $this->emit($sql, $params, $start);

        return $rows;
    }

    /** @param list<mixed> $params */
    public function execute(string $sql, array $params = []): int
    {
        $start = hrtime(true);
        $count = $this->connection()->execute($sql, $params);
        $this->emit($sql, $params, $start);

        return $count;
    }

    /** Run $fn in a transaction (a savepoint when one is already open). */
    public function transaction(Closure $fn, ?string $isolationLevel = null): mixed
    {
        $connection = $this->connection();
        if ($isolationLevel !== null && $connection->inTransaction()) {
            $isolationLevel = null; // savepoints inherit the level
        }

        return $connection->transaction(fn () => $fn(), $isolationLevel);
    }

    /** @param Closure(array<string, mixed>): void $listener */
    public function on(string $level, Closure $listener): void
    {
        $this->listeners[$level][] = $listener;
    }

    public function hasListeners(string $level): bool
    {
        return ($this->listeners[$level] ?? []) !== [];
    }

    /** Send an event (`['message' => ..., 'target' => ..., 'timestamp' => ...]` for info / warn / error). @param array<string, mixed> $event */
    public function emitEvent(string $level, array $event): void
    {
        foreach ($this->listeners[$level] ?? [] as $listener) {
            $listener($event);
        }
    }

    /** @param list<mixed> $params */
    private function emit(string $sql, array $params, int $start): void
    {
        if (($this->listeners['query'] ?? []) === []) {
            return;
        }
        $event = [
            'timestamp' => new \DateTimeImmutable(),
            'query' => $sql,
            'params' => json_encode(array_map(fn ($p) => $p instanceof \Nesto\Orm\Types\Binary ? '0x' . bin2hex($p->bytes) : $p, $params), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            'duration' => round((hrtime(true) - $start) / 1e6, 3),
            'target' => 'nesto.prisma',
        ];
        $this->emitEvent('query', $event);
    }
}
