<?php

declare(strict_types=1);

namespace Nesto\Orm\Migration;

use Nesto\Orm\Schema\Check;
use Nesto\Orm\Schema\Column;
use Nesto\Orm\Schema\ForeignKey;
use Nesto\Orm\Schema\Index;
use Nesto\Orm\Schema\Table;

/**
 * Table definition used by Schema::createTable() / Schema::table().
 *
 *   $schema->createTable('posts', function (Blueprint $t) {
 *       $t->id();
 *       $t->string('title');
 *       $t->text('body')->nullable();
 *       $t->enum('status', ['draft', 'published'])->default('draft');
 *       $t->json('meta')->nullable();
 *       $t->foreignId('user_id')->references('id')->on('users')->onDelete('CASCADE');
 *       $t->morphs('commentable');   // commentable_type + commentable_id + index (nullableMorphs, uuidMorphs, ...)
 *       $t->timestamps();
 *       $t->softDeletes();
 *       $t->index(['status', 'created_at']);
 *       $t->check('char_length(title) > 0');
 *   });
 *
 * Altering an existing table (Schema::table()):
 *
 *   $schema->table('posts', function (Blueprint $t) {
 *       $t->string('subtitle')->nullable();             // add
 *       $t->string('title', 300)->change();              // change the type / length: the rest is kept
 *       $t->bigInteger('votes')->nullable(false)->noDefault()->comment(null)->change();   // reset attributes
 *       $t->renameColumn('body', 'content');
 *       $t->dropColumn('legacy');
 *       $t->dropIndex(['status', 'created_at']);         // by columns or by name
 *       $t->dropUnique('uq_posts_slug');
 *       $t->dropForeign(['user_id']);
 *       $t->dropPrimary();  $t->primary(['id', 'locale']);
 *       $t->dropCheck('chk_posts_title');
 *   });
 *
 * ->change() never loses an attribute silently: the type (length, precision, enum values) always comes from the
 * definition, and nullable / default / unsigned / comment / charset / collation / auto-increment come from it only
 * when a modifier sets them — otherwise the live column's value is kept (introspected when the migration runs).
 * Reset one with ->nullable(false), ->noDefault(), ->unsigned(false), ->comment(null), ->autoIncrement(false).
 * Unlike Laravel 11+, which drops every attribute not repeated. migration:generate writes complete definitions
 * (including those resets), so generated migrations do not depend on this.
 */
final class Blueprint
{
    /** @var list<ColumnDefinition> columns to add (or, with ->change(), to alter) */
    public array $columns = [];
    /**
     * `sorts` (column => ASC|DESC), `lengths` (column => prefix length, MySQL) and `using` (index method:
     * HASH, GIN, ...) are optional (see Schema\Index).
     *
     * @var list<array{columns: list<string>, unique: bool, name: ?string, where?: ?string, sorts?: array<string, string>, lengths?: array<string, int>, using?: ?string}>
     */
    public array $indexes = [];
    /** @var list<string> */
    public array $primaryKeys = [];
    /** Name of the primary key constraint (PostgreSQL: `CONSTRAINT name PRIMARY KEY (...)`); null: the database's default. */
    public ?string $primaryKeyName = null;
    /** @var list<ForeignKeyDefinition> */
    public array $foreignKeys = [];
    /** @var list<array{expression: string, name: ?string}> */
    public array $checks = [];
    /**
     * Alterations of an existing table, in call order.
     * @var list<array{type: string, columns?: list<string>, name?: ?string, from?: string, to?: string}>
     */
    public array $commands = [];

    public function __construct(public readonly string $table) {}

    public function id(string $name = 'id'): ColumnDefinition
    {
        return $this->add(new ColumnDefinition($name, 'bigint'))->primary()->autoIncrement();
    }

    /** Auto-increment INTEGER primary key (TypeORM's default @PrimaryGeneratedColumn type). */
    public function increments(string $name = 'id'): ColumnDefinition
    {
        return $this->add(new ColumnDefinition($name, 'int'))->primary()->autoIncrement();
    }

    public function uuid(string $name = 'id'): ColumnDefinition
    {
        return $this->add(new ColumnDefinition($name, 'uuid'));
    }

    public function string(string $name, int $length = 255): ColumnDefinition
    {
        return $this->add(new ColumnDefinition($name, 'string', length: $length));
    }

    public function char(string $name, int $length = 1): ColumnDefinition
    {
        return $this->add(new ColumnDefinition($name, 'char', length: $length));
    }

    public function text(string $name): ColumnDefinition
    {
        return $this->add(new ColumnDefinition($name, 'text'));
    }

    public function mediumText(string $name): ColumnDefinition
    {
        return $this->add(new ColumnDefinition($name, 'mediumtext'));
    }

    public function longText(string $name): ColumnDefinition
    {
        return $this->add(new ColumnDefinition($name, 'longtext'));
    }

    public function integer(string $name): ColumnDefinition
    {
        return $this->add(new ColumnDefinition($name, 'int'));
    }

    public function tinyInteger(string $name): ColumnDefinition
    {
        return $this->add(new ColumnDefinition($name, 'tinyint'));
    }

    public function smallInteger(string $name): ColumnDefinition
    {
        return $this->add(new ColumnDefinition($name, 'smallint'));
    }

    public function bigInteger(string $name): ColumnDefinition
    {
        return $this->add(new ColumnDefinition($name, 'bigint'));
    }

    public function float(string $name): ColumnDefinition
    {
        return $this->add(new ColumnDefinition($name, 'float'));
    }

    public function double(string $name): ColumnDefinition
    {
        return $this->add(new ColumnDefinition($name, 'double'));
    }

    public function decimal(string $name, int $precision = 10, int $scale = 2): ColumnDefinition
    {
        return $this->add(new ColumnDefinition($name, 'decimal', precision: $precision, scale: $scale));
    }

    public function boolean(string $name): ColumnDefinition
    {
        return $this->add(new ColumnDefinition($name, 'bool'));
    }

    public function date(string $name): ColumnDefinition
    {
        return $this->add(new ColumnDefinition($name, 'date'));
    }

    /** @param ?int $precision fractional seconds */
    public function time(string $name, ?int $precision = null): ColumnDefinition
    {
        return $this->add(new ColumnDefinition($name, 'time', precision: $precision));
    }

    /** @param ?int $precision fractional seconds */
    public function datetime(string $name, ?int $precision = null): ColumnDefinition
    {
        return $this->add(new ColumnDefinition($name, 'datetime', precision: $precision));
    }

    /** @param ?int $precision fractional seconds */
    public function timestamp(string $name, ?int $precision = null): ColumnDefinition
    {
        return $this->add(new ColumnDefinition($name, 'timestamp', precision: $precision));
    }

    /** TIMESTAMPTZ on PostgreSQL, TIMESTAMP on MySQL/MariaDB. */
    public function timestampTz(string $name, ?int $precision = null): ColumnDefinition
    {
        return $this->add(new ColumnDefinition($name, 'timestamptz', precision: $precision));
    }

    public function json(string $name): ColumnDefinition
    {
        return $this->add(new ColumnDefinition($name, 'json'));
    }

    public function jsonb(string $name): ColumnDefinition
    {
        return $this->add(new ColumnDefinition($name, 'jsonb'));
    }

    /** BLOB / BYTEA; with a length BINARY(n) on MySQL/MariaDB. */
    public function binary(string $name, ?int $length = null): ColumnDefinition
    {
        return $this->add(new ColumnDefinition($name, $length !== null ? 'binary' : 'blob', length: $length));
    }

    /** @param list<string|int> $values */
    public function enum(string $name, array $values): ColumnDefinition
    {
        $column = $this->add(new ColumnDefinition($name, 'enum'));
        $column->values = $values;

        return $column;
    }

    /** A column of any #[Column] type name: `$table->column('ip', 'inet')`, `$table->column('code', 'varchar', 3)`. */
    public function column(string $name, string $type, ?int $length = null, ?int $precision = null, ?int $scale = null): ColumnDefinition
    {
        return $this->add(new ColumnDefinition($name, $type, $length, $precision, $scale));
    }

    public function foreignId(string $name): ForeignKeyDefinition
    {
        $column = new ForeignKeyDefinition($name, 'bigint');
        $this->add($column);
        $this->foreignKeys[] = $column;

        return $column;
    }

    public function foreignUuid(string $name): ForeignKeyDefinition
    {
        $column = new ForeignKeyDefinition($name, 'uuid');
        $this->add($column);
        $this->foreignKeys[] = $column;

        return $column;
    }

    /**
     * Add a foreign key constraint on existing column(s) of this blueprint.
     *
     * @param string|list<string> $columns
     */
    public function foreign(string|array $columns, ?string $name = null): ForeignKeyDefinition
    {
        $columns = array_values((array) $columns);
        $fk = new ForeignKeyDefinition((string) $columns[0], 'bigint');
        $fk->constraintOnly = true;
        $fk->columns = count($columns) > 1 ? $columns : [];
        $fk->constraintName = $name;
        $this->foreignKeys[] = $fk;

        return $fk;
    }

    /**
     * Polymorphic columns `{name}_type` (varchar) + `{name}_id` (bigint) and a composite index — what a
     * #[MorphTo] stores. `uuidMorphs()` / `stringMorphs()` for uuid / string keys; `nullable*` variants.
     */
    public function morphs(string $name, ?string $indexName = null, string $idType = 'bigint', bool $nullable = false): void
    {
        $this->string($name . '_type')->nullable($nullable);
        $this->add(new ColumnDefinition($name . '_id', $idType, $idType === 'string' ? 255 : null))->nullable($nullable);
        $this->index([$name . '_type', $name . '_id'], $indexName);
    }

    public function nullableMorphs(string $name, ?string $indexName = null): void
    {
        $this->morphs($name, $indexName, 'bigint', true);
    }

    public function uuidMorphs(string $name, ?string $indexName = null): void
    {
        $this->morphs($name, $indexName, 'uuid');
    }

    public function nullableUuidMorphs(string $name, ?string $indexName = null): void
    {
        $this->morphs($name, $indexName, 'uuid', true);
    }

    /** Morph columns with a varchar id: for parents with different key types (int and uuid). */
    public function stringMorphs(string $name, ?string $indexName = null, bool $nullable = false): void
    {
        $this->morphs($name, $indexName, 'string', $nullable);
    }

    public function timestamps(): void
    {
        $this->datetime('created_at')->nullable();
        $this->datetime('updated_at')->nullable();
    }

    public function softDeletes(string $name = 'deleted_at'): ColumnDefinition
    {
        return $this->datetime($name)->nullable();
    }

    /** @param string|list<string> $columns */
    public function primary(string|array $columns): void
    {
        $this->primaryKeys = array_values((array) $columns);
    }

    /**
     * @param string|list<string> $columns
     * @param ?string $where partial index predicate (PostgreSQL, SQLite; ignored by MySQL/MariaDB)
     */
    public function index(string|array $columns, ?string $name = null, ?string $where = null): void
    {
        $this->indexes[] = ['columns' => array_values((array) $columns), 'unique' => false, 'name' => $name, 'where' => $where];
    }

    /** @param string|list<string> $columns */
    public function unique(string|array $columns, ?string $name = null, ?string $where = null): void
    {
        $this->indexes[] = ['columns' => array_values((array) $columns), 'unique' => true, 'name' => $name, 'where' => $where];
    }

    /** CHECK constraint (raw SQL expression). Unnamed checks are named "chk_{table}_{hash of the expression}". */
    public function check(string $expression, ?string $name = null): void
    {
        $this->checks[] = ['expression' => $expression, 'name' => $name];
    }

    // ---- alterations of an existing table ------------------------------------------------------

    /** @param string|list<string> ...$columns */
    public function dropColumn(string|array ...$columns): void
    {
        $names = [];
        foreach ($columns as $column) {
            array_push($names, ...(array) $column);
        }
        $this->commands[] = ['type' => 'dropColumn', 'columns' => array_values($names)];
    }

    public function renameColumn(string $from, string $to): void
    {
        $this->commands[] = ['type' => 'renameColumn', 'from' => $from, 'to' => $to];
    }

    /** Drop an index by name, or by its columns (the matching index is looked up). @param string|list<string> $nameOrColumns */
    public function dropIndex(string|array $nameOrColumns): void
    {
        $this->commands[] = ['type' => 'dropIndex'] + self::target($nameOrColumns);
    }

    /** Drop a unique index / constraint by name or by its columns. @param string|list<string> $nameOrColumns */
    public function dropUnique(string|array $nameOrColumns): void
    {
        $this->commands[] = ['type' => 'dropUnique'] + self::target($nameOrColumns);
    }

    /** Drop a foreign key by constraint name or by its columns. @param string|list<string> $nameOrColumns */
    public function dropForeign(string|array $nameOrColumns): void
    {
        $this->commands[] = ['type' => 'dropForeign'] + self::target($nameOrColumns);
    }

    public function dropPrimary(): void
    {
        $this->commands[] = ['type' => 'dropPrimary'];
    }

    /** Drop a CHECK constraint by name (or by its exact expression for unnamed SQLite checks). */
    public function dropCheck(string $name): void
    {
        $this->commands[] = ['type' => 'dropCheck', 'name' => $name];
    }

    public function dropTimestamps(): void
    {
        $this->dropColumn('created_at', 'updated_at');
    }

    public function dropSoftDeletes(string $name = 'deleted_at'): void
    {
        $this->dropColumn($name);
    }

    public function add(ColumnDefinition $column): ColumnDefinition
    {
        $this->columns[] = $column;

        return $column;
    }

    /** This blueprint as a table of the schema model (what comparisons work on). */
    public function toTable(): Table
    {
        $blueprint = $this;
        $table = new Table($blueprint->table);
        foreach ($blueprint->columns as $column) {
            $copy = $column->copyAs(Column::class);
            $copy->unique = $copy->index = $copy->change = false;
            $copy->primary = false;
            $table->columns[$column->name] = $copy;
            if ($column->primary && $blueprint->primaryKeys === []) {
                $table->primaryKey[] = $column->name;
            }
            if ($column->unique && ! $column->primary) {
                $table->indexes[] = new Index([$column->name], true, null, false, null, true);
            }
            if ($column->index) {
                $table->indexes[] = new Index([$column->name], false, Index::defaultName($blueprint->table, [$column->name], false));
            }
        }
        if ($blueprint->primaryKeys !== []) {
            $table->primaryKey = $blueprint->primaryKeys;
        }
        foreach ($table->primaryKey as $name) {
            if (isset($table->columns[$name])) {
                $table->columns[$name]->primary = true;
                $table->columns[$name]->nullable = false;
            }
        }
        foreach ($blueprint->indexes as $index) {
            $table->indexes[] = new Index(
                $index['columns'],
                $index['unique'],
                $index['name'] ?? Index::defaultName($blueprint->table, $index['columns'], $index['unique']),
                $index['name'] !== null,
                $index['where'] ?? null,
                sorts: $index['sorts'] ?? [],
                lengths: $index['lengths'] ?? [],
                using: $index['using'] ?? null,
            );
        }
        $table->primaryKeyName = $blueprint->primaryKeyName;
        foreach ($blueprint->foreignKeys as $fk) {
            if ($fk->referencedTable === null) {
                continue;
            }
            $table->foreignKeys[] = new ForeignKey(
                $fk->constrainedColumns(),
                $fk->referencedTable,
                $fk->referencedColumnList(),
                $fk->onDeleteAction,
                $fk->onUpdateAction,
                $fk->constraintName,
                $fk->constraintName !== null,
            );
        }
        foreach ($blueprint->checks as $check) {
            $table->checks[] = new Check($check['expression'], $check['name'] ?? Check::defaultName($blueprint->table, $check['expression']));
        }

        return $table;
    }

    /** A blueprint that creates $table (single-column primary keys and unnamed uniques stay on the column). */
    public static function fromTable(Table $table): self
    {
        $blueprint = new self($table->name);
        $singlePrimary = count($table->primaryKey) === 1;
        $added = [];
        foreach ($table->columns as $column) {
            $copy = ColumnDefinition::of($column);
            $copy->primary = $singlePrimary && $column->name === $table->primaryKey[0];
            // auto-increment keys, and the PostgreSQL sequences of #[Generated('increment')] columns
            $copy->autoIncrement = $column->autoIncrement && ($copy->primary || $column->generated !== null);
            $copy->unique = $copy->index = $copy->change = false;
            $added[$column->name] = $blueprint->add($copy);
        }
        if (! $singlePrimary && $table->primaryKey !== []) {
            $blueprint->primary($table->primaryKey);
        }
        foreach ($table->indexes as $index) {
            $column = count($index->columns) === 1 ? ($added[$index->columns[0]] ?? null) : null;
            if ($index->unique && $index->constraint && ! $index->explicitName && $index->where === null
                && $column !== null && ! $column->primary && ! $column->unique) {
                $column->unique = true;
                continue;
            }
            $blueprint->indexes[] = ['columns' => $index->columns, 'unique' => $index->unique, 'name' => $index->name, 'where' => $index->where]
                + array_filter(['sorts' => $index->sorts, 'lengths' => $index->lengths, 'using' => $index->using], fn ($v) => $v !== [] && $v !== null);
        }
        $blueprint->primaryKeyName = $table->primaryKeyName;
        foreach ($table->foreignKeys as $fk) {
            $blueprint->foreign(count($fk->columns) === 1 ? $fk->columns[0] : $fk->columns, $fk->explicitName ? $fk->name : null)
                ->references(count($fk->referencedColumns) === 1 ? $fk->referencedColumns[0] : $fk->referencedColumns)
                ->on($fk->referencedTable)
                ->onDelete($fk->onDelete)
                ->onUpdate($fk->onUpdate);
        }
        foreach ($table->checks as $check) {
            $blueprint->check($check->expression, $check->name);
        }

        return $blueprint;
    }

    /** Anything beyond adding columns / indexes / foreign keys (needs Schema::table()'s alter path). */
    public function altersExisting(): bool
    {
        if ($this->commands !== [] || $this->checks !== [] || $this->primaryKeys !== []) {
            return true;
        }
        foreach ($this->columns as $column) {
            if ($column->change) {
                return true;
            }
        }

        return false;
    }

    /** @return array{columns?: list<string>, name?: string} */
    private static function target(string|array $nameOrColumns): array
    {
        return is_array($nameOrColumns) ? ['columns' => array_values($nameOrColumns)] : ['name' => $nameOrColumns];
    }
}
