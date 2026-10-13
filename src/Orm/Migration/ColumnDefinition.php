<?php

declare(strict_types=1);

namespace Nesto\Orm\Migration;

use Nesto\Orm\Schema\Column;
use Nesto\Orm\SqlExpression;

/**
 * Fluent column builder returned by Blueprint methods (the column itself is a Schema\Column).
 *
 *   $table->string('email')->unique();
 *   $table->integer('votes')->default(0)->unsigned()->comment('Up votes');
 *   $table->text('bio')->nullable();
 *   $table->datetime('seen_at')->useCurrent();                      // DEFAULT CURRENT_TIMESTAMP
 *   $table->string('title', 200)->nullable()->change();             // alter an existing column (Schema::table)
 *
 * ->change() alters only what the definition states: the type (with its length / precision / values) always,
 * and the attributes set through a modifier here. Nullability, default, unsigned, comment, charset, collation and
 * auto-increment that are not repeated keep the live column's value (it is introspected), so nothing is lost
 * silently; reset one explicitly: ->nullable(false), ->noDefault(), ->unsigned(false), ->comment(null),
 * ->autoIncrement(false). Generated migrations write those resets themselves (the full definition).
 */
class ColumnDefinition extends Column
{
    /** Attributes inherit() can take from the live column, and the properties holding each. */
    private const INHERITABLE = [
        'nullable' => ['nullable'],
        'default' => ['default', 'hasDefault'],
        'unsigned' => ['unsigned'],
        'comment' => ['comment'],
        'charset' => ['charset'],
        'collation' => ['collation'],
        'autoIncrement' => ['autoIncrement', 'identity'],
    ];
    private const TEXT_TYPES = ['string', 'varchar', 'char', 'text', 'mediumtext', 'longtext', 'tinytext', 'enum', 'simple-enum', 'set', 'citext'];

    /** @var array<string, true>|null attributes set through a modifier (see INHERITABLE); null: a complete definition */
    public ?array $specified = [];
    /** The live column a generated ->change() replaces: MigrationRenderer writes the modifiers resetting what it had. */
    public ?Column $previous = null;

    /** The same column as a complete ColumnDefinition (for model columns, e.g. introspected or entity ones). */
    public static function of(Column $column): self
    {
        $copy = $column instanceof self ? clone $column : $column->copyAs(self::class);
        $copy->specified = null;

        return $copy;
    }

    /**
     * This ->change() definition completed with the attributes of the live column it does not set itself (see the
     * class docblock); charset / collation only carry over to a text type.
     */
    public function inherit(Column $live): static
    {
        if ($this->specified === null) {
            return $this;
        }
        $merged = clone $this;
        foreach (self::INHERITABLE as $attribute => $properties) {
            if (isset($this->specified[$attribute])) {
                continue;
            }
            if (in_array($attribute, ['charset', 'collation'], true) && ! in_array(strtolower($this->type), self::TEXT_TYPES, true)) {
                continue;
            }
            foreach ($properties as $property) {
                $merged->{$property} = $live->{$property};
            }
        }
        $merged->specified = null;

        return $merged;
    }

    private function specify(string $attribute): void
    {
        if ($this->specified !== null) {
            $this->specified[$attribute] = true;
        }
    }

    public function nullable(bool $nullable = true): static
    {
        $this->nullable = $nullable;
        $this->specify('nullable');

        return $this;
    }

    /** A value, or raw SQL as a Nesto\Orm\SqlExpression (`new SqlExpression('CURRENT_TIMESTAMP')`). */
    public function default(mixed $value): static
    {
        $this->default = SqlExpression::from($value) ?? $value;
        $this->hasDefault = true;
        $this->specify('default');

        return $this;
    }

    /** No DEFAULT (with ->change(): drops the live column's default). */
    public function noDefault(): static
    {
        $this->default = null;
        $this->hasDefault = false;
        $this->specify('default');

        return $this;
    }

    /** DEFAULT CURRENT_TIMESTAMP. */
    public function useCurrent(): static
    {
        return $this->default(new SqlExpression('CURRENT_TIMESTAMP'));
    }

    public function unique(bool $unique = true): static
    {
        $this->unique = $unique;

        return $this;
    }

    public function index(bool $index = true): static
    {
        $this->index = $index;

        return $this;
    }

    public function primary(bool $primary = true): static
    {
        $this->primary = $primary;

        return $this;
    }

    public function autoIncrement(bool $auto = true): static
    {
        $this->autoIncrement = $auto;
        $this->specify('autoIncrement');

        return $this;
    }

    /** MySQL/MariaDB only (ignored elsewhere). */
    public function unsigned(bool $unsigned = true): static
    {
        $this->unsigned = $unsigned;
        $this->specify('unsigned');

        return $this;
    }

    /** Column comment (MySQL/MariaDB, PostgreSQL; ignored by SQLite). */
    public function comment(?string $comment): static
    {
        $this->comment = $comment;
        $this->specify('comment');

        return $this;
    }

    /** MySQL/MariaDB character set. */
    public function charset(?string $charset): static
    {
        $this->charset = $charset;
        $this->specify('charset');

        return $this;
    }

    public function collation(?string $collation): static
    {
        $this->collation = $collation;
        $this->specify('collation');

        return $this;
    }

    /** Use a native PostgreSQL enum type of this name (created when missing). */
    public function enumName(?string $name): static
    {
        $this->enumName = $name;

        return $this;
    }

    /** Generated column stored on write: GENERATED ALWAYS AS (expression) STORED. */
    public function storedAs(string $expression): static
    {
        $this->generatedType = 'STORED';
        $this->asExpression = $expression;

        return $this;
    }

    /** Generated column computed on read (MySQL/MariaDB/SQLite; PostgreSQL 18+). */
    public function virtualAs(string $expression): static
    {
        $this->generatedType = 'VIRTUAL';
        $this->asExpression = $expression;

        return $this;
    }

    /** PostgreSQL identity column (GENERATED BY DEFAULT / ALWAYS AS IDENTITY); auto-increment elsewhere. */
    public function generatedAs(string $identity = 'BY DEFAULT'): static
    {
        $this->identity = strtoupper($identity);
        $this->autoIncrement = true;
        $this->specify('autoIncrement');

        return $this;
    }

    /**
     * A value generated on insert: 'uuid' (PostgreSQL DEFAULT gen_random_uuid(); the ORM generates it on the
     * other databases) or 'increment' (a PostgreSQL sequence on a column that is not the key).
     */
    public function generated(string $strategy): static
    {
        $this->generated = strtolower($strategy);
        if ($this->generated !== 'uuid') {
            $this->autoIncrement = true;
            $this->specify('autoIncrement');
        }

        return $this;
    }

    /** PostgreSQL array of the column type ("INTEGER[]"). */
    public function array(bool $array = true): static
    {
        $this->array = $array;

        return $this;
    }

    /** Alter the existing column (Laravel's ->change()): what this definition does not state is kept (class docblock). */
    public function change(bool $change = true): static
    {
        $this->change = $change;

        return $this;
    }
}
