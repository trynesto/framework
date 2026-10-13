<?php

declare(strict_types=1);

namespace Nesto\Orm\Schema\Comparison;

use Nesto\Orm\Schema\Table;
use Nesto\Orm\Schema\View;

/**
 * The result of comparing two schema models (Comparator::compare() / compareViews()): tables to create,
 * per-table differences (TableDiff), tables to drop, and views to drop / (re)create. Pure data: Migration\DiffPlan turns it into ordered
 * Schema / Blueprint steps (to run, to print as SQL, or to render as a migration class).
 */
final class SchemaDiff
{
    /** @var list<Table> */
    public array $created = [];
    /** @var list<TableDiff> */
    public array $altered = [];
    /** @var list<Table> */
    public array $dropped = [];
    /** @var list<View> views to create, in dependency order (new ones and changed ones, recreated) */
    public array $createdViews = [];
    /** @var list<View> views to drop first, dependents before what they depend on (removed and changed ones) */
    public array $droppedViews = [];

    public function __construct(public readonly string $platform) {}

    public function isEmpty(): bool
    {
        return $this->created === [] && $this->altered === [] && $this->dropped === [] && $this->createdViews === [] && $this->droppedViews === [];
    }
}
