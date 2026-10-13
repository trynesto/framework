<?php

declare(strict_types=1);

namespace Nesto\Orm\Migration;

use Nesto\Orm\Schema\Comparison\SchemaDiff;
use Nesto\Orm\SqlExpression;

/**
 * Renders SchemaDiff steps as readable migration code over the Schema / Blueprint API:
 *
 *   $schema->createTable('posts', function (Blueprint $table): void {
 *       $table->id();
 *       $table->string('title', 120);
 *       $table->foreign('user_id')->references('id')->on('users')->onDelete('CASCADE');
 *   });
 *   $schema->table('users', function (Blueprint $table): void {
 *       $table->string('email', 320)->change();
 *       $table->dropColumn('legacy');
 *   });
 */
final class MigrationRenderer
{
    private bool $usesSqlExpression = false;

    /** A complete migration class (no namespace, like `migration:create`). */
    public function renderClass(string $class, SchemaDiff $up, SchemaDiff $down, string $comment = ''): string
    {
        $this->usesSqlExpression = false;
        $upCode = $this->renderSteps($up, 2);
        $downCode = $this->renderSteps($down, 2);
        $imports = ['Nesto\Orm\Migration\Blueprint', 'Nesto\Orm\Migration\Migration', 'Nesto\Orm\Migration\Schema'];
        if ($this->usesSqlExpression) {
            $imports[] = SqlExpression::class;
        }
        sort($imports);
        $uses = implode("\n", array_map(fn (string $c) => "use $c;", $imports));

        return <<<PHP
        <?php

        declare(strict_types=1);

        $uses

        /**
         * $comment
         */
        final class $class extends Migration
        {
            public function up(Schema \$schema): void
            {
        $upCode
            }

            public function down(Schema \$schema): void
            {
        $downCode
            }
        }

        PHP;
    }

    /** The statements of one direction, indented by $level × 4 spaces. */
    public function renderSteps(SchemaDiff $diff, int $level = 0): string
    {
        $pad = str_repeat('    ', $level);
        $lines = [];
        foreach ((new DiffPlan($diff))->steps() as $step) {
            $table = self::export($step['table']);
            if ($step['type'] === 'drop') {
                $lines[] = $pad . "\$schema->dropTable($table);";
                continue;
            }
            if ($step['type'] === 'dropView' || $step['type'] === 'createView') {
                $view = $step['view'];
                $materialized = $view->materialized ? ', materialized: true' : '';
                $lines[] = $pad . ($step['type'] === 'dropView'
                    ? "\$schema->dropView($table$materialized);"
                    : "\$schema->createView($table, " . self::export((string) $view->expression) . "$materialized);");
                continue;
            }
            $method = $step['type'] === 'create' ? 'createTable' : 'table';
            $lines[] = $pad . "\$schema->$method($table, function (Blueprint \$table): void {";
            foreach ($this->blueprintLines($step['blueprint'], $step['type'] === 'create') as $line) {
                $lines[] = $pad . '    ' . $line;
            }
            $lines[] = $pad . '});';
        }

        return $lines === [] ? $pad . '// no schema changes' : implode("\n", $lines);
    }

    /** @return list<string> */
    public function blueprintLines(Blueprint $blueprint, bool $create): array
    {
        $lines = [];
        foreach ($blueprint->commands as $command) {
            $lines[] = match ($command['type']) {
                'dropForeign', 'dropIndex', 'dropUnique' => '$table->' . $command['type'] . '(' . self::export($command['name'] ?? $command['columns']) . ');',
                'dropPrimary' => '$table->dropPrimary();',
                'dropCheck' => '$table->dropCheck(' . self::export($command['name']) . ');',
                'renameColumn' => '$table->renameColumn(' . self::export($command['from']) . ', ' . self::export($command['to']) . ');',
                'dropColumn' => '$table->dropColumn(' . implode(', ', array_map(fn ($c) => self::export($c), $command['columns'])) . ');',
                default => '// ' . $command['type'],
            };
        }
        // dropColumn runs after the column changes but reads better last: keep command order otherwise
        $drops = array_values(array_filter($lines, fn (string $l) => str_starts_with($l, '$table->dropColumn(')));
        $lines = array_values(array_filter($lines, fn (string $l) => ! str_starts_with($l, '$table->dropColumn(')));
        foreach ($blueprint->columns as $column) {
            $lines[] = $this->columnLine($column, $create && $blueprint->primaryKeys === []);
        }
        array_push($lines, ...$drops);
        if ($blueprint->primaryKeys !== []) {
            $lines[] = '$table->primary(' . self::export($blueprint->primaryKeys) . ');';
        }
        foreach ($blueprint->indexes as $index) {
            $args = [self::export($index['columns'])];
            if ($index['name'] !== null) {
                $args[] = self::export($index['name']);
            }
            if (($index['where'] ?? null) !== null) {
                $args[] = 'where: ' . self::export($index['where']);
            }
            $lines[] = '$table->' . ($index['unique'] ? 'unique' : 'index') . '(' . implode(', ', $args) . ');';
        }
        foreach ($blueprint->checks as $check) {
            $lines[] = '$table->check(' . self::export($check['expression']) . ($check['name'] !== null ? ', ' . self::export($check['name']) : '') . ');';
        }
        foreach ($blueprint->foreignKeys as $fk) {
            $columns = $fk->constrainedColumns();
            $line = '$table->foreign(' . self::export(count($columns) === 1 ? $columns[0] : $columns)
                . ($fk->constraintName !== null ? ', ' . self::export($fk->constraintName) : '') . ')';
            $referenced = $fk->referencedColumnList();
            $line .= '->references(' . self::export(count($referenced) === 1 ? $referenced[0] : $referenced) . ')';
            $line .= '->on(' . self::export((string) $fk->referencedTable) . ')';
            if ($fk->onDeleteAction !== null) {
                $line .= '->onDelete(' . self::export($fk->onDeleteAction) . ')';
            }
            if ($fk->onUpdateAction !== null) {
                $line .= '->onUpdate(' . self::export($fk->onUpdateAction) . ')';
            }
            $lines[] = $line . ';';
        }

        return $lines;
    }

    private function columnLine(ColumnDefinition $column, bool $inlinePrimary): string
    {
        $name = self::export($column->name);
        $autoPrimary = $column->autoIncrement && $column->primary && $inlinePrimary;
        $call = match (true) {
            $autoPrimary && $column->type === 'bigint' => $column->name === 'id' ? 'id()' : "id($name)",
            $autoPrimary && in_array($column->type, ['int', 'integer'], true) => $column->name === 'id' ? 'increments()' : "increments($name)",
            in_array($column->type, ['string', 'varchar'], true) => 'string(' . $name . ($column->length !== null && $column->length !== 255 ? ', ' . $column->length : '') . ')',
            $column->type === 'char' => "char($name, " . ($column->length ?? 1) . ')',
            $column->type === 'text' => "text($name)",
            $column->type === 'mediumtext' => "mediumText($name)",
            $column->type === 'longtext' => "longText($name)",
            in_array($column->type, ['int', 'integer'], true) => "integer($name)",
            $column->type === 'bigint' => "bigInteger($name)",
            $column->type === 'smallint' => "smallInteger($name)",
            $column->type === 'tinyint' => "tinyInteger($name)",
            $column->type === 'float' => "float($name)",
            $column->type === 'double' => "double($name)",
            in_array($column->type, ['decimal', 'numeric'], true) => "decimal($name, " . ($column->precision ?? 10) . ', ' . ($column->scale ?? 2) . ')',
            in_array($column->type, ['bool', 'boolean'], true) => "boolean($name)",
            $column->type === 'date' => "date($name)",
            in_array($column->type, ['time', 'datetime', 'timestamp'], true) => $column->type . "($name" . ($column->precision !== null ? ', ' . $column->precision : '') . ')',
            $column->type === 'timestamptz' => "timestampTz($name" . ($column->precision !== null ? ', ' . $column->precision : '') . ')',
            $column->type === 'json' => "json($name)",
            $column->type === 'jsonb' => "jsonb($name)",
            $column->type === 'uuid' => "uuid($name)",
            in_array($column->type, ['enum', 'simple-enum'], true) && $column->values !== [] => "enum($name, " . self::export($column->values) . ')',
            $column->type === 'blob' => "binary($name)",
            $column->type === 'binary' && $column->length !== null => "binary($name, {$column->length})",
            default => "column($name, " . self::export($column->type)
                . ($column->length !== null || $column->precision !== null || $column->scale !== null ? ', ' . self::export($column->length) : '')
                . ($column->precision !== null || $column->scale !== null ? ', ' . self::export($column->precision) : '')
                . ($column->scale !== null ? ', ' . self::export($column->scale) : '') . ')',
        };
        $line = '$table->' . $call;
        if ($column->array) {
            $line .= '->array()';
        }
        if ($column->identity !== null) {
            $line .= '->generatedAs(' . self::export($column->identity) . ')';
        } elseif ($column->generated !== null && ! $autoPrimary) {
            $line .= '->generated(' . self::export($column->generated) . ')';
        } elseif ($column->autoIncrement && ! $autoPrimary) {
            $line .= '->autoIncrement()';
        }
        if ($column->generatedType !== null && $column->asExpression !== null) {
            $line .= ($column->generatedType === 'VIRTUAL' ? '->virtualAs(' : '->storedAs(') . self::export($column->asExpression) . ')';
        }
        if ($column->primary && $inlinePrimary && ! $autoPrimary) {
            $line .= '->primary()';
        }
        // a ->change() keeps what it does not state (ColumnDefinition::inherit()): reset what the live column had
        $previous = $column->change ? $column->previous : null;
        if ($column->nullable && ! $column->primary) {
            $line .= '->nullable()';
        } elseif ($previous?->nullable) {
            $line .= '->nullable(false)';
        }
        if ($column->unsigned) {
            $line .= '->unsigned()';
        } elseif ($previous?->unsigned) {
            $line .= '->unsigned(false)';
        }
        if ($column->hasDefault && $column->default !== null && ! $column->autoIncrement) {
            $line .= '->default(' . $this->exportDefault($column->default) . ')';
        } elseif ($previous !== null && $previous->hasDefault && $previous->default !== null && ! $previous->autoIncrement) {
            $line .= '->noDefault()';
        }
        if ($previous?->autoIncrement && ! $column->autoIncrement) {
            $line .= '->autoIncrement(false)';
        }
        // (a charset / collation the model leaves open is not reset: the live one is kept, which schema diff accepts)
        if ($column->charset !== null) {
            $line .= '->charset(' . self::export($column->charset) . ')';
        }
        if ($column->collation !== null) {
            $line .= '->collation(' . self::export($column->collation) . ')';
        }
        if ($column->enumName !== null) {
            $line .= '->enumName(' . self::export($column->enumName) . ')';
        }
        if ($column->comment !== null && $column->comment !== '') {
            $line .= '->comment(' . self::export($column->comment) . ')';
        } elseif ($previous !== null && $previous->comment !== null && $previous->comment !== '') {
            $line .= '->comment(null)';
        }
        if ($column->unique) {
            $line .= '->unique()';
        }
        if ($column->change) {
            $line .= '->change()';
        }

        return $line . ';';
    }

    private function exportDefault(mixed $value): string
    {
        if ($value instanceof SqlExpression) {
            $this->usesSqlExpression = true;

            return 'new SqlExpression(' . self::export($value->sql) . ')';
        }
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }
        if ($value instanceof \DateTimeInterface) {
            $value = $value->format('Y-m-d H:i:s');
        }

        return self::export($value);
    }

    /** PHP literal with short array syntax. */
    public static function export(mixed $value): string
    {
        if (is_array($value)) {
            $list = array_is_list($value);
            $items = [];
            foreach ($value as $key => $item) {
                $items[] = ($list ? '' : self::export($key) . ' => ') . self::export($item);
            }

            return '[' . implode(', ', $items) . ']';
        }
        if (is_string($value)) {
            return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
        }
        if (is_float($value)) {
            $string = var_export($value, true);

            return str_contains($string, '.') || str_contains($string, 'E') ? $string : $string . '.0';
        }

        return var_export($value, true) === 'NULL' ? 'null' : var_export($value, true);
    }
}
