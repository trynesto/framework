<?php

declare(strict_types=1);

namespace Nesto\Orm\Persistence;

use Nesto\Orm\Connection;
use Nesto\Orm\DataSource;
use Nesto\Orm\EntityMetadata;
use Nesto\Orm\Mapping\TreeMetadata;
use Nesto\Orm\Mapping\TypeConverter;
use Nesto\Orm\OrmException;

/**
 * Keeps the bookkeeping of #[Tree] entities in step with save() / remove() (TypeORM's closure, nested-set and
 * materialized-path subject executors), and answers id-level tree questions for the TreeRepository:
 *
 *   closure-table      insert: (id, id) + (each ancestor of the parent, id); move: the subtree's links to its old
 *                      ancestors are replaced by links to the new parent's ancestors; remove: the node's links go
 *   nested-set         insert: the parent's right edge opens by 2 (a single root, like TypeORM); remove: the gap
 *                      closes; moving a node is refused (TypeORM does not support it either)
 *   materialized-path  insert: mpath = parent's mpath + id + "."; move: the subtree's paths are rewritten
 *   adjacency-list     only the parent column (queries walk it level by level)
 *
 * A #[TreeLevelColumn] gets the depth on insert (root 0) and is shifted for the whole subtree on a move.
 * Ids are database values (TypeConverter::toDatabase of the primary key).
 */
final class TreeMaintainer
{
    public function __construct(private readonly DataSource $dataSource) {}

    // ---- save -----------------------------------------------------------------------------------

    /**
     * Before the INSERT of a node: its level and its nested-set bounds (other nodes shift to make room).
     *
     * @param array<string, mixed> $row the row about to be inserted
     * @return array<string, mixed> the row with the tree columns
     */
    public function beforeInsert(EntityMetadata $meta, object $entity, array $row): array
    {
        $tree = $meta->tree;
        $parentId = $row[(string) $meta->treeParentRelation()->joinColumn] ?? null;
        if ($tree->levelProperty !== null) {
            $level = $parentId === null ? 0 : $this->level($meta, $parentId) + 1;
            $entity->{$tree->levelProperty} = $level;
            $row[$meta->columnName($tree->levelProperty)] = $level;
        }
        if (! $tree->isNestedSet()) {
            return $row;
        }
        $conn = $this->conn();
        $table = $conn->quote($meta->table);
        [$left, $right] = [$conn->quote(TreeMetadata::NESTED_SET_LEFT), $conn->quote(TreeMetadata::NESTED_SET_RIGHT)];
        if ($parentId === null) {
            $parentColumn = $conn->quote((string) $meta->treeParentRelation()->joinColumn);
            if ((int) $conn->fetchColumn("SELECT COUNT(*) FROM $table WHERE $parentColumn IS NULL") > 0) {
                throw new OrmException("{$meta->class} is a nested-set tree, which has a single root (like TypeORM): give the new node a parent, or use a closure-table / materialized-path tree for several roots.");
            }
            $row[TreeMetadata::NESTED_SET_LEFT] = 1;
            $row[TreeMetadata::NESTED_SET_RIGHT] = 2;

            return $row;
        }
        $parentRight = $conn->fetchColumn("SELECT $right FROM $table WHERE " . $this->pk($meta) . ' = :id', ['id' => $parentId])
            ?? throw new OrmException("{$meta->class}: the parent node {$parentId} does not exist.");
        $parentRight = (int) $parentRight;
        $conn->execute(
            "UPDATE $table SET $left = CASE WHEN $left > :r1 THEN $left + 2 ELSE $left END, $right = CASE WHEN $right >= :r2 THEN $right + 2 ELSE $right END WHERE $right >= :r3",
            ['r1' => $parentRight, 'r2' => $parentRight, 'r3' => $parentRight],
        );
        $row[TreeMetadata::NESTED_SET_LEFT] = $parentRight;
        $row[TreeMetadata::NESTED_SET_RIGHT] = $parentRight + 1;

        return $row;
    }

    /**
     * After the INSERT of a node (its key is known): closure links, materialized path.
     *
     * @param array<string, mixed> $row the inserted row, primary key included
     * @return array<string, mixed> tree column values to remember in the entity's snapshot
     */
    public function afterInsert(EntityMetadata $meta, array $row): array
    {
        $tree = $meta->tree;
        $id = $row[$meta->primary->name];
        $parentId = $row[(string) $meta->treeParentRelation()->joinColumn] ?? null;
        if ($tree->isClosureTable()) {
            $ancestors = $parentId === null ? [] : $this->closureColumn($meta, (string) $tree->ancestorColumn, (string) $tree->descendantColumn, [$parentId]);
            $this->insertClosure($meta, array_map(fn ($ancestor) => [$ancestor, $id], [$id, ...$ancestors]));
        }
        if ($tree->isMaterializedPath()) {
            $path = ($parentId === null ? '' : $this->path($meta, $parentId)) . $id . '.';
            $conn = $this->conn();
            $conn->execute('UPDATE ' . $conn->quote($meta->table) . ' SET ' . $conn->quote(TreeMetadata::MATERIALIZED_PATH) . ' = :path WHERE ' . $this->pk($meta) . ' = :id', ['path' => $path, 'id' => $id]);

            return [TreeMetadata::MATERIALIZED_PATH => $path];
        }

        return [];
    }

    /**
     * A node whose parent changes (before its UPDATE): refuses nested-set moves and cycles, and puts the new
     * level on the entity. Null when the parent does not change.
     *
     * @param array<string, mixed>|null $snapshot
     * @param array<string, mixed>      $changes
     * @return array{id: mixed, from: mixed, to: mixed, levelDelta: int}|null
     */
    public function prepareMove(EntityMetadata $meta, object $entity, ?array $snapshot, array $changes): ?array
    {
        $column = (string) $meta->treeParentRelation()->joinColumn;
        if (! array_key_exists($column, $changes)) {
            return null;
        }
        $tree = $meta->tree;
        $id = TypeConverter::toDatabase($meta->primary, $meta->primaryValue($entity));
        $to = $changes[$column];
        if ($tree->isNestedSet()) {
            throw new OrmException("{$meta->class} is a nested-set tree: its nodes cannot be moved to another parent (TypeORM does not support it either). Use a closure-table or materialized-path tree to move nodes.");
        }
        if ($to !== null && in_array((string) $id, array_map('strval', $this->ancestorIds($meta, $to)), true)) {
            throw new OrmException("{$meta->class} $id cannot move below " . ($to == $id ? 'itself' : "its own descendant $to") . '.');
        }
        $delta = 0;
        if ($tree->levelProperty !== null) {
            $old = (int) $this->level($meta, $id);
            $new = $to === null ? 0 : $this->level($meta, $to) + 1;
            $delta = $new - $old;
            $entity->{$tree->levelProperty} = $new;
        }

        return ['id' => $id, 'from' => $snapshot[$column] ?? null, 'to' => $to, 'levelDelta' => $delta];
    }

    /**
     * After the UPDATE of a moved node: closure links / paths of its subtree, levels of its descendants.
     *
     * @param array{id: mixed, from: mixed, to: mixed, levelDelta: int} $move
     * @return array<string, mixed> tree column values to remember in the entity's snapshot
     */
    public function move(EntityMetadata $meta, array $move): array
    {
        $tree = $meta->tree;
        $conn = $this->conn();
        $id = $move['id'];
        $snapshot = [];
        if ($tree->isClosureTable()) {
            [$anc, $desc] = [(string) $tree->ancestorColumn, (string) $tree->descendantColumn];
            $subtree = $this->closureColumn($meta, $desc, $anc, [$id]);
            $oldAncestors = array_values(array_filter($this->closureColumn($meta, $anc, $desc, [$id]), fn ($a) => (string) $a !== (string) $id));
            if ($oldAncestors !== []) {
                $conn->execute(
                    'DELETE FROM ' . $conn->quote((string) $tree->closureTable) . ' WHERE ' . $conn->quote($desc) . ' IN (:subtree) AND ' . $conn->quote($anc) . ' IN (:ancestors)',
                    ['subtree' => $subtree, 'ancestors' => $oldAncestors],
                );
            }
            $newAncestors = $move['to'] === null ? [] : $this->closureColumn($meta, $anc, $desc, [$move['to']]);
            $links = [];
            foreach ($newAncestors as $ancestor) {
                foreach ($subtree as $node) {
                    $links[] = [$ancestor, $node];
                }
            }
            $this->insertClosure($meta, $links);
        }
        if ($tree->isMaterializedPath()) {
            $oldPath = $this->path($meta, $id);
            $newPath = ($move['to'] === null ? '' : $this->path($meta, $move['to'])) . $id . '.';
            $column = $conn->quote(TreeMetadata::MATERIALIZED_PATH);
            $rest = 'SUBSTR(' . $column . ', :start)';
            $conn->execute(
                'UPDATE ' . $conn->quote($meta->table) . ' SET ' . $column . ' = ' . ($conn->driver() === 'mysql' ? "CONCAT(:path, $rest)" : ":path || $rest")
                    . ' WHERE ' . $column . " LIKE :old ESCAPE '!'",
                ['path' => $newPath, 'start' => strlen($oldPath) + 1, 'old' => self::likePrefix($oldPath)],
            );
            $snapshot[TreeMetadata::MATERIALIZED_PATH] = $newPath;
        }
        if ($tree->levelProperty !== null && $move['levelDelta'] !== 0) {
            $descendants = array_values(array_filter($this->descendantIds($meta, $id), fn ($d) => (string) $d !== (string) $id));
            if ($descendants !== []) {
                $level = $conn->quote($meta->columnName($tree->levelProperty));
                $conn->execute(
                    'UPDATE ' . $conn->quote($meta->table) . " SET $level = $level + :delta WHERE " . $this->pk($meta) . ' IN (:ids)',
                    ['delta' => $move['levelDelta'], 'ids' => $descendants],
                );
            }
        }

        return $snapshot;
    }

    // ---- remove ---------------------------------------------------------------------------------

    /**
     * Before the DELETE of a node: drops its closure links; returns what afterRemove() needs (nested-set bounds).
     *
     * @return array{0: int, 1: int}|null
     */
    public function beforeRemove(EntityMetadata $meta, mixed $id): ?array
    {
        $tree = $meta->tree;
        $conn = $this->conn();
        if ($tree->isClosureTable()) {
            $conn->execute(
                'DELETE FROM ' . $conn->quote((string) $tree->closureTable) . ' WHERE ' . $conn->quote((string) $tree->descendantColumn) . ' = :id1 OR ' . $conn->quote((string) $tree->ancestorColumn) . ' = :id2',
                ['id1' => $id, 'id2' => $id],
            );
        }
        if (! $tree->isNestedSet()) {
            return null;
        }
        $bounds = $conn->fetchOne(
            'SELECT ' . $conn->quote(TreeMetadata::NESTED_SET_LEFT) . ' AS l, ' . $conn->quote(TreeMetadata::NESTED_SET_RIGHT) . ' AS r FROM ' . $conn->quote($meta->table) . ' WHERE ' . $this->pk($meta) . ' = :id',
            ['id' => $id],
        );

        return $bounds === null ? null : [(int) $bounds['l'], (int) $bounds['r']];
    }

    /** After the DELETE of a nested-set node (and, through the foreign key, its subtree): close the gap. @param array{0: int, 1: int}|null $bounds */
    public function afterRemove(EntityMetadata $meta, ?array $bounds): void
    {
        if ($bounds === null) {
            return;
        }
        [$l, $r] = $bounds;
        $width = $r - $l + 1;
        $conn = $this->conn();
        [$left, $right] = [$conn->quote(TreeMetadata::NESTED_SET_LEFT), $conn->quote(TreeMetadata::NESTED_SET_RIGHT)];
        $conn->execute(
            'UPDATE ' . $conn->quote($meta->table) . " SET $left = CASE WHEN $left > :r1 THEN $left - :w1 ELSE $left END, $right = CASE WHEN $right > :r2 THEN $right - :w2 ELSE $right END WHERE $right > :r3",
            ['r1' => $r, 'r2' => $r, 'r3' => $r, 'w1' => $width, 'w2' => $width],
        );
    }

    // ---- id queries (TreeRepository) ------------------------------------------------------------

    /** @return list<mixed> ids of the node and every node below it */
    public function descendantIds(EntityMetadata $meta, mixed $id): array
    {
        $tree = $meta->tree;
        $conn = $this->conn();
        $table = $conn->quote($meta->table);
        $pk = $this->pk($meta);

        return match ($tree->type) {
            'closure-table' => $this->closureColumn($meta, (string) $tree->descendantColumn, (string) $tree->ancestorColumn, [$id]),
            'nested-set' => array_column($conn->fetchAll(
                "SELECT t.$pk AS id FROM $table t INNER JOIN $table n ON t." . $conn->quote(TreeMetadata::NESTED_SET_LEFT) . ' BETWEEN n.' . $conn->quote(TreeMetadata::NESTED_SET_LEFT) . ' AND n.' . $conn->quote(TreeMetadata::NESTED_SET_RIGHT) . " WHERE n.$pk = :id",
                ['id' => $id],
            ), 'id'),
            'materialized-path' => array_column($conn->fetchAll(
                "SELECT $pk AS id FROM $table WHERE " . $conn->quote(TreeMetadata::MATERIALIZED_PATH) . " LIKE :path ESCAPE '!'",
                ['path' => self::likePrefix($this->path($meta, $id))],
            ), 'id'),
            default => $this->walkDown($meta, $id),
        };
    }

    /** @return list<mixed> ids of the node and every node above it */
    public function ancestorIds(EntityMetadata $meta, mixed $id): array
    {
        $tree = $meta->tree;
        $conn = $this->conn();
        $table = $conn->quote($meta->table);
        $pk = $this->pk($meta);

        return match ($tree->type) {
            'closure-table' => $this->closureColumn($meta, (string) $tree->ancestorColumn, (string) $tree->descendantColumn, [$id]),
            'nested-set' => array_column($conn->fetchAll(
                "SELECT t.$pk AS id FROM $table t INNER JOIN $table n ON t." . $conn->quote(TreeMetadata::NESTED_SET_LEFT) . ' <= n.' . $conn->quote(TreeMetadata::NESTED_SET_LEFT)
                    . ' AND t.' . $conn->quote(TreeMetadata::NESTED_SET_RIGHT) . ' >= n.' . $conn->quote(TreeMetadata::NESTED_SET_RIGHT) . " WHERE n.$pk = :id",
                ['id' => $id],
            ), 'id'),
            'materialized-path' => array_values(array_filter(explode('.', $this->path($meta, $id)), fn (string $part) => $part !== '')),
            default => $this->walkUp($meta, $id),
        };
    }

    /** Materialized path of a node ("1.4.9."); "" when it has none. */
    public function path(EntityMetadata $meta, mixed $id): string
    {
        $conn = $this->conn();

        return (string) $conn->fetchColumn(
            'SELECT ' . $conn->quote(TreeMetadata::MATERIALIZED_PATH) . ' FROM ' . $conn->quote($meta->table) . ' WHERE ' . $this->pk($meta) . ' = :id',
            ['id' => $id],
        );
    }

    /** LIKE pattern matching strings starting with $prefix ("!" escapes; use with ESCAPE '!'). */
    public static function likePrefix(string $prefix): string
    {
        return strtr($prefix, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    }

    // ---- helpers --------------------------------------------------------------------------------

    /** Adjacency list: the node and its descendants, one query per level. @return list<mixed> */
    private function walkDown(EntityMetadata $meta, mixed $id): array
    {
        $conn = $this->conn();
        $sql = 'SELECT ' . $this->pk($meta) . ' AS id FROM ' . $conn->quote($meta->table) . ' WHERE ' . $conn->quote((string) $meta->treeParentRelation()->joinColumn) . ' IN (:ids)';
        $all = [(string) $id => $id];
        $level = [$id];
        while ($level !== []) {
            $next = [];
            foreach (array_column($conn->fetchAll($sql, ['ids' => $level]), 'id') as $child) {
                if (! isset($all[(string) $child])) { // a cycle in hand-written data would loop forever
                    $all[(string) $child] = $next[] = $child;
                }
            }
            $level = $next;
        }

        return array_values($all);
    }

    /** Adjacency list: the node and its ancestors, one query per level. @return list<mixed> */
    private function walkUp(EntityMetadata $meta, mixed $id): array
    {
        $conn = $this->conn();
        $sql = 'SELECT ' . $conn->quote((string) $meta->treeParentRelation()->joinColumn) . ' FROM ' . $conn->quote($meta->table) . ' WHERE ' . $this->pk($meta) . ' = :id';
        $all = [];
        for ($current = $id; $current !== null && ! isset($all[(string) $current]); $current = $conn->fetchColumn($sql, ['id' => $current])) {
            $all[(string) $current] = $current;
        }

        return array_values($all);
    }

    /** @param list<mixed> $ids @return list<mixed> closure table $select column of the rows whose $where column is in $ids */
    private function closureColumn(EntityMetadata $meta, string $select, string $where, array $ids): array
    {
        $conn = $this->conn();

        return array_column($conn->fetchAll(
            'SELECT ' . $conn->quote($select) . ' AS v FROM ' . $conn->quote((string) $meta->tree->closureTable) . ' WHERE ' . $conn->quote($where) . ' IN (:ids)',
            ['ids' => $ids],
        ), 'v');
    }

    /** @param list<array{0: mixed, 1: mixed}> $links [ancestor, descendant] pairs */
    private function insertClosure(EntityMetadata $meta, array $links): void
    {
        $conn = $this->conn();
        $tree = $meta->tree;
        foreach (array_chunk($links, 200) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $i => [$ancestor, $descendant]) {
                $values[] = "(:a$i, :d$i)";
                $params["a$i"] = $ancestor;
                $params["d$i"] = $descendant;
            }
            $conn->execute(
                'INSERT INTO ' . $conn->quote((string) $tree->closureTable) . ' (' . $conn->quote((string) $tree->ancestorColumn) . ', ' . $conn->quote((string) $tree->descendantColumn) . ') VALUES ' . implode(', ', $values),
                $params,
            );
        }
    }

    private function level(EntityMetadata $meta, mixed $id): int
    {
        $conn = $this->conn();

        return (int) $conn->fetchColumn(
            'SELECT ' . $conn->quote($meta->columnName((string) $meta->tree->levelProperty)) . ' FROM ' . $conn->quote($meta->table) . ' WHERE ' . $this->pk($meta) . ' = :id',
            ['id' => $id],
        );
    }

    private function pk(EntityMetadata $meta): string
    {
        return $this->conn()->quote($meta->primary->name);
    }

    private function conn(): Connection
    {
        return $this->dataSource->getConnection();
    }
}
