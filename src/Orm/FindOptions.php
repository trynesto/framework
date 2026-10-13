<?php

declare(strict_types=1);

namespace Nesto\Orm;

use Nesto\Orm\Mapping\RelationType;

/**
 * TypeORM's FindOptions applied by Repository::find*() / count*():
 *
 *   where                 ['name' => 'Ada'], operators (see Criteria), relation columns ['author' => ['name' => 'Ada']],
 *                         a list of criteria is OR-ed
 *   order                 ['name' => 'ASC', 'author' => ['name' => 'DESC']]   (ASC|DESC|asc|desc|1|-1|['direction' => ...])
 *                         ['note' => ['direction' => 'ASC', 'nulls' => 'first']]   (nulls: first|last|FIRST|LAST)
 *   select                ['id', 'title'] or ['title' => true, 'author' => ['name' => true]]: partial entities
 *                         (primary and foreign keys are always read); selecting a relation loads it
 *   relations             ['posts', 'posts.tags'] or ['posts' => ['tags' => true]]
 *   relationLoadStrategy  'query' (default here: one query per relation level) | 'join' (LEFT JOINs, TypeORM's
 *                         default; MorphTo is always loaded by query). DataSource option of the same name.
 *   loadEagerRelations    false: skip `eager: true` relations
 *   loadRelationIds       true | ['relations' => ['author'], 'disableMixedMap' => true]: relation properties get the
 *                         related id(s) instead of entities ([pk => id] maps with disableMixedMap)
 *   take, skip, withDeleted
 *   lock                  ['mode' => 'optimistic', 'version' => 3] (findOne only) |
 *                         ['mode' => 'pessimistic_write', 'tables' => ['posts'], 'onLocked' => 'nowait'] (see Lock;
 *                         pessimistic modes need a transaction; tables: the entity's table / relation paths)
 *   cache                 true | 60_000 (ms) | ['id' => 'users_admins', 'milliseconds' => 25_000]: query result cache
 *                         (needs the DataSource `cache` option; count() uses the id "<id>-count")
 */
final class FindOptions
{
    public const KEYS = ['where', 'order', 'take', 'skip', 'relations', 'withDeleted', 'select', 'relationLoadStrategy', 'loadEagerRelations', 'loadRelationIds', 'lock', 'cache'];
    public const ALIAS = 'e';

    /** @var array<string, array> relations to load (tree) */
    private readonly array $relations;
    /** @var array<string, mixed> */
    private readonly array $select;
    private readonly string $strategy;
    /** @var array<string, string> relation path => join alias (join strategy) */
    private array $joined = [];

    public function __construct(private readonly DataSource $dataSource, private readonly EntityMetadata $meta, private readonly array $options, private readonly ?QueryRunner $queryRunner = null)
    {
        foreach (array_keys($options) as $key) {
            if (! in_array($key, self::KEYS, true)) {
                throw new OrmException("Unknown find option \"$key\" (expected one of " . implode(', ', self::KEYS) . ').');
            }
        }
        $this->select = self::tree($options['select'] ?? [], true);
        $relations = EagerRelations::merge(self::tree($options['relations'] ?? []), self::selectedRelations($meta, $this->select));
        if (($options['loadEagerRelations'] ?? true) !== false) {
            $relations = EagerRelations::expand($meta, $relations);
        }
        $this->relations = $relations;
        $strategy = $options['relationLoadStrategy'] ?? $dataSource->getOptions()['relationLoadStrategy'] ?? 'query';
        if (! in_array($strategy, ['join', 'query'], true)) {
            throw new OrmException("relationLoadStrategy must be \"join\" or \"query\", got \"$strategy\".");
        }
        $this->strategy = $strategy;
    }

    /** The query for the root entities (with the relation joins of the join strategy). */
    public function queryBuilder(bool $forCount = false): QueryBuilder
    {
        $qb = (new QueryBuilder($this->dataSource, $this->meta->class, self::ALIAS))->setQueryRunner($this->queryRunner);
        if (! empty($this->options['withDeleted'])) {
            $qb->withDeleted();
        }
        $cache = $this->options['cache'] ?? null;
        if (is_array($cache)) {
            isset($cache['id']) ? $qb->cache((string) $cache['id'], isset($cache['milliseconds']) ? (int) $cache['milliseconds'] : null)
                : $qb->cache(isset($cache['milliseconds']) ? (int) $cache['milliseconds'] : true);
        } elseif (is_bool($cache) || is_int($cache)) {
            $qb->cache($cache);
        }
        if (! $forCount && $this->strategy === 'join') {
            $this->join($qb, self::ALIAS, $this->meta, $this->relations, '');
        }
        if (! empty($this->options['where'])) {
            $qb->where($this->options['where']);
        }
        if ($forCount) {
            return $qb;
        }
        $this->applySelect($qb);
        $this->applyOrder($qb, self::ALIAS, $this->meta, $this->options['order'] ?? []);
        if (empty($this->options['order'])) {
            $qb->addOrderBy(self::ALIAS . '.' . $this->meta->primary->property);
        }
        if (isset($this->options['take'])) {
            $qb->take((int) $this->options['take']);
        }
        if (isset($this->options['skip'])) {
            $qb->skip((int) $this->options['skip']);
        }
        if (isset($this->options['lock'])) {
            $lock = Lock::fromOption($this->options['lock']);
            // lock tables name the entity (main alias) or relation paths (their join aliases)
            $tables = $lock->tables === null ? null : array_map(fn (string $t) => $this->joined[$t] ?? $t, $lock->tables);
            $qb->setLock($lock->mode, $lock->version, $tables)->setOnLocked($lock->onLocked);
        }

        return $qb;
    }

    /** Loads what the root query did not: relations (query strategy / MorphTo), relation ids. @param list<object> $entities */
    public function load(array $entities): void
    {
        if ($entities === []) {
            return;
        }
        $withDeleted = ! empty($this->options['withDeleted']);
        $loader = new RelationLoader($this->dataSource);
        if ($this->strategy === 'query') {
            $loader->load($this->meta, $entities, self::paths($this->relations), $withDeleted, $this->select);
        } else {
            $this->loadUnjoined($loader, $this->meta, $entities, $this->relations, $this->select, '', $withDeleted);
        }
        $this->loadRelationIds($entities);
    }

    // ---- relations ------------------------------------------------------------------------------

    /** Join strategy: LEFT JOIN + select every joinable relation of the tree (MorphTo subtrees are left to load()). */
    private function join(QueryBuilder $qb, string $parentAlias, EntityMetadata $meta, array $tree, string $path): void
    {
        foreach ($tree as $name => $sub) {
            $relation = $meta->relation((string) $name) ?? throw new OrmException("Unknown relation \"$name\" on {$meta->class}.");
            if ($relation->type === RelationType::MorphTo) {
                continue;
            }
            $alias = $this->aliasFor($path . $name);
            $qb->leftJoinAndSelect($parentAlias . '.' . $name, $alias);
            $this->joined[$path . $name] = $alias;
            $this->join($qb, $alias, MetadataRegistry::get($relation->target), $sub, $path . $name . '.');
        }
    }

    /** Join strategy: relations under a MorphTo, loaded by query from the joined parents. */
    private function loadUnjoined(RelationLoader $loader, EntityMetadata $meta, array $entities, array $tree, array $select, string $path, bool $withDeleted): void
    {
        foreach ($tree as $name => $sub) {
            $relation = $meta->relation((string) $name);
            $subSelect = is_array($select[$name] ?? null) ? $select[$name] : [];
            if ($relation->type === RelationType::MorphTo) {
                $loader->load($meta, $entities, self::paths([$name => $sub]), $withDeleted, [$name => $subSelect]);
                continue;
            }
            $children = [];
            foreach ($entities as $entity) {
                $value = $entity->{$name} ?? null;
                foreach (is_array($value) ? $value : (is_object($value) ? [$value] : []) as $child) {
                    $children[spl_object_id($child)] = $child;
                }
            }
            if ($children !== [] && $sub !== []) {
                $this->loadUnjoined($loader, MetadataRegistry::get($relation->target), array_values($children), $sub, $subSelect, $path . $name . '.', $withDeleted);
            }
        }
    }

    private function aliasFor(string $path): string
    {
        $alias = self::ALIAS . '__' . str_replace('.', '__', $path);
        if (strlen($alias) > 40) {
            $alias = self::ALIAS . '__j' . count($this->joined);
        }

        return $alias;
    }

    /** Join strategy + `loadRelationIds`: the root keeps the relation ids. @param list<object> $entities */
    private function loadRelationIds(array $entities): void
    {
        $option = $this->options['loadRelationIds'] ?? false;
        if ($option === false || $option === null) {
            return;
        }
        $only = is_array($option) ? ($option['relations'] ?? null) : null;
        $mixedMap = ! (is_array($option) && ! empty($option['disableMixedMap']));
        $loader = new RelationIdLoader($this->dataSource);
        foreach ($this->meta->relations as $name => $relation) {
            if (($only !== null && ! in_array($name, $only, true)) || isset($this->relations[$name])) {
                continue;
            }
            if ($relation->isMorph() || $relation->isThrough()) {
                if ($only !== null) {
                    throw new OrmException("loadRelationIds supports ManyToOne, OneToOne, OneToMany and ManyToMany relations; {$this->meta->class}::\$$name is a {$relation->type->value}.");
                }
                continue;
            }
            $targetMeta = MetadataRegistry::get($relation->target);
            $pk = (string) $targetMeta->primary->property;
            $ids = $loader->ids($this->meta, $relation, $entities);
            foreach ($entities as $entity) {
                $value = $ids[spl_object_id($entity)];
                if (! $mixedMap) {
                    $value = is_array($value) ? array_map(fn ($id) => [$pk => $id], $value) : ($value === null ? null : [$pk => $value]);
                }
                RelationIdLoader::assign($entity, $name, $value);
                if (! $relation->holdsForeignKey()) {
                    $this->dataSource->state()->touchRelation($entity, $name);
                }
            }
        }
    }

    // ---- select / order -------------------------------------------------------------------------

    /** Partial selection of the root (and joined relations): only when some level names columns. */
    private function applySelect(QueryBuilder $qb): void
    {
        if ($this->select === []) {
            return;
        }
        $items = [];
        $this->selectItems($this->meta, $this->select, self::ALIAS, '', $items);
        if ($items !== []) {
            if (! self::namesColumns($this->meta, $this->select)) {
                array_unshift($items, self::ALIAS);
            }
            $qb->select($items);
        }
    }

    /** @param list<string> $items */
    private function selectItems(EntityMetadata $meta, array $select, string $alias, string $path, array &$items): void
    {
        $columns = RelationLoader::columnsFor($meta, null, $select, $path === '' ? self::paths($this->relations) : self::paths(self::subtree($this->relations, $path)));
        foreach ($columns ?? [] as $column) {
            $items[] = $alias . '.' . $column;
        }
        foreach ($select as $key => $sub) {
            $relationPath = $path . $key;
            if (is_array($sub) && isset($this->joined[$relationPath])) {
                $relation = $meta->relation((string) $key);
                $this->selectItems(MetadataRegistry::get($relation->target), $sub, $this->joined[$relationPath], $relationPath . '.', $items);
            }
        }
    }

    private static function namesColumns(EntityMetadata $meta, array $select): bool
    {
        return self::selectedColumns($meta, $select) !== [];
    }

    /**
     * Columns named in a select tree: `prop => true`, and #[Embedded] properties as a whole (`address => true`)
     * or by their own properties (`address => ['city' => true]`).
     *
     * @return list<Mapping\ColumnMetadata>
     */
    public static function selectedColumns(EntityMetadata $meta, array $select, string $prefix = ''): array
    {
        $columns = [];
        foreach ($select as $key => $value) {
            $path = $prefix . $key;
            $column = $meta->column($path);
            if ($column !== null) {
                if ($value === true) {
                    $columns[] = $column;
                }
                continue;
            }
            $embedded = $meta->embeddedColumns($path);
            if ($embedded !== []) {
                array_push($columns, ...(is_array($value) ? self::selectedColumns($meta, $value, $path . '.') : ($value === true ? $embedded : [])));
            }
        }

        return $columns;
    }

    /** `order` with nested relation keys: the relation is joined (or its join reused) and ordered by its column. */
    private function applyOrder(QueryBuilder $qb, string $alias, EntityMetadata $meta, array $order, string $prefix = ''): void
    {
        foreach ($order as $key => $direction) {
            $key = $prefix . $key;
            $plain = is_array($direction) && (isset($direction['direction']) || isset($direction['nulls']));
            if (is_array($direction) && ! $plain && $meta->column($key) === null && $meta->embeddedColumns($key) !== []) {
                $this->applyOrder($qb, $alias, $meta, $direction, $key . '.'); // #[Embedded] columns
                continue;
            }
            $relation = $meta->relation((string) $key);
            if ($relation !== null && is_array($direction) && ! $plain) {
                $joinAlias = $qb->joinedAlias($alias, (string) $key);
                if ($joinAlias === null) {
                    $joinAlias = $alias . '__' . $key;
                    $qb->leftJoin($alias . '.' . $key, $joinAlias);
                }
                $this->applyOrder($qb, $joinAlias, MetadataRegistry::get($relation->target), $direction);
                continue;
            }
            $qb->addOrderBy($alias . '.' . $key, self::direction($direction), is_array($direction) ? ($direction['nulls'] ?? null) : null);
        }
    }

    private static function direction(mixed $direction): string
    {
        if (is_array($direction)) {
            $direction = $direction['direction'] ?? 'ASC';
        }

        return in_array(strtoupper((string) $direction), ['DESC', '-1'], true) ? 'DESC' : 'ASC';
    }

    // ---- trees ----------------------------------------------------------------------------------

    /**
     * ['a', 'b.c'] / ['a' => true, 'b' => ['c' => true]] / mixed → ['a' => [], 'b' => ['c' => []]].
     * With $leaves (select), plain names stay `true` and relation subtrees stay arrays.
     *
     * @return array<string, mixed>
     */
    public static function tree(mixed $value, bool $leaves = false): array
    {
        $tree = [];
        foreach ((array) $value as $key => $item) {
            if (is_int($key)) {
                $parts = explode('.', (string) $item);
                $leaf = array_pop($parts);
                $node = &$tree;
                foreach ($parts as $part) {
                    $node[$part] = is_array($node[$part] ?? null) ? $node[$part] : [];
                    $node = &$node[$part];
                }
                $node[$leaf] = $leaves ? (is_array($node[$leaf] ?? null) ? $node[$leaf] : true) : ($node[$leaf] ?? []);
                unset($node);
                continue;
            }
            if ($item === false || $item === null) {
                continue;
            }
            $tree[$key] = is_array($item) ? self::tree($item, $leaves) : ($leaves ? true : []);
        }

        return $tree;
    }

    /** Relations named in a select tree (they are loaded). @return array<string, array> */
    private static function selectedRelations(EntityMetadata $meta, array $select): array
    {
        $tree = [];
        foreach ($select as $key => $sub) {
            $relation = $meta->relation((string) $key);
            if ($relation === null) {
                continue;
            }
            $tree[$key] = is_array($sub) && $relation->type !== RelationType::MorphTo ? self::selectedRelations(MetadataRegistry::get($relation->target), $sub) : [];
        }

        return $tree;
    }

    /** @param array<string, array> $tree @return list<string> dotted paths */
    public static function paths(array $tree, string $prefix = ''): array
    {
        $paths = [];
        foreach ($tree as $name => $sub) {
            $paths[] = $prefix . $name;
            array_push($paths, ...self::paths($sub, $prefix . $name . '.'));
        }

        return $paths;
    }

    private static function subtree(array $tree, string $path): array
    {
        foreach (explode('.', rtrim($path, '.')) as $part) {
            $tree = $tree[$part] ?? [];
        }

        return $tree;
    }
}
