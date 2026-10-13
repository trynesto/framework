<?php

declare(strict_types=1);

namespace Nesto\Orm;

use Closure;

/**
 * The `replication` option of a DataSource (TypeORM's master / slaves setup): one Connection per node,
 * each opened on first use and each reconnecting on its own when its server drops it (worker mode).
 *
 *   'type' => 'mysql',
 *   'replication' => [
 *       'master' => ['host' => 'db-primary', 'username' => 'app', 'password' => '…', 'database' => 'app'],
 *       'slaves' => [
 *           ['host' => 'db-replica-1', 'username' => 'ro', 'password' => '…', 'database' => 'app'],
 *           ['url' => 'mysql://ro:…@db-replica-2/app'],
 *       ],
 *       'defaultMode' => 'slave',   // where reads go by default: 'slave' (TypeORM's default) | 'master'
 *   ]
 *
 * `primary` / `replicas` are accepted for `master` / `slaves`. A node inherits `type`, `charset` and the PDO
 * `options` of the DataSource. Reads (find*, count, QueryBuilder getMany / getOne / getRaw* / getCount /
 * stream) pick a random slave; writes, raw queries, schema changes and anything while a transaction is open
 * go to the master (see DataSource::readConnection()).
 */
final class Replication
{
    public const MODES = ['master', 'slave'];

    /** @var array<string, mixed> */
    private readonly array $master;
    /** @var list<array<string, mixed>> */
    private readonly array $slaves;
    /** @var array<int, Connection> opened slave connections by index */
    private array $open = [];
    /** Where reads go when nothing else decides: 'slave' | 'master'. */
    public readonly string $defaultMode;

    /** @param array<string, mixed> $options DataSource options with a `replication` key */
    public function __construct(array $options)
    {
        $replication = $options['replication'];
        if (! is_array($replication)) {
            throw new OrmException('The "replication" option must be an array with "master" and "slaves".');
        }
        $inherited = array_intersect_key($options, array_flip(['type', 'charset', 'options']));
        $master = $replication['master'] ?? $replication['primary'] ?? throw new OrmException('The "replication" option needs a "master" node.');
        $slaves = $replication['slaves'] ?? $replication['replicas'] ?? [];
        if (! is_array($master) || ! is_array($slaves) || ! array_is_list($slaves)) {
            throw new OrmException('replication.master must be connection options and replication.slaves a list of them.');
        }
        $this->master = $master + $inherited;
        $this->slaves = array_map(static fn (array $slave): array => $slave + $inherited, $slaves);
        $mode = $replication['defaultMode'] ?? 'slave';
        if (! in_array($mode, self::MODES, true)) {
            throw new OrmException("replication.defaultMode must be \"master\" or \"slave\", got \"$mode\".");
        }
        $this->defaultMode = $mode;
    }

    /** @param array<string, mixed> $options */
    public static function fromOptions(array $options): ?self
    {
        return empty($options['replication']) ? null : new self($options);
    }

    /** @return array<string, mixed> connection options of the master node */
    public function masterOptions(): array
    {
        return $this->master;
    }

    public function slaveCount(): int
    {
        return count($this->slaves);
    }

    /**
     * A slave connection (random when $index is null), opened on first use; $install wires it to the DataSource
     * (events, logger). Without slaves, null.
     *
     * @param Closure(Connection): void $install
     */
    public function slave(Closure $install, ?int $index = null): ?Connection
    {
        if ($this->slaves === []) {
            return null;
        }
        $index ??= random_int(0, count($this->slaves) - 1);
        if (! isset($this->slaves[$index])) {
            throw new OrmException("There is no slave #$index (" . count($this->slaves) . ' configured).');
        }
        if (! isset($this->open[$index])) {
            $connection = Connection::fromOptions($this->slaves[$index]);
            $install($connection);
            $this->open[$index] = $connection;
        }

        return $this->open[$index];
    }

    /** @return array<int, Connection> the slave connections opened so far */
    public function openSlaves(): array
    {
        return $this->open;
    }

    /** Forget the open slave connections (DataSource::destroy()). */
    public function close(): void
    {
        foreach ($this->open as $connection) {
            $connection->setTransactionListener(null)->setQueryListeners(null, null)->setOrmLogger(null);
        }
        $this->open = [];
    }
}
