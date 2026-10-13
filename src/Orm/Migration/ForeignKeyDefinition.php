<?php

declare(strict_types=1);

namespace Nesto\Orm\Migration;

/**
 * A column that is also a foreign key, or (Blueprint::foreign()) a constraint on existing columns.
 *
 *   $table->foreignId('user_id')->references('id')->on('users')->onDelete('CASCADE');
 *   $table->foreignId('user_id')->constrained();                     // → users.id
 *   $table->foreign('author_id')->references('id')->on('users');     // constraint only
 *   $table->foreign(['a', 'b'], 'fk_pairs')->references(['x', 'y'])->on('pairs')->onUpdate('CASCADE');
 */
final class ForeignKeyDefinition extends ColumnDefinition
{
    public ?string $referencedTable = null;
    public string $referencedColumn = 'id';
    public ?string $onDeleteAction = null;
    public ?string $onUpdateAction = null;
    public bool $constraintOnly = false;
    /** @var list<string> constrained columns (default: [$name]) */
    public array $columns = [];
    /** @var list<string> referenced columns (default: [$referencedColumn]) */
    public array $referencedColumns = [];
    public ?string $constraintName = null;

    /** @param string|list<string> $column */
    public function references(string|array $column): static
    {
        if (is_array($column)) {
            $this->referencedColumns = array_values($column);
            $this->referencedColumn = (string) ($column[0] ?? 'id');
        } else {
            $this->referencedColumn = $column;
            $this->referencedColumns = [];
        }

        return $this;
    }

    public function on(string $table): static
    {
        $this->referencedTable = $table;

        return $this;
    }

    /** Infer the table from the column name: `user_id` → `users`. */
    public function constrained(?string $table = null, string $column = 'id'): static
    {
        $this->referencedTable = $table ?? \Nesto\Orm\Mapping\Naming::plural((string) preg_replace('/_id$/', '', $this->name));
        $this->referencedColumn = $column;

        return $this;
    }

    /** CASCADE | SET NULL | RESTRICT | NO ACTION | SET DEFAULT ("DEFAULT" means SET DEFAULT). */
    public function onDelete(?string $action): static
    {
        $this->onDeleteAction = self::action($action);

        return $this;
    }

    public function onUpdate(?string $action): static
    {
        $this->onUpdateAction = self::action($action);

        return $this;
    }

    public function cascadeOnDelete(): static
    {
        return $this->onDelete('CASCADE');
    }

    public function nullOnDelete(): static
    {
        return $this->onDelete('SET NULL');
    }

    public function cascadeOnUpdate(): static
    {
        return $this->onUpdate('CASCADE');
    }

    /** Name of the FOREIGN KEY constraint (default: chosen by the database). */
    public function constraintName(?string $name): static
    {
        $this->constraintName = $name;

        return $this;
    }

    /** @return list<string> */
    public function constrainedColumns(): array
    {
        return $this->columns !== [] ? $this->columns : [$this->name];
    }

    /** @return list<string> */
    public function referencedColumnList(): array
    {
        return $this->referencedColumns !== [] ? $this->referencedColumns : [$this->referencedColumn];
    }

    /** Normalized referential action (null for none). */
    public static function action(?string $action): ?string
    {
        if ($action === null || trim($action) === '') {
            return null;
        }
        $action = strtoupper((string) preg_replace('/\s+/', ' ', trim($action)));

        return $action === 'DEFAULT' ? 'SET DEFAULT' : $action;
    }
}
