<?php

declare(strict_types=1);

namespace Nesto\Orm\Migration;

use Nesto\Orm\Connection;
use Nesto\Orm\OrmException;

/**
 * Runs / reverts Migration classes and records them in a `migrations` table (name, batch, executed_at).
 *
 *   $migrator = new Migrator($connection, [CreateUsers::class, CreatePosts::class]);
 *   $migrator->run();                  // ["CreateUsers", "CreatePosts"], in one transaction (mode "all")
 *   $migrator->run(transaction: 'each'); // one transaction per migration ("none": no transactions)
 *   $migrator->run(fake: true);         // record as executed without running them
 *   $migrator->revert();               // "CreatePosts"
 *   $migrator->status();               // [['name' => ..., 'ran' => true, 'batch' => 1], ...]
 */
final class Migrator
{
    /** Transaction modes (TypeORM's `transaction` / `migrationsTransactionMode` option). */
    public const TRANSACTION_MODES = ['all', 'each', 'none'];

    /** @var list<Migration> */
    private array $migrations = [];

    /**
     * @param list<class-string<Migration>|Migration|string> $migrations classes, instances, or directories of migration files
     * @param 'all'|'each'|'none' $transaction default transaction mode: all pending migrations in one transaction
     *        (TypeORM's default), each in its own, or none (a migration can still opt in / out with transactional())
     */
    public function __construct(
        private readonly Connection $connection,
        array $migrations = [],
        private readonly string $table = 'migrations',
        private readonly string $transaction = 'all',
        private readonly string $metadataTable = Schema::METADATA_TABLE,
    ) {
        self::assertMode($transaction);
        foreach ($migrations as $migration) {
            $this->add($migration);
        }
    }

    /** @param class-string<Migration>|Migration|string $migration */
    public function add(string|Migration $migration): self
    {
        if ($migration instanceof Migration) {
            $this->migrations[] = $migration;
        } elseif (is_dir($migration)) {
            $this->loadDirectory($migration);
        } elseif (class_exists($migration) && is_subclass_of($migration, Migration::class)) {
            $this->migrations[] = new $migration;
        } else {
            throw new OrmException("\"$migration\" is not a Migration class, instance or directory.");
        }

        return $this;
    }

    /** @return list<Migration> */
    public function migrations(): array
    {
        return $this->migrations;
    }

    /**
     * Run every pending migration (in the given order) as one batch. `$transaction` overrides the mode;
     * `$fake` records the migrations as executed without running them (TypeORM's --fake).
     *
     * @param 'all'|'each'|'none'|null $transaction
     * @return list<string> executed names
     */
    public function run(?string $transaction = null, bool $fake = false): array
    {
        $mode = $transaction ?? $this->transaction;
        self::assertMode($mode);
        $this->ensureTable();
        $ran = $this->ranNames();
        $pending = array_values(array_filter($this->migrations, fn (Migration $m) => ! in_array($m->name(), $ran, true)));
        $logger = $this->connection->getOrmLogger();
        if ($pending === []) {
            $logger?->logMigration('No migrations are pending');

            return [];
        }
        $logger?->logMigration(count($pending) . ' migrations are new migrations must be executed.');
        $batch = $this->lastBatch() + 1;
        $executed = [];
        $step = function (Migration $migration) use ($batch, $fake, &$executed): void {
            if (! $fake) {
                $migration->up(new Schema($this->connection, $this->metadataTable));
            }
            $this->connection->execute(
                'INSERT INTO ' . $this->quotedTable() . ' (' . $this->connection->quote('name') . ', ' . $this->connection->quote('batch') . ', ' . $this->connection->quote('executed_at') . ') VALUES (:name, :batch, :at)',
                ['name' => $migration->name(), 'batch' => $batch, 'at' => date('Y-m-d H:i:s')],
            );
            $executed[] = $migration->name();
            $this->connection->getOrmLogger()?->logMigration('Migration ' . $migration->name() . ' has been ' . ($fake ? '(fake) ' : '') . 'executed successfully.');
        };
        if ($mode === 'all') {
            $forbidden = array_values(array_filter($pending, fn (Migration $m) => $m->transactional() === false));
            if ($forbidden !== [] && ! $fake) {
                throw new OrmException(sprintf(
                    'Migration%s %s disable%s transactions (transactional() returns false), which the "all" transaction mode cannot honour: run them with the "each" or "none" mode.',
                    count($forbidden) === 1 ? '' : 's',
                    implode(', ', array_map(fn (Migration $m) => $m->name(), $forbidden)),
                    count($forbidden) === 1 ? 's' : '',
                ));
            }
            $this->unit($pending, true, function () use ($pending, $step): void {
                foreach ($pending as $migration) {
                    $step($migration);
                }
            });

            return $executed;
        }
        foreach ($pending as $migration) {
            $this->unit([$migration], self::wraps($mode, $migration), fn () => $step($migration));
        }

        return $executed;
    }

    /**
     * Revert the most recently executed migration. Returns its name or null when nothing ran.
     *
     * @param 'all'|'each'|'none'|null $transaction ('all' and 'each' both wrap the one migration)
     */
    public function revert(?string $transaction = null, bool $fake = false): ?string
    {
        $mode = $transaction ?? $this->transaction;
        self::assertMode($mode);
        $this->ensureTable();
        $row = $this->connection->fetchOne(
            'SELECT ' . $this->connection->quote('name') . ' FROM ' . $this->quotedTable() . ' ORDER BY ' . $this->connection->quote('id') . ' DESC LIMIT 1'
        );
        if ($row === null) {
            return null;
        }
        $name = (string) $row['name'];
        $migration = $this->find($name) ?? throw new OrmException("Migration \"$name\" was executed but its class is not registered; cannot revert.");
        $this->unit([$migration], self::wraps($mode, $migration), function () use ($migration, $name, $fake): void {
            if (! $fake) {
                $migration->down(new Schema($this->connection, $this->metadataTable));
            }
            $this->connection->execute('DELETE FROM ' . $this->quotedTable() . ' WHERE ' . $this->connection->quote('name') . ' = :name', ['name' => $name]);
        });
        $this->connection->getOrmLogger()?->logMigration('Migration ' . $name . ' has been ' . ($fake ? '(fake) ' : '') . 'reverted successfully.');

        return $name;
    }

    /** Revert the whole last batch. @return list<string> */
    public function rollbackBatch(?string $transaction = null): array
    {
        $this->ensureTable();
        $batch = $this->lastBatch();
        if ($batch === 0) {
            return [];
        }
        $rows = $this->connection->fetchAll(
            'SELECT ' . $this->connection->quote('name') . ' FROM ' . $this->quotedTable() . ' WHERE ' . $this->connection->quote('batch') . ' = :b ORDER BY ' . $this->connection->quote('id') . ' DESC',
            ['b' => $batch],
        );
        $reverted = [];
        foreach ($rows as $row) {
            $reverted[] = (string) $this->revert($transaction);
        }

        return $reverted;
    }

    /** @return list<array{name: string, ran: bool, batch: ?int}> */
    public function status(): array
    {
        $this->ensureTable();
        $rows = [];
        foreach ($this->connection->fetchAll('SELECT ' . $this->connection->quote('name') . ', ' . $this->connection->quote('batch') . ' FROM ' . $this->quotedTable()) as $row) {
            $rows[(string) $row['name']] = (int) $row['batch'];
        }
        $out = [];
        foreach ($this->migrations as $migration) {
            $name = $migration->name();
            $out[] = ['name' => $name, 'ran' => isset($rows[$name]), 'batch' => $rows[$name] ?? null];
        }

        return $out;
    }

    public function ensureTable(): void
    {
        $schema = new Schema($this->connection, $this->metadataTable);
        if ($schema->hasTable($this->table)) {
            return;
        }
        $schema->createTable($this->table, function (Blueprint $t): void {
            $t->id();
            $t->string('name')->unique();
            $t->integer('batch');
            $t->datetime('executed_at');
        }, ifNotExists: true);
    }

    /**
     * Run $body as one unit: inside a transaction when $transactional and the platform has transactional DDL
     * (PostgreSQL, SQLite — MySQL/MariaDB commit implicitly on DDL, so they never get one). On SQLite, foreign
     * keys are switched off around the unit (like TypeORM), so table rebuilds can drop and recreate referenced
     * tables, and PRAGMA foreign_key_check runs before committing instead of per-row checks.
     *
     * @param list<Migration> $migrations
     */
    private function unit(array $migrations, bool $transactional, \Closure $body): void
    {
        $sqliteChecks = $this->connection->driver() === 'sqlite' && ! $this->connection->inTransaction()
            && (int) $this->connection->fetchColumn('PRAGMA foreign_keys') === 1;
        $run = function () use ($body, $sqliteChecks, $migrations): void {
            $body();
            if ($sqliteChecks) {
                $this->assertForeignKeys($migrations);
            }
        };
        if ($sqliteChecks) {
            $this->connection->execute('PRAGMA foreign_keys = OFF');
        }
        try {
            if ($transactional && $this->connection->driver() !== 'mysql') {
                $this->connection->transaction($run);
            } else {
                $run();
            }
        } finally {
            if ($sqliteChecks) {
                $this->connection->execute('PRAGMA foreign_keys = ON');
            }
        }
    }

    /** Does $migration get its own transaction in the "each" / "none" modes? */
    private static function wraps(string $mode, Migration $migration): bool
    {
        return $mode === 'none' ? $migration->transactional() === true : $migration->transactional() !== false;
    }

    private static function assertMode(string $mode): void
    {
        if (! in_array($mode, self::TRANSACTION_MODES, true)) {
            throw new OrmException("Unknown migration transaction mode \"$mode\": use all, each or none.");
        }
    }

    /** @param list<Migration> $migrations */
    private function assertForeignKeys(array $migrations): void
    {
        $violations = $this->connection->fetchAll('PRAGMA foreign_key_check');
        if ($violations !== []) {
            $first = $violations[0];
            throw new OrmException(sprintf(
                'Migration %s leaves %d row%s violating foreign keys (first: "%s" row %s → "%s").',
                implode(', ', array_map(fn (Migration $m) => $m->name(), $migrations)), count($violations), count($violations) === 1 ? '' : 's', $first['table'], $first['rowid'] ?? '?', $first['parent'],
            ));
        }
    }

    private function find(string $name): ?Migration
    {
        foreach ($this->migrations as $migration) {
            if ($migration->name() === $name) {
                return $migration;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function ranNames(): array
    {
        return array_map(fn ($r) => (string) $r['name'], $this->connection->fetchAll('SELECT ' . $this->connection->quote('name') . ' FROM ' . $this->quotedTable()));
    }

    private function lastBatch(): int
    {
        return (int) $this->connection->fetchColumn('SELECT MAX(' . $this->connection->quote('batch') . ') FROM ' . $this->quotedTable());
    }

    private function quotedTable(): string
    {
        return $this->connection->quote($this->table);
    }

    /** Load every Migration subclass declared in the *.php files of a directory (sorted by file name). */
    private function loadDirectory(string $dir): void
    {
        $files = glob(rtrim($dir, '/') . '/*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            require_once $file;
            foreach (self::classesIn($file) as $class) {
                if (class_exists($class) && is_subclass_of($class, Migration::class) && ! (new \ReflectionClass($class))->isAbstract()) {
                    if ($this->find((new $class)->name()) === null) {
                        $this->migrations[] = new $class;
                    }
                }
            }
        }
    }

    /** Fully-qualified class names declared in a PHP file (tokenizer based, so it works even when the file was already included). */
    private static function classesIn(string $file): array
    {
        $tokens = token_get_all((string) file_get_contents($file));
        $namespace = '';
        $classes = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (! is_array($token)) {
                continue;
            }
            if ($token[0] === T_NAMESPACE) {
                $namespace = '';
                for ($j = $i + 1; $j < $count; $j++) {
                    if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                        $namespace .= $tokens[$j][1];
                    } elseif ($tokens[$j] === ';' || $tokens[$j] === '{') {
                        break;
                    }
                }
                continue;
            }
            if ($token[0] === T_CLASS) {
                $prev = $tokens[$i - 1] ?? null;
                if (is_array($prev) && $prev[0] === T_DOUBLE_COLON) {
                    continue; // Foo::class
                }
                for ($j = $i + 1; $j < $count; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        $classes[] = ($namespace !== '' ? $namespace . '\\' : '') . $tokens[$j][1];
                        break;
                    }
                    if (! is_array($tokens[$j]) || $tokens[$j][0] !== T_WHITESPACE) {
                        break; // anonymous class
                    }
                }
            }
        }

        return $classes;
    }
}
