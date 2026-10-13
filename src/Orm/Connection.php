<?php

declare(strict_types=1);

namespace Nesto\Orm;

use Closure;
use Nesto\Orm\Mapping\TypeConverter;
use PDO;
use PDOException;
use PDOStatement;

/**
 * Thin PDO wrapper: prepared statements only, driver-aware identifier quoting, nested transactions
 * through savepoints and an optional query log (handy in tests to assert "no N+1").
 *
 *   $conn = Connection::fromOptions(['type' => 'sqlite', 'database' => ':memory:']);
 *   $conn->execute('INSERT INTO users (name) VALUES (:name)', ['name' => 'Ada']);
 *   $rows = $conn->fetchAll('SELECT * FROM users WHERE id IN (:ids)', ['ids' => [1, 2]]); // arrays are expanded
 *   $conn->transaction(fn () => ...);  // nested calls use SAVEPOINTs
 *   $conn->transaction(fn () => ..., 'SERIALIZABLE');  // isolation level of the outermost transaction
 *   $conn->enableQueryLog(); ... $conn->getQueryLog(); // [['sql' => ..., 'params' => [...], 'time' => 0.001], ...]
 */
final class Connection
{
    private int $transactionDepth = 0;
    private bool $logging = false;
    /** @var list<array{sql: string, params: array<string, mixed>, time: float}> */
    private array $log = [];
    private ?Closure $logger = null;
    /** @var (Closure(string, int): void)|null transaction phase listener (subscribers' transaction events) */
    private ?Closure $transactionListener = null;
    /** @var (Closure(string, array): void)|null called before every statement (subscribers' beforeQuery) */
    private ?Closure $beforeQuery = null;
    /** @var (Closure(string, array, bool, float, mixed, ?\Throwable): void)|null called after every statement */
    private ?Closure $afterQuery = null;
    /** The DataSource's logger (TypeORM's Logger): queries, failed and slow queries, transaction statements. */
    private ?Logging\Logger $ormLogger = null;
    /** Milliseconds after which a statement is reported through Logger::logQuerySlow(). */
    private ?float $maxQueryExecutionTime = null;
    /** PDO driver name: 'sqlite' | 'mysql' | 'pgsql' (MariaDB uses the mysql PDO driver). */
    private readonly string $driver;
    /** Server flavour: 'sqlite' | 'mysql' | 'mariadb' | 'pgsql'; null until detected. */
    private ?string $platform;

    /** Messages PDO drivers use when the server dropped the connection (wait_timeout, restart, KILL, network). */
    private const LOST_CONNECTION = [
        'server has gone away', 'lost connection', 'no connection to the server', 'is dead or not enabled',
        'error while sending', 'server closed the connection unexpectedly', 'ssl connection has been closed unexpectedly',
        'error writing data to the connection', 'decryption failed or bad record mac', 'connection reset by peer',
        'broken pipe', 'packets out of order', 'physical connection is not usable', 'terminating connection due to administrator command',
        'connection was killed', 'the client was disconnected by the server', 'eof detected', 'connection is closed',
    ];

    /** SQLSTATEs meaning "the connection is gone" (08xxx = connection exception, 57P01-03 = server shutdown / admin kill). */
    private const LOST_CONNECTION_STATES = ['08S01', '08003', '08006', '57P01', '57P02', '57P03'];

    /** MySQL/MariaDB error codes for a dropped connection: 2006 gone away, 2013/2055 lost, 4031 idle disconnect, 1927 killed (MariaDB). */
    private const LOST_CONNECTION_CODES = [2006, 2013, 2055, 4031, 1927];

    /**
     * @param string $driver 'sqlite' | 'mysql' | 'mariadb' | 'pgsql' (aliases 'postgres', 'postgresql', 'sqlite3' accepted)
     * @param (Closure(): PDO)|null $reconnector opens a fresh PDO when the server dropped the connection;
     *        long-running workers (FrankenPHP) keep one connection for hours, so this matters there
     * @param ?string $platform 'mysql' or 'mariadb' for the mysql driver; detected from the server version when null
     */
    public function __construct(private PDO $pdo, string $driver, private readonly ?Closure $reconnector = null, ?string $platform = null)
    {
        [$this->driver, $hinted] = self::normalizeType($driver);
        $this->platform = $platform !== null ? self::normalizeType($platform)[1] : ($this->driver === 'mysql' && $hinted === 'mysql' ? null : $hinted);
        $this->configure();
    }

    private function configure(): void
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        if ($this->driver === 'sqlite') {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    /**
     * @return array{0: string, 1: string} [PDO driver, platform] for a configured type name
     */
    private static function normalizeType(string $type): array
    {
        return match (strtolower(trim($type))) {
            'postgres', 'postgresql', 'pgsql', 'pg' => ['pgsql', 'pgsql'],
            'mysql' => ['mysql', 'mysql'],
            'mariadb' => ['mysql', 'mariadb'],
            'sqlite', 'sqlite3' => ['sqlite', 'sqlite'],
            default => throw new OrmException("Unsupported database type \"$type\" (use sqlite, mysql, mariadb or postgres)."),
        };
    }

    /** Default TCP port per platform. */
    public static function defaultPort(string $type): ?int
    {
        return match (self::normalizeType($type)[0]) {
            'pgsql' => 5432,
            'mysql' => 3306,
            default => null,
        };
    }

    /**
     * Build a connection from ORM options.
     *
     *   ['type' => 'postgres', 'host' => 'db', 'port' => 5432, 'database' => 'app', 'username' => ..., 'password' => ...]
     *   ['type' => 'mariadb', 'host' => 'db', 'database' => 'app']                 // port defaults to 3306
     *   ['url' => 'postgres://user:secret@db:5432/app']                             // URL form (type taken from the scheme)
     *   ['url' => 'mysql://root@127.0.0.1:3306/app?charset=utf8mb4']
     *   ['type' => 'mysql', 'url' => 'mysql:host=127.0.0.1;dbname=app']             // raw PDO DSN
     *   ['type' => 'sqlite', 'database' => ':memory:']
     *
     * @param array{type?: string, database?: ?string, host?: ?string, port?: int|string|null, username?: ?string,
     *               password?: ?string, charset?: ?string, url?: ?string, options?: array<int, mixed>} $options
     */
    public static function fromOptions(array $options): self
    {
        $url = $options['url'] ?? null;
        $dsn = null;
        if (is_string($url) && $url !== '') {
            if (preg_match('#^([a-z][a-z0-9+.-]*)://#i', $url) === 1) {
                $options = self::parseUrl($url) + $options;
            } else {
                // raw PDO DSN ("pgsql:host=...;dbname=..."): its prefix names the PDO driver
                $dsn = $url;
                $prefix = strtolower((string) strstr($url, ':', true));
                $configured = self::normalizeType((string) ($options['type'] ?? $prefix));
                if (in_array($prefix, ['mysql', 'pgsql', 'sqlite'], true) && $configured[0] !== $prefix) {
                    $options['type'] = $prefix;
                }
            }
        }
        [$driver, $platform] = self::normalizeType((string) ($options['type'] ?? 'sqlite'));
        $port = isset($options['port']) && $options['port'] !== '' && (int) $options['port'] > 0 ? (int) $options['port'] : self::defaultPort($driver);
        $host = isset($options['host']) && $options['host'] !== '' ? (string) $options['host'] : '127.0.0.1';
        if ($dsn === null) {
            $dsn = match ($driver) {
                'sqlite' => 'sqlite:' . (($options['database'] ?? null) ?: ':memory:'),
                'mysql' => sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                    $host,
                    $port,
                    $options['database'] ?? '',
                    ($options['charset'] ?? null) ?: 'utf8mb4',
                ),
                'pgsql' => sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $options['database'] ?? ''),
            };
        }
        $pdoOptions = $options['options'] ?? [];
        if ($driver === 'mysql' && defined('PDO::MYSQL_ATTR_FOUND_ROWS') && ! array_key_exists(PDO::MYSQL_ATTR_FOUND_ROWS, $pdoOptions)) {
            // UPDATE reports matched rows (like SQLite/PostgreSQL), not only the rows whose values changed
            $pdoOptions[PDO::MYSQL_ATTR_FOUND_ROWS] = true;
        }
        $username = isset($options['username']) && $options['username'] !== '' ? (string) $options['username'] : null;
        $password = isset($options['password']) && $options['password'] !== '' ? (string) $options['password'] : null;
        $connect = static fn (): PDO => new PDO($dsn, $username, $password, $pdoOptions);

        return new self($connect(), $driver, $driver === 'sqlite' ? null : $connect, $platform === 'mysql' ? null : $platform);
    }

    /**
     * "postgres://user:pass@host:5432/db", "mariadb://root@127.0.0.1/app?charset=utf8mb4", "sqlite:///abs/path.db".
     *
     * @return array<string, mixed> options parsed from the URL
     */
    public static function parseUrl(string $url): array
    {
        $scheme = strtolower((string) strstr($url, '://', true));
        [, $type] = self::normalizeType($scheme);
        if ($type === 'sqlite') {
            $path = rawurldecode(substr($url, strlen($scheme) + 3));

            return ['type' => 'sqlite', 'database' => $path === '' ? ':memory:' : $path];
        }
        $parts = parse_url($url);
        if ($parts === false) {
            throw new OrmException('Invalid database URL.');
        }
        $options = ['type' => $type];
        if (isset($parts['host'])) {
            $options['host'] = rawurldecode($parts['host']);
        }
        if (isset($parts['port'])) {
            $options['port'] = (int) $parts['port'];
        }
        if (isset($parts['user'])) {
            $options['username'] = rawurldecode($parts['user']);
        }
        if (isset($parts['pass'])) {
            $options['password'] = rawurldecode($parts['pass']);
        }
        $database = ltrim(rawurldecode($parts['path'] ?? ''), '/');
        if ($database !== '') {
            $options['database'] = $database;
        }
        parse_str($parts['query'] ?? '', $query);
        if (isset($query['charset']) && is_string($query['charset'])) {
            $options['charset'] = $query['charset'];
        }

        return $options;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** PDO driver: 'sqlite' | 'mysql' | 'pgsql' (MariaDB reports 'mysql' here; see platform()). */
    public function driver(): string
    {
        return $this->driver;
    }

    /**
     * Database flavour: 'sqlite' | 'mysql' | 'mariadb' | 'pgsql'. For the mysql driver it comes from the
     * configured type ('mariadb') or, when configured as 'mysql', from the server version string.
     */
    public function platform(): string
    {
        if ($this->platform === null) {
            $version = $this->serverVersion();
            $this->platform = $version !== null && stripos($version, 'mariadb') !== false ? 'mariadb' : 'mysql';
        }

        return $this->platform;
    }

    public function isMariaDb(): bool
    {
        return $this->platform() === 'mariadb';
    }

    /** Server version string as reported by the driver ("8.4.0", "11.4.2-MariaDB-ubu2404", "16.2 (...)"), or null. */
    public function serverVersion(): ?string
    {
        // The handshake version needs no round trip; MariaDB reports e.g. "11.4.2-MariaDB-ubu2404"
        // (or "5.5.5-10.6.18-MariaDB" on 10.x), so it is enough to tell MariaDB from MySQL.
        try {
            $version = $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            if (is_string($version) && $version !== '') {
                return $version;
            }
        } catch (\Throwable) {
        }
        try {
            $version = $this->pdo->query('SELECT VERSION()')->fetchColumn();

            return is_string($version) ? $version : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Quote an identifier (table / column); "a.b" is quoted per part. */
    public function quote(string $identifier): string
    {
        if ($identifier === '*') {
            return $identifier;
        }
        $parts = array_map(fn (string $p) => $p === '*' ? '*' : $this->quotePart($p), explode('.', $identifier));

        return implode('.', $parts);
    }

    private function quotePart(string $part): string
    {
        return $this->driver === 'mysql'
            ? '`' . str_replace('`', '``', $part) . '`'
            : '"' . str_replace('"', '""', $part) . '"';
    }

    // ---- statements ------------------------------------------------------------------------------

    /** @param array<string|int, mixed> $params named (:name) or positional (?) */
    public function query(string $sql, array $params = []): PDOStatement
    {
        if ($this->beforeQuery !== null) {
            ($this->beforeQuery)($sql, $params);
        }
        [$sql, $params] = $this->expandArrays($sql, $params);
        $this->ormLogger?->logQuery($sql, $params);
        $start = microtime(true);
        try {
            $stmt = $this->run($sql, $params);
        } catch (PDOException $e) {
            if (! $this->canReconnect($e)) {
                throw $this->failed($sql, $params, $start, $e);
            }
            // The server dropped us between requests: reconnect and retry once. Never inside a
            // transaction, whose earlier statements died with the old connection.
            try {
                $this->reconnect();
                $stmt = $this->run($sql, $params);
            } catch (PDOException $retry) {
                throw $this->failed($sql, $params, $start, $retry);
            }
        }
        $this->record($sql, $params, $start);
        $this->reportSlow($sql, $params, $start);
        if ($this->afterQuery !== null) {
            ($this->afterQuery)($sql, $params, true, microtime(true) - $start, $stmt, null);
        }

        return $stmt;
    }

    /** @param array<string|int, mixed> $params */
    private function reportSlow(string $sql, array $params, float $start): void
    {
        if ($this->ormLogger !== null && $this->maxQueryExecutionTime !== null) {
            $elapsed = (microtime(true) - $start) * 1000;
            if ($elapsed > $this->maxQueryExecutionTime) {
                $this->ormLogger->logQuerySlow(round($elapsed, 3), $sql, $params);
            }
        }
    }

    /** @param array<string|int, mixed> $params */
    private function failed(string $sql, array $params, float $start, PDOException $e): QueryException
    {
        $this->record($sql, $params, $start);
        $this->ormLogger?->logQueryError($e->getMessage(), $sql, $params);
        $error = new QueryException($e->getMessage() . ' [SQL: ' . $sql . ']', $sql, $params, $e);
        if ($this->afterQuery !== null) {
            ($this->afterQuery)($sql, $params, false, microtime(true) - $start, null, $error);
        }

        return $error;
    }

    /** @param array<string|int, mixed> $params */
    private function run(string $sql, array $params, ?PDO $pdo = null): PDOStatement
    {
        // mysqlnd also raises an E_WARNING when the server has gone away; the exception carries the same message.
        $stmt = @($pdo ?? $this->pdo)->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(is_int($key) ? $key + 1 : $key, $this->normalizeValue($value), $this->paramType($value));
        }
        @$stmt->execute();

        return $stmt;
    }

    private function canReconnect(PDOException $e): bool
    {
        if ($this->reconnector === null || $this->transactionDepth > 0) {
            return false;
        }

        return self::isLostConnection($e);
    }

    /** Does this exception mean the server dropped the connection (vs. an ordinary SQL error)? */
    public static function isLostConnection(PDOException $e): bool
    {
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());
        $code = (int) ($e->errorInfo[1] ?? 0);
        if (in_array($state, self::LOST_CONNECTION_STATES, true) || in_array($code, self::LOST_CONNECTION_CODES, true)) {
            return true;
        }
        $message = strtolower($e->getMessage());
        foreach (self::LOST_CONNECTION as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** Replace the PDO handle with a fresh connection. */
    public function reconnect(): void
    {
        if ($this->reconnector === null) {
            throw new OrmException('This connection cannot reconnect (it was created from an existing PDO).');
        }
        $this->pdo = ($this->reconnector)();
        $this->configure();
        $this->transactionDepth = 0;
    }

    /** Execute a write statement and return the affected row count. */
    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    /** @return list<array<string, mixed>> */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * The rows of a SELECT one by one, without loading the whole result into PHP memory (QueryBuilder::stream()):
     *
     *   - SQLite: rows are read from the statement as they are produced;
     *   - MySQL / MariaDB: an unbuffered query on a dedicated connection to the same server, closed when the
     *     generator ends — the main connection stays free for other queries while you iterate. Inside a
     *     transaction (whose rows only its connection sees) the query is buffered on the main connection;
     *   - PostgreSQL: a server-side cursor read $batchSize rows at a time — inside the current transaction, or
     *     outside one in a transaction of a dedicated connection.
     *
     * Leaving the loop early (break, exception) closes the cursor / dedicated connection.
     *
     * @param array<string|int, mixed> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function cursor(string $sql, array $params = [], int $batchSize = 1000): \Generator
    {
        if ($this->beforeQuery !== null) {
            ($this->beforeQuery)($sql, $params);
        }
        [$sql, $params] = $this->expandArrays($sql, $params);
        $this->ormLogger?->logQuery($sql, $params);
        $start = microtime(true);
        $dedicated = $this->driver !== 'sqlite' && $this->transactionDepth === 0 && $this->reconnector !== null;
        $pdo = $this->pdo;
        $cursor = null;
        $stmt = null;
        try {
            try {
                if ($dedicated) {
                    $pdo = ($this->reconnector)();
                    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    if ($this->driver === 'mysql') {
                        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
                    } else {
                        $pdo->beginTransaction();
                    }
                }
                if ($this->driver === 'pgsql') {
                    $cursor = 'nesto_cursor_' . bin2hex(random_bytes(6));
                    $this->run('DECLARE ' . $cursor . ' NO SCROLL CURSOR FOR ' . $sql, $params, $pdo);
                } else {
                    $stmt = $this->run($sql, $params, $pdo);
                }
            } catch (PDOException $e) {
                throw $this->failed($sql, $params, $start, $e);
            }
            $this->record($sql, $params, $start);
            $this->reportSlow($sql, $params, $start);
            if ($this->afterQuery !== null) {
                ($this->afterQuery)($sql, $params, true, microtime(true) - $start, $stmt, null);
            }
            if ($stmt !== null) {
                while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                    yield $row;
                }

                return;
            }
            $fetch = 'FETCH FORWARD ' . max(1, $batchSize) . ' FROM ' . $cursor;
            do {
                $rows = $pdo->query($fetch)->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    yield $row;
                }
            } while (count($rows) >= $batchSize);
        } finally {
            try {
                $stmt?->closeCursor();
                if ($cursor !== null && $pdo->inTransaction()) {
                    $pdo->exec('CLOSE ' . $cursor);
                }
                if ($dedicated && $this->driver === 'pgsql' && $pdo->inTransaction()) {
                    $pdo->commit();
                }
            } catch (\Throwable) {
                // the cursor dies with its transaction / connection anyway
            }
            $stmt = null;
            $pdo = null;
        }
    }

    public function fetchColumn(string $sql, array $params = []): mixed
    {
        $value = $this->query($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    public function lastInsertId(?string $sequence = null): string|int
    {
        $id = $this->pdo->lastInsertId($sequence);

        return is_numeric($id) ? (int) $id : (string) $id;
    }

    // ---- transactions ----------------------------------------------------------------------------

    /** Isolation levels accepted by beginTransaction() / transaction() (TypeORM's IsolationLevel). */
    public const ISOLATION_LEVELS = ['READ UNCOMMITTED', 'READ COMMITTED', 'REPEATABLE READ', 'SERIALIZABLE'];

    /**
     * Start a transaction, or a SAVEPOINT when one is already active. The isolation level applies to the
     * outermost transaction only (a savepoint inherits it). SQLite supports READ UNCOMMITTED and
     * SERIALIZABLE (its default) only, like TypeORM.
     */
    public function beginTransaction(?string $isolationLevel = null): void
    {
        $level = $isolationLevel === null ? null : self::isolationLevel($isolationLevel, $this->driver);
        $this->notifyTransaction('beforeTransactionStart');
        if ($this->transactionDepth === 0) {
            try {
                $this->startTransaction($level);
            } catch (PDOException $e) {
                if (! $this->canReconnect($e)) {
                    throw $e;
                }
                $this->reconnect();
                $this->startTransaction($level);
            }
        } else {
            $this->exec('SAVEPOINT nesto_sp_' . $this->transactionDepth);
        }
        $this->transactionDepth++;
        $this->notifyTransaction('afterTransactionStart');
    }

    private function startTransaction(?string $level): void
    {
        if ($level !== null && $this->driver === 'mysql') {
            @$this->pdo->exec('SET TRANSACTION ISOLATION LEVEL ' . $level); // applies to the next transaction only
        } elseif ($level !== null && $this->driver === 'sqlite') {
            $this->pdo->exec('PRAGMA read_uncommitted = ' . ($level === 'READ UNCOMMITTED' ? '1' : '0'));
        }
        $this->ormLogger?->logQuery('START TRANSACTION');
        @$this->pdo->beginTransaction();
        if ($level !== null && $this->driver === 'pgsql') {
            $this->exec('SET TRANSACTION ISOLATION LEVEL ' . $level); // must precede any other statement
        }
    }

    /** Normalized isolation level ("read committed" → "READ COMMITTED"); throws for unknown / unsupported ones. */
    public static function isolationLevel(string $level, string $driver): string
    {
        $normalized = strtoupper(trim((string) preg_replace('/[\s_]+/', ' ', $level)));
        if (! in_array($normalized, self::ISOLATION_LEVELS, true)) {
            throw new OrmException("Unknown isolation level \"$level\" (expected one of " . implode(', ', self::ISOLATION_LEVELS) . ').');
        }
        if ($driver === 'sqlite' && ! in_array($normalized, ['READ UNCOMMITTED', 'SERIALIZABLE'], true)) {
            throw new OrmException('SQLite only supports the SERIALIZABLE and READ UNCOMMITTED isolation levels.');
        }

        return $normalized;
    }

    public function commit(): void
    {
        if ($this->transactionDepth === 0) {
            throw new OrmException('No active transaction to commit.');
        }
        $this->notifyTransaction('beforeTransactionCommit');
        $this->transactionDepth--;
        if ($this->transactionDepth === 0) {
            // MySQL/MariaDB commit implicitly on DDL; PDO then has no transaction left to commit
            if ($this->pdo->inTransaction()) {
                $this->ormLogger?->logQuery('COMMIT');
                $this->pdo->commit();
            }
        } else {
            $this->exec('RELEASE SAVEPOINT nesto_sp_' . $this->transactionDepth);
        }
        $this->notifyTransaction('afterTransactionCommit');
    }

    public function rollBack(): void
    {
        if ($this->transactionDepth === 0) {
            throw new OrmException('No active transaction to roll back.');
        }
        $this->notifyTransaction('beforeTransactionRollback');
        $this->transactionDepth--;
        if ($this->transactionDepth === 0) {
            if ($this->pdo->inTransaction()) {
                $this->ormLogger?->logQuery('ROLLBACK');
                $this->pdo->rollBack();
            }
        } else {
            $this->exec('ROLLBACK TO SAVEPOINT nesto_sp_' . $this->transactionDepth);
        }
        $this->notifyTransaction('afterTransactionRollback');
    }

    /**
     * Receives (phase, depth) around every transaction / savepoint operation. The phase is the subscriber
     * method name: beforeTransactionStart, afterTransactionStart, before/afterTransactionCommit,
     * before/afterTransactionRollback.
     *
     * @param (Closure(string, int): void)|null $listener
     */
    public function setTransactionListener(?Closure $listener): self
    {
        $this->transactionListener = $listener;

        return $this;
    }

    /**
     * Hooks around every statement run through query() / execute() / fetch*(): $before(sql, params) and
     * $after(sql, params, success, seconds, PDOStatement|null, QueryException|null).
     */
    public function setQueryListeners(?Closure $before, ?Closure $after): self
    {
        $this->beforeQuery = $before;
        $this->afterQuery = $after;

        return $this;
    }

    /** A transaction-control statement, run directly on PDO (outside the query log) but reported to the logger. */
    private function exec(string $sql): void
    {
        $this->ormLogger?->logQuery($sql);
        $this->pdo->exec($sql);
    }

    private function notifyTransaction(string $phase): void
    {
        if ($this->transactionListener !== null) {
            ($this->transactionListener)($phase, $this->transactionDepth);
        }
    }

    public function inTransaction(): bool
    {
        return $this->transactionDepth > 0;
    }

    public function transactionDepth(): int
    {
        return $this->transactionDepth;
    }

    /** Run $fn inside a (possibly nested) transaction; rolls back and rethrows on any Throwable. */
    public function transaction(Closure $fn, ?string $isolationLevel = null): mixed
    {
        $this->beginTransaction($isolationLevel);
        try {
            $result = $fn($this);
            $this->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->rollBack();
            throw $e;
        }
    }

    // ---- logging ----------------------------------------------------------------------------------

    public function enableQueryLog(bool $enabled = true): self
    {
        $this->logging = $enabled;

        return $this;
    }

    public function disableQueryLog(): self
    {
        $this->logging = false;

        return $this;
    }

    /** @return list<array{sql: string, params: array<string, mixed>, time: float}> */
    public function getQueryLog(): array
    {
        return $this->log;
    }

    public function flushQueryLog(): self
    {
        $this->log = [];

        return $this;
    }

    public function queryCount(): int
    {
        return count($this->log);
    }

    /**
     * The DataSource's Logger (TypeORM's `logger` option): every statement goes to logQuery() before it runs,
     * failures to logQueryError(), statements slower than $maxQueryExecutionTime ms to logQuerySlow().
     */
    public function setOrmLogger(?Logging\Logger $logger, ?float $maxQueryExecutionTime = null): self
    {
        $this->ormLogger = $logger instanceof Logging\NullLogger ? null : $logger;
        $this->maxQueryExecutionTime = $maxQueryExecutionTime;

        return $this;
    }

    public function getOrmLogger(): ?Logging\Logger
    {
        return $this->ormLogger;
    }

    /** Callback receiving (sql, params, seconds) for every statement — the `logging` option given as a Closure. */
    public function setLogger(?Closure $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    // ---- internals --------------------------------------------------------------------------------

    private function record(string $sql, array $params, float $start): void
    {
        $time = microtime(true) - $start;
        if ($this->logging) {
            $this->log[] = ['sql' => $sql, 'params' => $params, 'time' => $time];
        }
        if ($this->logger !== null) {
            ($this->logger)($sql, $params, $time);
        }
    }

    /** Expand `:ids` bound to an array into `:ids_0, :ids_1, ...`. */
    private function expandArrays(string $sql, array $params): array
    {
        $out = [];
        foreach ($params as $key => $value) {
            if (is_array($value) && is_string($key)) {
                $name = ltrim($key, ':');
                if ($value === []) {
                    $sql = (string) preg_replace('/:' . preg_quote($name, '/') . '\b/', 'NULL', $sql);
                    continue;
                }
                $placeholders = [];
                foreach (array_values($value) as $i => $item) {
                    $placeholders[] = ':' . $name . '_' . $i;
                    $out[$name . '_' . $i] = $item;
                }
                $sql = (string) preg_replace('/:' . preg_quote($name, '/') . '\b/', implode(', ', $placeholders), $sql);
                continue;
            }
            $out[is_string($key) ? ltrim($key, ':') : $key] = $value;
        }

        return [$sql, $out];
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof Types\Binary) {
            return $value->bytes;
        }
        $value = TypeConverter::toParameter($value);
        if (is_bool($value)) {
            return $this->driver === 'pgsql' ? $value : (int) $value;
        }

        return $value;
    }

    private function paramType(mixed $value): int
    {
        return match (true) {
            $value === null => PDO::PARAM_NULL,
            $value instanceof Types\Binary => PDO::PARAM_LOB,
            is_bool($value) => $this->driver === 'pgsql' ? PDO::PARAM_BOOL : PDO::PARAM_INT,
            is_int($value) => PDO::PARAM_INT,
            default => PDO::PARAM_STR,
        };
    }
}
