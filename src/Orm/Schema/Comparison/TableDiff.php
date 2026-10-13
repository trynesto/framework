<?php

declare(strict_types=1);

namespace Nesto\Orm\Schema\Comparison;

use Nesto\Orm\Schema\Check;
use Nesto\Orm\Schema\ForeignKey;
use Nesto\Orm\Schema\Index;
use Nesto\Orm\Schema\Table;
use Nesto\Orm\Schema\Column;

/** What changes between two versions of one table (see Comparator::compareTable()). */
final class TableDiff
{
    /** @var list<Column> */
    public array $addedColumns = [];
    /** @var list<array{0: Column, 1: Column}> [current, wanted] */
    public array $changedColumns = [];
    /** @var list<Column> */
    public array $droppedColumns = [];
    /** @var array{0: list<string>, 1: list<string>}|null [current, wanted] */
    public ?array $primaryKey = null;
    /** @var list<Index> */
    public array $addedIndexes = [];
    /** @var list<Index> */
    public array $droppedIndexes = [];
    /** @var list<ForeignKey> */
    public array $addedForeignKeys = [];
    /** @var list<ForeignKey> */
    public array $droppedForeignKeys = [];
    /** @var list<Check> */
    public array $addedChecks = [];
    /** @var list<Check> */
    public array $droppedChecks = [];

    public function __construct(public readonly string $table, public readonly Table $from, public readonly Table $to) {}

    public function isEmpty(): bool
    {
        return $this->addedColumns === [] && $this->changedColumns === [] && $this->droppedColumns === [] && $this->primaryKey === null
            && $this->addedIndexes === [] && $this->droppedIndexes === [] && $this->addedForeignKeys === [] && $this->droppedForeignKeys === []
            && $this->addedChecks === [] && $this->droppedChecks === [];
    }
}
