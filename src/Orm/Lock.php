<?php

declare(strict_types=1);

namespace Nesto\Orm;

/**
 * A lock requested through the `lock` find option or QueryBuilder::setLock() / setOnLocked() (TypeORM's lock
 * modes), and its SQL per platform:
 *
 *   mode                         PostgreSQL                      MySQL 8+ / MariaDB
 *   optimistic                   – (version check after findOne)  –
 *   pessimistic_read             FOR SHARE [OF …] [onLocked]     FOR SHARE [onLocked] / LOCK IN SHARE MODE
 *   pessimistic_write            FOR UPDATE [OF …] [onLocked]    FOR UPDATE [onLocked]
 *   pessimistic_partial_write    FOR UPDATE [OF …] SKIP LOCKED   FOR UPDATE SKIP LOCKED
 *   pessimistic_write_or_fail    FOR UPDATE [OF …] NOWAIT        FOR UPDATE NOWAIT
 *   for_no_key_update            FOR NO KEY UPDATE [OF …]        not supported
 *   for_key_share                FOR KEY SHARE [OF …]            not supported
 *   dirty_read                   –                               –
 *
 * `onLocked`: 'nowait' | 'skip_locked'; `tables` (PostgreSQL only): aliases to lock (FOR UPDATE OF …).
 * SQLite has no row locks: pessimistic modes throw LockNotSupportedOnGivenDriverException, as in TypeORM.
 * Pessimistic modes need an open transaction (PessimisticLockTransactionRequiredException).
 */
final class Lock
{
    public const MODES = [
        'optimistic', 'pessimistic_read', 'pessimistic_write', 'dirty_read', 'pessimistic_partial_write',
        'pessimistic_write_or_fail', 'for_no_key_update', 'for_key_share',
    ];

    /** @param list<string>|null $tables */
    public function __construct(
        public readonly string $mode,
        public readonly int|\DateTimeInterface|null $version = null,
        public readonly ?array $tables = null,
        public readonly ?string $onLocked = null,
    ) {
        if (! in_array($mode, self::MODES, true)) {
            throw new OrmException("Unknown lock mode \"$mode\" (expected one of " . implode(', ', self::MODES) . ').');
        }
        if ($mode === 'optimistic' && $version === null) {
            throw new OrmException('An optimistic lock needs a version: an integer (#[VersionColumn]) or a date (#[UpdateDateColumn]).');
        }
        if ($onLocked !== null && ! in_array($onLocked, ['nowait', 'skip_locked'], true)) {
            throw new OrmException("onLocked must be \"nowait\" or \"skip_locked\", got \"$onLocked\".");
        }
        if ($tables === []) {
            throw new OrmException('Lock tables cannot be an empty list.');
        }
    }

    /** `lock` find option: ['mode' => 'pessimistic_write', 'tables' => [...], 'onLocked' => 'nowait'] / ['mode' => 'optimistic', 'version' => 3]. */
    public static function fromOption(mixed $option): self
    {
        if (! is_array($option) || ! isset($option['mode']) || ! is_string($option['mode'])) {
            throw new OrmException("The lock find option must be an array with a \"mode\", e.g. ['mode' => 'pessimistic_write'].");
        }
        $unknown = array_diff(array_keys($option), ['mode', 'version', 'tables', 'onLocked']);
        if ($unknown !== []) {
            throw new OrmException('Unknown lock option "' . implode('", "', $unknown) . '" (expected mode, version, tables, onLocked).');
        }

        return new self($option['mode'], $option['version'] ?? null, isset($option['tables']) ? array_values((array) $option['tables']) : null, $option['onLocked'] ?? null);
    }

    public function withOnLocked(?string $onLocked): self
    {
        return new self($this->mode, $this->version, $this->tables, $onLocked);
    }

    /** Row-lock modes: they need a transaction. */
    public function isPessimistic(): bool
    {
        return ! in_array($this->mode, ['optimistic', 'dirty_read'], true);
    }

    /**
     * The locking clause appended to the SELECT (with a leading space), '' when the mode adds none.
     *
     * @param \Closure(string): string $quoteAlias quotes / resolves a lock table name
     */
    public function sql(Connection $connection, \Closure $quoteAlias): string
    {
        if (! $this->isPessimistic()) {
            return '';
        }
        $platform = $connection->platform();
        if ($platform === 'sqlite') {
            throw new LockNotSupportedOnGivenDriverException("SQLite has no row locks: the \"$this->mode\" lock is not supported (run the query in a transaction instead).");
        }
        $postgres = $platform === 'pgsql';
        if ($this->tables !== null && ! $postgres) {
            throw new LockNotSupportedOnGivenDriverException('Lock tables are only supported on PostgreSQL.');
        }
        $of = $this->tables !== null ? ' OF ' . implode(', ', array_map($quoteAlias, $this->tables)) : '';
        $onLocked = match ($this->onLocked) {
            'nowait' => ' NOWAIT',
            'skip_locked' => ' SKIP LOCKED',
            default => '',
        };

        return match ($this->mode) {
            'pessimistic_read' => match (true) {
                $postgres => ' FOR SHARE' . $of . $onLocked,
                $platform === 'mysql' && version_compare(self::numericVersion($connection), '8.0.0', '>=') => ' FOR SHARE' . $onLocked,
                default => ' LOCK IN SHARE MODE',
            },
            'pessimistic_write' => ' FOR UPDATE' . $of . $onLocked,
            'pessimistic_partial_write' => ' FOR UPDATE' . $of . ' SKIP LOCKED',
            'pessimistic_write_or_fail' => ' FOR UPDATE' . $of . ' NOWAIT',
            'for_no_key_update', 'for_key_share' => $postgres
                ? ($this->mode === 'for_no_key_update' ? ' FOR NO KEY UPDATE' : ' FOR KEY SHARE') . $of . $onLocked
                : throw new LockNotSupportedOnGivenDriverException("The \"$this->mode\" lock is only supported on PostgreSQL."),
        };
    }

    private static function numericVersion(Connection $connection): string
    {
        preg_match('/\d+(\.\d+)*/', (string) $connection->serverVersion(), $m);

        return $m[0] ?? '0';
    }
}
