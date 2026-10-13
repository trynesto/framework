<?php

declare(strict_types=1);

namespace Nesto\Prisma\Client;

use Closure;
use Nesto\Orm\Connection;
use Nesto\Prisma\Client\Errors\PrismaClientInitializationError;
use Nesto\Prisma\Client\Errors\PrismaClientKnownRequestError;
use Nesto\Prisma\Client\Errors\PrismaClientValidationError;
use Nesto\Prisma\Client\Internal\Engine;
use Nesto\Prisma\Client\Internal\ErrorMapper;
use Nesto\Prisma\Client\Internal\ModelInfo;
use Nesto\Prisma\Client\Internal\Reader;
use Nesto\Prisma\Client\Internal\Writer;
use Nesto\Prisma\Dmmf\DmmfCache;
use Nesto\Prisma\Dmmf\Document;
use Nesto\Prisma\Migrate\DatabaseUrl;
use Nesto\Prisma\PrismaException;
use Nesto\Prisma\Schema\SchemaLoader;

/**
 * Prisma Client for PHP. Prisma's `$`-methods are plain methods here (PHP keeps methods and properties
 * apart, so they cannot clash with model properties): connect(), disconnect(), transaction(), queryRaw(),
 * executeRaw(), queryRawUnsafe(), executeRawUnsafe(), on(), extends(). The `$`-names work too:
 * `$prisma->{'$transaction'}(...)`.
 *
 *   $prisma = new PrismaClient(['schema' => 'prisma/schema.prisma']);   // or the generated App\Prisma\PrismaClient
 *   $prisma->user->findMany(['where' => ['email' => ['endsWith' => '@prisma.io']]]);
 *   $prisma->transaction(fn (PrismaClient $tx) => $tx->user->update(...), ['isolationLevel' => Prisma::Serializable]);
 *   $prisma->transaction([fn ($tx) => $tx->user->create(...), fn ($tx) => $tx->post->deleteMany()]);
 *   $prisma->queryRaw(Prisma::sql('SELECT * FROM "User" WHERE id = ', $id));
 *   $prisma->queryRaw('SELECT * FROM "User" WHERE id = ?', [$id]);
 *
 * Options: `schema` (schema.prisma path) or `document` (Dmmf\Document), `cacheFile` (DmmfCache file),
 * `datasourceUrl` / `datasources` (['db' => ['url' => ...]]), `connection` (an Orm\Connection),
 * `log` (['query', 'info', 'warn', 'error'] or [['emit' => 'event'|'stdout', 'level' => 'query'], ...]),
 * `transactionOptions` (['isolationLevel' => ..., 'timeout' => 5000, 'maxWait' => 2000]), `omit` (global omit:
 * ['user' => ['password' => true]] — a query's `omit: { password: false }` brings it back), `schemaDir` (for
 * relative SQLite paths and .env), `enums` (enum name => generated PHP enum class).
 *
 * Worker-safe: the client keeps the connection and the compiled schema, nothing per request.
 */
class PrismaClient
{
    private Engine $engine;
    private Reader $reader;
    private Writer $writer;
    /** @var array<string, mixed> */
    private array $options = [];
    /** @var list<array<string, mixed>> */
    private array $extensions = [];
    /** @var array<string, ModelDelegate> */
    private array $delegates = [];
    private bool $inTransaction = false;
    private bool $transactionClosed = false;
    private ?float $transactionDeadline = null;
    private ?int $transactionTimeout = null;
    private ?string $schemaDir = null;

    /** @param array<string, mixed> $options */
    public function __construct(array $options = [])
    {
        $this->options = $options;
        $this->engine = $this->createEngine($options);
        $this->reader = new Reader($this->engine, [], (array) ($options['omit'] ?? []));
        $this->writer = new Writer($this->reader);
        $this->setUpLogging($options['log'] ?? []);
        $this->bootDelegates();
    }

    /** Typed model properties of the generated client are created here. */
    protected function bootDelegates(): void
    {
    }

    /** The compiled schema embedded by the generator (null: from the `schema` / `document` option). */
    protected static function embeddedDocument(): ?Document
    {
        return null;
    }

    /** @return array<string, class-string<\BackedEnum>> enum name => generated PHP enum */
    protected static function enumClasses(): array
    {
        return [];
    }

    /** Directory of schema.prisma when the client was generated (relative SQLite paths, .env). */
    protected static function generatedSchemaDir(): ?string
    {
        return null;
    }

    /** @param array<string, mixed> $options */
    private function createEngine(array $options): Engine
    {
        $schemaDir = $options['schemaDir'] ?? static::generatedSchemaDir();
        $document = $options['document'] ?? null;
        if ($document === null && isset($options['schema'])) {
            $schema = (string) $options['schema'];
            $schemaDir ??= dirname($schema);
            try {
                $document = isset($options['cacheFile'])
                    ? DmmfCache::load($schema, (string) $options['cacheFile'], (bool) ($options['checkSchema'] ?? true))
                    : SchemaLoader::loadFile($schema);
            } catch (PrismaException $e) {
                throw new PrismaClientInitializationError($e->getMessage(), null, $e);
            }
        }
        $document ??= static::embeddedDocument();
        if (! $document instanceof Document) {
            throw new PrismaClientInitializationError('The Prisma client needs a schema: pass `schema` (path to schema.prisma) or use the generated client (`php nesto prisma:generate`).');
        }
        $this->schemaDir = $schemaDir !== null ? (string) $schemaDir : null;
        $connection = $options['connection'] ?? null;
        $connector = null;
        if (! $connection instanceof Connection) {
            $connection = null;
            $datasource = $document->datasource;
            $url = $options['datasourceUrl'] ?? ($datasource !== null ? ($options['datasources'][$datasource->name]['url'] ?? null) : null);
            $connector = $this->connector($document, $url !== null ? (string) $url : null);
        }
        $enums = ($options['enums'] ?? []) + static::enumClasses();

        return new Engine($document, $connection, $connector, $enums);
    }

    /** Opens the connection on first use: $url, else the datasource URL of the schema (env var, .env). */
    private function connector(Document $document, ?string $url): Closure
    {
        $datasource = $document->datasource;
        $provider = $datasource?->provider;
        $schemaDir = $this->schemaDir;

        return function () use ($url, $datasource, $provider, $schemaDir): Connection {
            $resolved = $url ?? $datasource?->url->resolve();
            if ($resolved === null && $schemaDir !== null) {
                \Nesto\Config\Env::load([dirname($schemaDir) . '/.env', $schemaDir . '/.env']);
                $resolved = $datasource?->url->resolve();
            }
            if ($resolved === null || $provider === null) {
                throw new PrismaClientInitializationError($datasource?->url->fromEnvVar !== null
                    ? sprintf('error: Environment variable not found: %s.', $datasource->url->fromEnvVar)
                    : 'The datasource has no URL.');
            }

            $connectionOptions = (new DatabaseUrl($resolved, $provider, $schemaDir))->options();
            if ($provider === 'mysql') {
                // DateTimes are UTC (like Prisma): TIMESTAMP columns and NOW() must not shift with the server zone;
                // an init command also runs when the connection reconnects
                $initCommand = defined('Pdo\\Mysql::ATTR_INIT_COMMAND') ? constant('Pdo\\Mysql::ATTR_INIT_COMMAND') : constant('PDO::MYSQL_ATTR_INIT_COMMAND');
                $connectionOptions['options'][$initCommand] = "SET time_zone = '+00:00'";
            }

            return Connection::fromOptions($connectionOptions);
        };
    }

    /**
     * Point this client at another database (what `datasourceUrl` does at construction): the open connection is
     * closed and the next query connects to $url. Relative SQLite paths are resolved against the schema
     * folder. Used by the test helpers (Testing\PrismaTestDatabase) to move an app's PrismaService to the test
     * database; it affects every client sharing this one's engine (extends(), transaction clients).
     */
    public function useDatasourceUrl(string $url): void
    {
        $provider = $this->dmmf()->requireDatasource()->provider;
        try {
            new DatabaseUrl($url, $provider, $this->schemaDir);
        } catch (PrismaException $e) {
            throw new PrismaClientInitializationError($e->getMessage(), null, $e);
        }
        $this->engine->useConnector($this->connector($this->dmmf(), $url));
    }

    /** @param list<string|array{emit?: string, level: string}> $log */
    private function setUpLogging(array $log): void
    {
        foreach ($log as $entry) {
            $level = is_array($entry) ? (string) ($entry['level'] ?? '') : (string) $entry;
            $emit = is_array($entry) ? (string) ($entry['emit'] ?? 'stdout') : 'stdout';
            if ($emit !== 'stdout' || $level === '') {
                continue;
            }
            $stream = $this->options['logStream'] ?? null;
            $this->engine->on($level, function (array $event) use ($level, $stream): void {
                $line = 'prisma:' . $level . ' ' . ($level === 'query' ? $event['query'] : ($event['message'] ?? '')) . "\n";
                if (is_resource($stream)) {
                    fwrite($stream, $line);
                } else {
                    file_put_contents('php://stdout', $line);
                }
            });
        }
    }

    // ---- models ----------------------------------------------------------------------------------

    /** `$prisma->user` — the delegate of a model by its lowerCamel name. */
    public function __get(string $name): ModelDelegate
    {
        return $this->model($name);
    }

    public function __isset(string $name): bool
    {
        return $this->engine->dataModel->modelOfDelegate($name) !== null;
    }

    /** A model delegate by delegate name ("user") or model name ("User"). */
    public function model(string $name): ModelDelegate
    {
        if (isset($this->delegates[$name])) {
            return $this->delegates[$name];
        }
        $model = $this->engine->dataModel->modelOfDelegate($name) ?? ($this->engine->dataModel->has($name) ? $name : null);
        if ($model === null) {
            throw new PrismaClientValidationError(sprintf('Unknown model `%s`.', $name));
        }

        return $this->delegates[$name] = $this->createDelegate($model);
    }

    /** The delegate object of a model (the generated client returns its typed subclasses). */
    protected function createDelegate(string $model): ModelDelegate
    {
        return new ModelDelegate($this, $model);
    }

    /**
     * Run one model operation: query extensions, then the engine; database errors become Prisma errors.
     *
     * @internal used by ModelDelegate
     * @param array<string, mixed> $args
     */
    public function dispatch(string $model, string $operation, array $args): mixed
    {
        $this->assertTransactionUsable();
        $info = $this->engine->model($model);
        $run = fn (array $args): mixed => $this->execute($info, $operation, $args);
        foreach ($this->extensions as $extension) {
            $hooks = $this->queryHooks($extension['query'] ?? [], $info, $operation);
            foreach ($hooks as $hook) {
                $next = $run;
                $run = fn (array $args): mixed => self::invoke($hook, [
                    'model' => $model,
                    'operation' => $operation,
                    'args' => $args,
                    'query' => fn (?array $nextArgs = null) => $next($nextArgs ?? $args),
                ]);
            }
        }

        return $run($args);
    }

    /** @param array<string, mixed> $query @return list<Closure> innermost first */
    private function queryHooks(array $query, ModelInfo $info, string $operation): array
    {
        $hooks = [];
        foreach ([$info->delegateName(), $info->name, '$allModels'] as $key) {
            $component = $query[$key] ?? null;
            if (! is_array($component)) {
                continue;
            }
            foreach ([$operation, '$allOperations'] as $op) {
                if (($component[$op] ?? null) instanceof Closure) {
                    $hooks[] = $component[$op];
                }
            }
        }
        if (($query['$allOperations'] ?? null) instanceof Closure) {
            $hooks[] = $query['$allOperations'];
        }

        return $hooks;
    }

    /** @param array<string, mixed> $args */
    private function execute(ModelInfo $info, string $operation, array $args): mixed
    {
        try {
            if (array_key_exists('relationLoadStrategy', $args) && str_starts_with($operation, 'find')) {
                $this->relationLoadStrategy($args['relationLoadStrategy'], sprintf('prisma.%s.%s', $info->delegateName(), $operation));
            }
            return match ($operation) {
                'findUnique' => $this->reader->findUnique($info, $args),
                'findUniqueOrThrow' => $this->reader->findUnique($info, $args) ?? throw $this->notFound($info),
                'findFirst' => $this->reader->findFirst($info, $args),
                'findFirstOrThrow' => $this->reader->findFirst($info, $args) ?? throw $this->notFound($info),
                'findMany' => $this->reader->findMany($info, $args),
                'create' => $this->writer->create($info, $args),
                'createMany' => ['count' => $this->writer->createMany($info, $args)],
                'createManyAndReturn' => $this->writer->createManyAndReturn($info, $args),
                'update' => $this->writer->update($info, $args),
                'updateMany' => ['count' => $this->writer->updateMany($info, $args)],
                'updateManyAndReturn' => $this->writer->updateManyAndReturn($info, $args),
                'upsert' => $this->writer->upsert($info, $args),
                'delete' => $this->writer->delete($info, $args),
                'deleteMany' => ['count' => $this->writer->deleteMany($info, $args)],
                'count' => $this->reader->count($info, $args),
                'aggregate' => $this->reader->aggregate($info, $args),
                'groupBy' => $this->reader->groupBy($info, $args),
                default => throw new PrismaClientValidationError(sprintf('Unknown operation `%s`.', $operation)),
            };
        } catch (\Throwable $e) {
            $mapped = ErrorMapper::map($e, $this->engine->dataModel, $info->name, $operation, $args);
            if ($this->engine->hasListeners('error')) {
                $this->engine->emitEvent('error', ['timestamp' => new \DateTimeImmutable(), 'message' => $mapped->getMessage(), 'target' => sprintf('%s.%s', $info->delegateName(), $operation)]);
            }
            throw $mapped;
        }
    }

    /** `relationLoadStrategy`: "query" (what Nesto does) or "join" (accepted with a warning: the query strategy is used). */
    private function relationLoadStrategy(mixed $strategy, string $target): void
    {
        if ($strategy !== 'query' && $strategy !== 'join') {
            throw new PrismaClientValidationError(sprintf('Invalid value for argument `relationLoadStrategy`: expected "query" or "join", got %s.', json_encode($strategy)));
        }
        if ($strategy === 'join') {
            $this->engine->warn("relationLoadStrategy: 'join' is not supported by Nesto's Prisma client: the 'query' strategy (one query per relation) is used.", $target);
        }
    }

    private function notFound(ModelInfo $info): PrismaClientKnownRequestError
    {
        return PrismaClientKnownRequestError::create('P2025', sprintf('No %s found', $info->name), ['modelName' => $info->name, 'cause' => 'Expected a record, found none.']);
    }

    // ---- connection ------------------------------------------------------------------------------

    /** Open the database connection now (Prisma's $connect; otherwise it opens on the first query). */
    public function connect(): void
    {
        $this->engine->connection();
        if ($this->engine->hasListeners('info')) {
            $this->engine->emitEvent('info', ['timestamp' => new \DateTimeImmutable(), 'message' => sprintf('Connected to %s', $this->engine->platform()), 'target' => 'nesto.prisma']);
        }
    }

    /** Close the connection (Prisma's $disconnect); the next query reconnects. */
    public function disconnect(): void
    {
        $this->engine->disconnect();
    }

    /** The compiled schema (Prisma.dmmf): models, enums, datasource. */
    public function dmmf(): Document
    {
        return $this->engine->dataModel->document;
    }

    /** The underlying ORM connection (shared engine). */
    public function connection(): Connection
    {
        return $this->engine->connection();
    }

    /** @param Closure(array<string, mixed>): void $listener Prisma's $on('query' | 'info' | 'warn' | 'error', fn) */
    public function on(string $event, Closure $listener): void
    {
        if (! in_array($event, ['query', 'info', 'warn', 'error'], true)) {
            throw new PrismaClientValidationError(sprintf('Unknown event `%s` (query, info, warn, error).', $event));
        }
        $this->engine->on($event, $listener);
    }

    // ---- transactions ----------------------------------------------------------------------------

    /**
     * Prisma's $transaction:
     *
     *   - a list of operations, run in order in one transaction; PHP runs calls eagerly, so each operation is a
     *     closure (`fn ($tx) => $tx->user->create(...)`); returns the list of results;
     *   - an interactive transaction: `fn (PrismaClient $tx) => ...` — commits when it returns, rolls back when
     *     it throws. Options: isolationLevel (Prisma::ReadCommitted, ...), timeout (ms, default 5000; a
     *     transaction running longer is rolled back with P2028), maxWait (accepted, PHP waits synchronously).
     *
     * @param list<Closure>|Closure $operations
     * @param array{isolationLevel?: string, timeout?: int, maxWait?: int} $options
     */
    public function transaction(array|Closure $operations, array $options = []): mixed
    {
        $this->assertTransactionUsable();
        $defaults = (array) ($this->options['transactionOptions'] ?? []);
        $options += $defaults;
        $isolation = isset($options['isolationLevel']) ? self::isolationLevel((string) $options['isolationLevel']) : null;
        $tx = $this->transactionClient(is_array($operations) ? null : (int) ($options['timeout'] ?? 5000));
        try {
            return $this->engine->transaction(function () use ($operations, $tx) {
                if (is_array($operations)) {
                    $results = [];
                    foreach ($operations as $operation) {
                        if (! $operation instanceof Closure) {
                            throw new PrismaClientValidationError('$transaction([...]) expects closures: fn ($tx) => $tx->model->operation(...) (PHP runs calls eagerly).');
                        }
                        $results[] = $operation($tx);
                    }

                    return $results;
                }
                $result = $operations($tx);
                $tx->assertTransactionUsable();

                return $result;
            }, $isolation);
        } catch (\Nesto\Orm\OrmException $e) {
            throw $e instanceof \Nesto\Orm\QueryException ? ErrorMapper::map($e, $this->engine->dataModel, null, '$transaction') : new PrismaClientValidationError($e->getMessage(), 0, $e);
        } finally {
            $tx->transactionClosed = true;
        }
    }

    /** "ReadCommitted" / "read committed" / Prisma::Serializable → "READ COMMITTED". */
    private static function isolationLevel(string $level): string
    {
        $spaced = (string) preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $level);

        return strtoupper(trim(str_replace('_', ' ', $spaced)));
    }

    private function transactionClient(?int $timeout): static
    {
        $tx = $this->copy();
        $tx->inTransaction = true;
        $tx->transactionTimeout = $timeout;
        $tx->transactionDeadline = $timeout !== null ? microtime(true) + $timeout / 1000 : null;

        return $tx;
    }

    private function assertTransactionUsable(): void
    {
        if (! $this->inTransaction) {
            return;
        }
        if ($this->transactionClosed) {
            throw PrismaClientKnownRequestError::create('P2028', 'Transaction API error: Transaction already closed: A query cannot be executed on a committed transaction.', ['error' => 'Transaction already closed']);
        }
        if ($this->transactionDeadline !== null && microtime(true) > $this->transactionDeadline) {
            throw PrismaClientKnownRequestError::create('P2028', sprintf(
                'Transaction API error: Transaction already closed: A query cannot be executed on an expired transaction. The timeout for this transaction was %d ms, however more time passed since the start of the transaction. Consider increasing the interactive transaction timeout or doing less work in the transaction.',
                (int) $this->transactionTimeout,
            ), ['error' => 'Transaction expired']);
        }
    }

    /** True for the `$tx` client of an interactive transaction. */
    public function isTransactionClient(): bool
    {
        return $this->inTransaction;
    }

    // ---- raw queries -----------------------------------------------------------------------------

    /**
     * Prisma's $queryRaw: a Sql fragment (Prisma::sql(...)) or SQL with `?` placeholders and their values.
     *
     * @param list<mixed> $values
     * @return list<array<string, mixed>>
     */
    public function queryRaw(Sql|string $query, array $values = []): array
    {
        [$sql, $params] = $this->rawParts($query, $values);

        return $this->runRaw(fn () => $this->rawRows($this->engine->select($sql, $params)));
    }

    /** Prisma's $executeRaw: the number of affected rows. @param list<mixed> $values */
    public function executeRaw(Sql|string $query, array $values = []): int
    {
        [$sql, $params] = $this->rawParts($query, $values);

        return $this->runRaw(fn () => $this->engine->execute($sql, $params));
    }

    /** Prisma's $queryRawUnsafe(query, ...values): SQL built by the caller; values are still bound. @return list<array<string, mixed>> */
    public function queryRawUnsafe(string $query, mixed ...$values): array
    {
        return $this->runRaw(fn () => $this->rawRows($this->engine->select($query, array_values($values))));
    }

    /** Prisma's $executeRawUnsafe(query, ...values). */
    public function executeRawUnsafe(string $query, mixed ...$values): int
    {
        return $this->runRaw(fn () => $this->engine->execute($query, array_values($values)));
    }

    /** @param list<mixed> $values @return array{0: string, 1: list<mixed>} */
    private function rawParts(Sql|string $query, array $values): array
    {
        if ($query instanceof Sql) {
            if ($values !== []) {
                throw new PrismaClientValidationError('Pass the values inside the Sql fragment (Prisma::sql(...)), not as a second argument.');
            }

            return [$query->sql(), $this->rawValues($query->values())];
        }

        return [$query, $this->rawValues(array_values($values))];
    }

    /** @param list<mixed> $values @return list<mixed> */
    private function rawValues(array $values): array
    {
        return array_map(function (mixed $value) {
            if ($value instanceof \DateTimeInterface) {
                return \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
            }
            if ($value instanceof \UnitEnum && ! $value instanceof \BackedEnum) {
                return $value->name;
            }

            return $value;
        }, $values);
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    private function rawRows(array $rows): array
    {
        foreach ($rows as &$row) {
            foreach ($row as $key => $value) {
                if (is_resource($value)) {
                    $row[$key] = (string) stream_get_contents($value);
                }
            }
        }

        return $rows;
    }

    private function runRaw(Closure $fn): mixed
    {
        $this->assertTransactionUsable();
        try {
            return $fn();
        } catch (\Nesto\Orm\QueryException|\PDOException $e) {
            throw ErrorMapper::raw($e);
        }
    }

    // ---- extensions ------------------------------------------------------------------------------

    /**
     * Prisma Client extensions: returns a new client with the extension applied (this one is unchanged).
     *
     *   $xprisma = $prisma->extends([
     *       'name' => 'audit',
     *       'result' => ['user' => ['fullName' => ['needs' => ['firstName' => true, 'lastName' => true],
     *                                              'compute' => fn (array $user) => "{$user['firstName']} {$user['lastName']}"]]],
     *       'query' => ['user' => ['findMany' => fn (array $args, Closure $query) => $query($args)],
     *                   '$allModels' => ['$allOperations' => fn ($model, $operation, $args, $query) => $query($args)]],
     *       'model' => ['user' => ['signUp' => function (string $email) { return $this->create(['data' => ['email' => $email]]); }]],
     *       'client' => ['$log' => fn (string $message) => ...],
     *   ]);
     *
     * Hook closures receive the parameters they name: model, operation, args, query (or one array parameter
     * with all of them).
     *
     * @param array<string, mixed>|Closure $extension
     */
    public function extends(array|Closure $extension): static
    {
        if ($extension instanceof Closure) {
            $result = $extension($this);
            if (! $result instanceof self) {
                throw new PrismaClientValidationError('An extension closure must return the extended client ($client->extends([...])).');
            }

            return $result;
        }
        foreach (array_keys($extension) as $component) {
            if (! in_array($component, ['name', 'model', 'query', 'result', 'client'], true)) {
                throw new PrismaClientValidationError(sprintf('Unknown extension component `%s` (name, model, query, result, client).', $component));
            }
        }
        $clone = $this->copy();
        $clone->extensions[] = $extension;
        if (isset($extension['result'])) {
            $computed = [];
            foreach ($clone->extensions as $ext) {
                foreach ((array) ($ext['result'] ?? []) as $model => $fields) {
                    $modelName = $model === '$allModels' ? '$allModels' : ($this->engine->dataModel->modelOfDelegate((string) $model) ?? (string) $model);
                    foreach ((array) $fields as $field => $definition) {
                        $computed[$modelName][(string) $field] = [
                            'needs' => array_keys(array_filter((array) ($definition['needs'] ?? []))),
                            'compute' => $definition['compute'] ?? throw new PrismaClientValidationError(sprintf('The result extension field `%s` needs a `compute` closure.', $field)),
                        ];
                    }
                }
            }
            $clone->reader = $this->reader->withComputed($computed);
            $clone->writer = new Writer($clone->reader);
        }

        return $clone;
    }

    /** @internal model extension method of a model, or null */
    public function modelExtension(string $model, string $method): ?Closure
    {
        $delegate = lcfirst($model);
        for ($i = count($this->extensions) - 1; $i >= 0; $i--) {
            $components = (array) ($this->extensions[$i]['model'] ?? []);
            foreach ([$delegate, $model, '$allModels'] as $key) {
                if (($components[$key][$method] ?? null) instanceof Closure) {
                    return $components[$key][$method];
                }
            }
        }

        return null;
    }

    /**
     * Client extension methods and the `$`-prefixed Prisma names ($transaction, $queryRaw, ...).
     *
     * @param list<mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        for ($i = count($this->extensions) - 1; $i >= 0; $i--) {
            $components = (array) ($this->extensions[$i]['client'] ?? []);
            $fn = $components[$method] ?? $components['$' . ltrim($method, '$')] ?? null;
            if ($fn instanceof Closure) {
                $bound = @Closure::bind($fn, $this, static::class);

                return ($bound ?? $fn)(...$arguments);
            }
        }
        if (str_starts_with($method, '$') && method_exists($this, substr($method, 1))) {
            return $this->{substr($method, 1)}(...$arguments);
        }

        throw new \BadMethodCallException(sprintf('Call to undefined method %s::%s()', static::class, $method));
    }

    /**
     * Call a hook with the parameters it declares (by name), or the whole context for a single array parameter.
     *
     * @param array<string, mixed> $context
     */
    private static function invoke(Closure $hook, array $context): mixed
    {
        $parameters = (new \ReflectionFunction($hook))->getParameters();
        if (count($parameters) === 1 && ! isset($context[$parameters[0]->getName()])) {
            return $hook($context);
        }
        $arguments = [];
        foreach ($parameters as $parameter) {
            $name = $parameter->getName();
            if ($parameter->isVariadic()) {
                break;
            }
            if (array_key_exists($name, $context)) {
                $arguments[$name] = $context[$name];
            } elseif (! $parameter->isOptional()) {
                throw new PrismaClientValidationError(sprintf('Query extension parameter `$%s` is unknown (model, operation, args, query).', $name));
            }
        }

        return $hook(...$arguments);
    }

    /** A copy sharing the engine (connection, schema, listeners) — for extends() and transactions. */
    private function copy(): static
    {
        $clone = (new \ReflectionClass(static::class))->newInstanceWithoutConstructor();
        $clone->engine = $this->engine;
        $clone->reader = $this->reader;
        $clone->writer = $this->writer;
        $clone->options = $this->options;
        $clone->schemaDir = $this->schemaDir;
        $clone->extensions = $this->extensions;
        $clone->delegates = [];
        $clone->inTransaction = $this->inTransaction;
        $clone->transactionClosed = $this->transactionClosed;
        $clone->transactionDeadline = $this->transactionDeadline;
        $clone->transactionTimeout = $this->transactionTimeout;
        $clone->bootDelegates();

        return $clone;
    }
}
