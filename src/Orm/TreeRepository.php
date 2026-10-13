<?php

declare(strict_types=1);

namespace Nesto\Orm;

use Nesto\Orm\Mapping\TreeMetadata;
use Nesto\Orm\Mapping\TypeConverter;

/**
 * Repository of a #[Tree] entity (TypeORM's TreeRepository): `$dataSource->getTreeRepository(Category::class)`,
 * and what getRepository() / #[InjectRepository] return for tree entities.
 *
 *   $tree->findTrees(['depth' => 2, 'relations' => ['owner']]);  // roots with their children nested
 *   $tree->findRoots();                                          // nodes without parent
 *   $tree->findDescendants($node);                               // flat list, the node included
 *   $tree->findDescendantsTree($node, ['depth' => 1]);           // $node with ->children filled
 *   $tree->countDescendants($node);                              // the node included
 *   $tree->findAncestors($node); $tree->findAncestorsTree($node); // ->parent filled up to the root
 *   $tree->createDescendantsQueryBuilder('c', 'closure', $node)->andWhere('c.active = :a', ['a' => true])->getMany();
 *
 * Tree options (TypeORM's FindTreeOptions): `depth` (levels of children; -1 / absent: all) and `relations`.
 * Writes go through save() / remove(), which keep the closure table, nested set or materialized path in step
 * (see Persistence\TreeMaintainer); like TypeORM, insert() / delete() / QueryBuilder writes bypass that bookkeeping.
 * Adjacency-list trees work too (an extension: their queries walk the parent column level by level).
 * Results are ordered by primary key.
 */
class TreeRepository extends Repository
{
    /** @return list<object> every root with its descendants nested (TypeORM's findTrees()) */
    public function findTrees(array $options = []): array
    {
        $tree = $this->tree();
        $nodes = $this->find(['relations' => $options['relations'] ?? [], 'order' => [(string) $this->metadata()->primary->property => 'ASC']]);
        $roots = array_values(array_filter($nodes, fn (object $node) => $this->parentId($node) === null));
        foreach ($roots as $root) {
            $this->buildChildren($root, $nodes, (int) ($options['depth'] ?? -1), $tree);
        }

        return $roots;
    }

    /** @return list<object> nodes without parent (TypeORM's findRoots()) */
    public function findRoots(array $options = []): array
    {
        $parent = (string) $this->tree()->parentProperty;

        return $this->find([
            'where' => [$parent => Operators\IsNull()],
            'relations' => $options['relations'] ?? [],
            'order' => [(string) $this->metadata()->primary->property => 'ASC'],
        ]);
    }

    /** @return list<object> the node and every node below it, flat (TypeORM's findDescendants()) */
    public function findDescendants(object $entity, array $options = []): array
    {
        return $this->loadTreeQuery($this->createDescendantsQueryBuilder('treeEntity', 'treeClosure', $entity), $options);
    }

    /** The given node with its descendants nested in its #[TreeChildren] property (TypeORM's findDescendantsTree()). */
    public function findDescendantsTree(object $entity, array $options = []): object
    {
        $tree = $this->tree();
        $nodes = $this->findDescendants($entity, $options);
        $this->buildChildren($entity, $nodes, (int) ($options['depth'] ?? -1), $tree);

        return $entity;
    }

    public function countDescendants(object $entity): int
    {
        return $this->createDescendantsQueryBuilder('treeEntity', 'treeClosure', $entity)->getCount();
    }

    /**
     * A query for the node and its descendants (TypeORM's createDescendantsQueryBuilder()). $closureTableAlias names
     * the closure table join (closure-table) or the node's own join (nested-set); unused by the other types.
     */
    public function createDescendantsQueryBuilder(string $alias, string $closureTableAlias, object $entity): QueryBuilder
    {
        return $this->treeQuery($alias, $closureTableAlias, $entity, descendants: true);
    }

    /** @return list<object> the node and every node above it, flat (TypeORM's findAncestors()) */
    public function findAncestors(object $entity, array $options = []): array
    {
        return $this->loadTreeQuery($this->createAncestorsQueryBuilder('treeEntity', 'treeClosure', $entity), $options);
    }

    /** The given node with its #[TreeParent] chain filled up to the root (TypeORM's findAncestorsTree()). */
    public function findAncestorsTree(object $entity, array $options = []): object
    {
        $tree = $this->tree();
        $byId = [];
        foreach ($this->findAncestors($entity, $options) as $node) {
            $byId[(string) $this->idOf($node)] = $node;
        }
        $state = $this->getDataSource()->state();
        for ($node = $entity; $node !== null;) {
            $parentId = $this->parentId($node);
            $parent = $parentId === null ? null : ($byId[(string) $parentId] ?? null);
            if ($parent === null && ! Hydrator::allowsNull($node, $tree->parentProperty)) {
                break;
            }
            $node->{$tree->parentProperty} = $parent;
            $state->touchRelation($node, $tree->parentProperty);
            $node = $parent;
        }

        return $entity;
    }

    public function countAncestors(object $entity): int
    {
        return $this->createAncestorsQueryBuilder('treeEntity', 'treeClosure', $entity)->getCount();
    }

    /** A query for the node and its ancestors (TypeORM's createAncestorsQueryBuilder()); see createDescendantsQueryBuilder(). */
    public function createAncestorsQueryBuilder(string $alias, string $closureTableAlias, object $entity): QueryBuilder
    {
        return $this->treeQuery($alias, $closureTableAlias, $entity, descendants: false);
    }

    // ---- internals ------------------------------------------------------------------------------

    private function treeQuery(string $alias, string $joinAlias, object $entity, bool $descendants): QueryBuilder
    {
        $meta = $this->metadata();
        $tree = $this->tree();
        $id = $this->idOf($entity) ?? throw new OrmException("{$meta->class} has no primary key value: save the node before querying its " . ($descendants ? 'descendants' : 'ancestors') . '.');
        $qb = $this->createQueryBuilder($alias);
        $pk = (string) $meta->primary->property;
        $param = 'nesto_tree_' . ($descendants ? 'ancestor' : 'descendant');
        if ($tree->isClosureTable()) {
            [$joinOn, $filter] = $descendants ? [$tree->descendantColumn, $tree->ancestorColumn] : [$tree->ancestorColumn, $tree->descendantColumn];
            $q = $this->getConnection()->quote(...);

            return $qb->innerJoin((string) $tree->closureTable, $joinAlias, $q($joinAlias) . '.' . $q((string) $joinOn) . " = $alias.$pk")
                ->andWhere($q($joinAlias) . '.' . $q((string) $filter) . " = :$param", [$param => $id]);
        }
        if ($tree->isNestedSet()) {
            [$l, $r] = [TreeMetadata::NESTED_SET_LEFT, TreeMetadata::NESTED_SET_RIGHT];
            $on = $descendants
                ? "$alias.$l BETWEEN $joinAlias.$l AND $joinAlias.$r"
                : "$alias.$l <= $joinAlias.$l AND $alias.$r >= $joinAlias.$r";

            return $qb->innerJoin($meta->class, $joinAlias, $on)->andWhere("$joinAlias.$pk = :$param", [$param => $id]);
        }
        if ($tree->isMaterializedPath() && $descendants) {
            $path = $this->getDataSource()->persister()->trees()->path($meta, $id);

            return $qb->andWhere("$alias." . TreeMetadata::MATERIALIZED_PATH . " LIKE :$param ESCAPE '!'", [$param => Persistence\TreeMaintainer::likePrefix($path)]);
        }
        // materialized-path ancestors (the ids are the path) and adjacency lists (walked level by level)
        $trees = $this->getDataSource()->persister()->trees();
        $ids = $descendants ? $trees->descendantIds($meta, $id) : $trees->ancestorIds($meta, $id);

        return $qb->andWhere("$alias.$pk IN (:$param)", [$param => array_map(fn ($v) => TypeConverter::toPhp($meta->primary, $v), $ids)]);
    }

    /** getMany() of a tree query (ordered by key) with the `relations` option loaded. @return list<object> */
    private function loadTreeQuery(QueryBuilder $qb, array $options): array
    {
        $qb->addOrderBy($qb->getAlias() . '.' . $this->metadata()->primary->property);
        $relations = $options['relations'] ?? [];

        return $this->getDataSource()->broadcaster()->loading(function () use ($qb, $relations): array {
            $nodes = $qb->getMany();
            if ($relations !== []) {
                (new FindOptions($this->getDataSource(), $this->metadata(), ['relations' => $relations, 'loadEagerRelations' => false]))->load($nodes);
            }

            return $nodes;
        });
    }

    /**
     * Put the children of $node (found in $nodes by their parent key) into its #[TreeChildren] property, $depth
     * levels deep (-1: all; TypeORM's buildChildrenEntityTree()). Nodes at the depth limit get an empty list that
     * save() treats as "not loaded".
     *
     * @param list<object> $nodes
     */
    private function buildChildren(object $node, array $nodes, int $depth, TreeMetadata $tree): void
    {
        $property = $tree->childrenProperty ?? throw new OrmException("{$this->metadata()->class} needs a #[TreeChildren] property to build trees.");
        $state = $this->getDataSource()->state();
        if ($depth === 0) {
            $node->{$property} = [];
            $state->markDefaultRelations($node, [$property]);

            return;
        }
        $id = (string) $this->idOf($node);
        $children = array_values(array_filter($nodes, fn (object $candidate) => $candidate !== $node && (string) $this->parentId($candidate) === $id && $this->parentId($candidate) !== null));
        $node->{$property} = $children;
        $meta = $this->metadata();
        $state->setRelationIds($node, $property, array_map(fn (object $child) => TypeConverter::toDatabase($meta->primary, $meta->primaryValue($child)), $children));
        foreach ($children as $child) {
            $this->buildChildren($child, $nodes, $depth - 1, $tree);
        }
    }

    /** Primary key of a node as stored (null when unsaved). */
    private function idOf(object $entity): mixed
    {
        $meta = $this->metadata();
        $id = $meta->primaryValue($entity);

        return $id === null ? null : TypeConverter::toDatabase($meta->primary, $id);
    }

    /** The parent key a node points at (its parent entity, foreign key value or loaded row). */
    private function parentId(object $node): mixed
    {
        $meta = $this->metadata();

        return $this->getDataSource()->hydrator()->foreignKeyValue($meta, $node, $meta->treeParentRelation());
    }

    private function tree(): TreeMetadata
    {
        return $this->metadata()->tree ?? throw new OrmException("{$this->entity} is not a tree entity: add #[Tree(...)] and a #[TreeParent] property.");
    }
}
