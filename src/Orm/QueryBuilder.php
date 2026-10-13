<?php

declare(strict_types=1);

namespace Nesto\Orm;

use Closure;
use Nesto\Orm\Mapping\RelationMetadata;
use Nesto\Orm\Mapping\RelationType;
use Nesto\Orm\Mapping\ThroughPath;
use Nesto\Orm\Mapping\EmbeddedValues;
use Nesto\Orm\Mapping\RelationValues;
use Nesto\Orm\Mapping\TypeConverter;

/**
 * TypeORM-style query builder on top of Connection. Works for entities (hydrated results, relation
 * joins, soft-delete awareness) and for raw tables.
 *
 *   $users = $repo->createQueryBuilder('u')
 *       ->leftJoinAndSelect('u.posts', 'p')
 *       ->where('u.active = :active', ['active' => true])
 *       ->andWhere(['role' => ['In', ['admin', 'editor']]])
 *       ->orderBy('u.name')->take(10)->skip(20)
 *       ->getMany();
 *
 * Every relation type can be joined (polymorphic joins add the type condition, through relations join the
 * intermediate tables) except MorphTo, whose target table depends on the row: load it with `relations`.
 * Like TypeORM, the query builder never loads `eager` relations.
 *
 *   $qb->leftJoinAndMapOne('u.profilePhoto', 'u.photos', 'ph', 'ph.isProfile = :y', ['y' => true]);  // + *AndMapMany
 *   $qb->loadRelationCountAndMap('u.postCount', 'u.posts');  $qb->loadRelationIdAndMap('u.tagIds', 'u.tags');
 *   $qb->select(['u.name'])->getMany();                       // partial entities (keys always selected)
 *   $qb->addSelect('u.password')->getMany();                  // + a select: false column (the alias stays selected)
 *   $qb->where(['age' => MoreThan(18)])->orderBy('u.address.city'); // find operators, #[Embedded] paths
 *   $qb->where(['author' => ['name' => 'Ada']]);              // relation columns: LEFT JOIN (reused if joined)
 *   $qb->orderBy('u.lastSeen', 'DESC', 'NULLS LAST');         // MySQL / MariaDB: emulated with ISNULL()
 *   $qb->update()->set(['author' => $ada])->where(...);       // relations: an entity, its key or ['id' => 5]
 *   $qb->relation(Post::class, 'tags')->of($post)->add($tag); // RelationQueryBuilder
 *
 *   $qb->insert()->into(User::class)->values(['name' => 'Ada'])->execute();
 *   $qb->update(User::class)->set(['active' => false])->where('id = :id', ['id' => 3])->execute();
 *   $qb->delete()->from(User::class)->where(['active' => false])->execute();
 *
 * Property names in `alias.property` references are translated to column names and quoted per driver.
 */
final class QueryBuilder
{
    private string $type = 'select';
    private ?string $table = null;
    private ?string $alias = null;
    private ?EntityMetadata $meta = null;
    /** @var list<string> */
    private array $selects = [];
    /** The entity alias is selected besides addSelect() items until select() replaces the selection (TypeORM). */
    private bool $selectsAlias = true;
    private bool $distinct = false;
    /** @var list<array{type: string, table: string, alias: string, condition: string, meta: ?EntityMetadata, parentAlias: ?string, relation: ?RelationMetadata, select: bool}> */
    private array $joins = [];
    /** @var list<array{0: string, 1: string}> */
    private array $wheres = [];
    /** @var list<array{0: string, 1: string}> */
    private array $havings = [];
    /** @var array<string, mixed> */
    private array $params = [];
    /** @var array<string, string> */
    private array $orderBy = [];
    /** @var list<string> */
    private array $groupBy = [];
    private ?int $limit = null;
    private ?int $offset = null;
    private bool $withDeleted = false;
    /** @var array<string, EntityMetadata> */
    private array $aliasMeta = [];
    /** @var list<array<string, mixed>> */
    private array $insertRows = [];
    /** @var array<string, mixed> */
    private array $updateSet = [];
    private int $paramCounter = 0;
    /** @var list<array{0: string, 1: string, 2: string, 3: RelationMetadata, 4: ?Closure, 5: string}> loadRelationIdAndMap / CountAndMap */
    private array $relationMaps = [];
    private ?Lock $lock = null;
    private ?string $onLocked = null;
    private bool $callListeners = true;
    /** TypeORM's setQueryRunner(): the runner (and so the connection / replication node) the query runs on. */
    private ?QueryRunner $queryRunner = null;
    /** cache(): true / false, or null (only the `cache.alwaysEnabled` option decides). */
    private ?bool $cache = null;
    private ?string $cacheId = null;
    private ?int $cacheDuration = null;

    public function __construct(
        private readonly DataSource $dataSource,
        ?string $entityOrTable = null,
        ?string $alias = null,
    ) {
        if ($entityOrTable !== null) {
            $this->from($entityOrTable, $alias);
        }
    }

    // ---- from / select --------------------------------------------------------------------------

    /** Entity class or raw table name. */
    public function from(string $entityOrTable, ?string $alias = null): self
    {
        if (MetadataRegistry::has($entityOrTable)) {
            $this->meta = MetadataRegistry::get($entityOrTable);
            $this->table = $this->meta->table;
        } else {
            $this->meta = null;
            $this->table = $entityOrTable;
        }
        $this->alias = $alias ?? $this->table;
        if ($this->meta !== null) {
            $this->aliasMeta[$this->alias] = $this->meta;
        }

        return $this;
    }

    /** `select('u')`, `select('u.id, u.name')`, `select(['u.id', 'COUNT(p.id) AS total'])`. Replaces the selection. */
    public function select(string|array|null $selection = null): self
    {
        $this->selects = [];
        $this->selectsAlias = $selection === null;

        return $selection === null ? $this : $this->addSelect($selection);
    }

    public function addSelect(string|array $selection): self
    {
        foreach ((array) $selection as $item) {
            foreach ($this->splitTopLevel($item) as $expr) {
                $this->selects[] = trim($expr);
            }
        }

        return $this;
    }

    public function distinct(bool $distinct = true): self
    {
        $this->distinct = $distinct;

        return $this;
    }

    // ---- where ----------------------------------------------------------------------------------

    /** Replaces previous conditions. String with :named params or the array shorthand (see Criteria). */
    public function where(string|array $condition, array $params = []): self
    {
        $this->wheres = [];

        return $this->andWhere($condition, $params);
    }

    public function andWhere(string|array $condition, array $params = []): self
    {
        $this->wheres[] = ['AND', $this->compileCondition($condition)];

        return $this->setParameters($params);
    }

    public function orWhere(string|array $condition, array $params = []): self
    {
        $this->wheres[] = ['OR', $this->compileCondition($condition)];

        return $this->setParameters($params);
    }

    /** @param list<mixed> $values */
    public function whereIn(string $column, array $values): self
    {
        return $this->andWhere([$column => ['In', $values]]);
    }

    /** @param list<mixed> $values */
    public function whereNotIn(string $column, array $values): self
    {
        return $this->andWhere([$column => ['NotIn', $values]]);
    }

    public function whereNull(string $column): self
    {
        return $this->andWhere($this->ref($column) . ' IS NULL');
    }

    public function whereNotNull(string $column): self
    {
        return $this->andWhere($this->ref($column) . ' IS NOT NULL');
    }

    public function setParameter(string $name, mixed $value): self
    {
        $this->params[ltrim($name, ':')] = $value;

        return $this;
    }

    /** @param array<string, mixed> $params */
    public function setParameters(array $params): self
    {
        foreach ($params as $name => $value) {
            $this->setParameter((string) $name, $value);
        }

        return $this;
    }

    // ---- joins ----------------------------------------------------------------------------------

    /** `innerJoin('u.posts', 'p')` (relation) or `innerJoin('posts', 'p', 'p.user_id = u.id')` (table). */
    public function innerJoin(string $relationOrTable, string $alias, ?string $condition = null, array $params = []): self
    {
        return $this->join('INNER', $relationOrTable, $alias, $condition, false, $params);
    }

    public function leftJoin(string $relationOrTable, string $alias, ?string $condition = null, array $params = []): self
    {
        return $this->join('LEFT', $relationOrTable, $alias, $condition, false, $params);
    }

    /** Joins a relation and hydrates it onto the parent entity. */
    public function innerJoinAndSelect(string $relation, string $alias, ?string $condition = null, array $params = []): self
    {
        return $this->join('INNER', $relation, $alias, $condition, true, $params);
    }

    public function leftJoinAndSelect(string $relation, string $alias, ?string $condition = null, array $params = []): self
    {
        return $this->join('LEFT', $relation, $alias, $condition, true, $params);
    }

    /**
     * Joins a relation ("u.photos") or an entity class (with an ON condition) and maps the FIRST match onto
     * a property of the parent ("u.profilePhoto"), TypeORM's leftJoinAndMapOne():
     *
     *   $qb->leftJoinAndMapOne('u.profilePhoto', 'u.photos', 'photo', 'photo.isProfile = :yes', ['yes' => true]);
     *   $qb->leftJoinAndMapOne('u.latestPost', Post::class, 'lp', 'lp.user = u.id');
     */
    public function leftJoinAndMapOne(string $mapToProperty, string $relationOrEntity, string $alias, ?string $condition = null, array $params = []): self
    {
        return $this->join('LEFT', $relationOrEntity, $alias, $condition, true, $params, $mapToProperty, false);
    }

    /** Like leftJoinAndMapOne(), mapping every match as a list. */
    public function leftJoinAndMapMany(string $mapToProperty, string $relationOrEntity, string $alias, ?string $condition = null, array $params = []): self
    {
        return $this->join('LEFT', $relationOrEntity, $alias, $condition, true, $params, $mapToProperty, true);
    }

    public function innerJoinAndMapOne(string $mapToProperty, string $relationOrEntity, string $alias, ?string $condition = null, array $params = []): self
    {
        return $this->join('INNER', $relationOrEntity, $alias, $condition, true, $params, $mapToProperty, false);
    }

    public function innerJoinAndMapMany(string $mapToProperty, string $relationOrEntity, string $alias, ?string $condition = null, array $params = []): self
    {
        return $this->join('INNER', $relationOrEntity, $alias, $condition, true, $params, $mapToProperty, true);
    }

    /**
     * After getMany(), put the related ids of a relation on a property, ONE extra query (TypeORM):
     *
     *   $qb->loadRelationIdAndMap('p.tagIds', 'p.tags');
     *   $qb->loadRelationIdAndMap('u.publishedIds', 'u.posts', 'post', fn (QueryBuilder $q) => $q->andWhere('post.status = :s', ['s' => 'published']));
     */
    public function loadRelationIdAndMap(string $mapToProperty, string $relationName, ?string $alias = null, ?Closure $queryBuilderFactory = null): self
    {
        return $this->mapRelation('ids', $mapToProperty, $relationName, $alias, $queryBuilderFactory);
    }

    /** After getMany(), put the number of related rows on a property: loadRelationCountAndMap('u.postCount', 'u.posts'). */
    public function loadRelationCountAndMap(string $mapToProperty, string $relationName, ?string $alias = null, ?Closure $queryBuilderFactory = null): self
    {
        return $this->mapRelation('count', $mapToProperty, $relationName, $alias, $queryBuilderFactory);
    }

    private function mapRelation(string $kind, string $mapTo, string $relationName, ?string $alias, ?Closure $factory): self
    {
        [$mapAlias, $mapProperty] = array_pad(explode('.', $mapTo, 2), 2, null);
        [$relAlias, $relProperty] = array_pad(explode('.', $relationName, 2), 2, null);
        $meta = $this->aliasMeta[$relAlias] ?? null;
        $relation = $relProperty !== null ? $meta?->relation($relProperty) : null;
        if ($relation === null || $mapProperty === null || $mapAlias !== $relAlias) {
            throw new OrmException("load{$kind}AndMap('$mapTo', '$relationName'): expected \"alias.property\" for both, on the same entity alias of this query.");
        }
        if (! property_exists($meta->class, $mapProperty)) {
            throw new OrmException("Cannot map \"$mapTo\": {$meta->class} has no property \$$mapProperty.");
        }
        $this->relationMaps[] = [$kind, $relAlias, $mapProperty, $relation, $factory, $alias ?? $relProperty];

        return $this;
    }

    /**
     * TypeORM's RelationQueryBuilder: change or load ONE relation of given entities without loading them.
     *
     *   $qb->relation(Post::class, 'tags')->of($post)->add($tag);       // or ->of(3)->add([1, 2])
     *   $qb->relation('author')->of($post)->set($user);                // relation of the query's entity
     *   $qb->relation(User::class, 'posts')->of($user)->loadMany();
     */
    public function relation(string $entityOrProperty, ?string $property = null): RelationQueryBuilder
    {
        if ($property === null) {
            $meta = $this->meta ?? throw new OrmException('relation(\'property\') needs a query builder created for an entity, or pass relation(Entity::class, \'property\').');

            return new RelationQueryBuilder($this->dataSource, $meta->class, $entityOrProperty);
        }

        return new RelationQueryBuilder($this->dataSource, $entityOrProperty, $property);
    }

    /** Alias of a join of `$parentAlias.$property`, or null when that relation is not joined. */
    public function joinedAlias(string $parentAlias, string $property): ?string
    {
        foreach ($this->joins as $join) {
            if ($join['parentAlias'] === $parentAlias && $join['relation']?->property === $property && $join['mapTo'] === null) {
                return $join['alias'];
            }
        }

        return null;
    }

    private function join(string $type, string $relationOrTable, string $alias, ?string $condition, bool $select, array $params, ?string $mapTo = null, bool $mapMany = false): self
    {
        $this->setParameters($params);
        $relation = null;
        $parentAlias = null;
        if (str_contains($relationOrTable, '.')) {
            [$parentAlias, $property] = explode('.', $relationOrTable, 2);
            $parentMeta = $this->aliasMeta[$parentAlias] ?? null;
            $relation = $parentMeta?->relation($property);
        }
        $mapProperty = null;
        if ($mapTo !== null) {
            [$mapAlias, $mapProperty] = array_pad(explode('.', $mapTo, 2), 2, null);
            $mapMeta = $this->aliasMeta[$mapAlias] ?? null;
            if ($mapProperty === null || $mapMeta === null) {
                throw new OrmException("Map target \"$mapTo\" must be \"alias.property\" of an entity alias of this query.");
            }
            if (! property_exists($mapMeta->class, $mapProperty)) {
                throw new OrmException("Cannot map \"$mapTo\": {$mapMeta->class} has no property \$$mapProperty (declare it, e.g. `public ?Photo \$$mapProperty = null;`).");
            }
            if ($relation !== null && $parentAlias !== $mapAlias) {
                throw new OrmException("Cannot map \"$mapTo\" from \"$relationOrTable\": the relation must belong to alias \"$mapAlias\".");
            }
            $parentAlias = $mapAlias;
        }
        if ($relation === null) {
            if (MetadataRegistry::has($relationOrTable)) {
                $meta = MetadataRegistry::get($relationOrTable);
                $this->aliasMeta[$alias] = $meta;
                $table = $meta->table;
            } else {
                $meta = null;
                $table = $relationOrTable;
            }
            if ($condition === null) {
                throw new OrmException("Join of \"$relationOrTable\" needs an ON condition (or use a relation like \"alias.property\").");
            }
            if ($mapTo !== null && $meta === null) {
                throw new OrmException("Cannot map \"$mapTo\" from table \"$relationOrTable\": join an entity class or a relation.");
            }
            $on = $this->translate($condition);
            if ($meta !== null && $meta->hasSoftDelete() && ! $this->withDeleted && $mapTo !== null) {
                $on = '(' . $on . ') AND ' . $this->quote($alias) . '.' . $this->quote($meta->deletedAtColumn()->name) . ' IS NULL';
            }
            if ($meta !== null && ($discriminator = $this->discriminatorCondition($meta, $alias)) !== null) {
                $on = '(' . $on . ') AND ' . $discriminator;
            }
            $this->joins[] = ['type' => $type, 'table' => $table, 'alias' => $alias, 'condition' => $on, 'meta' => $meta, 'parentAlias' => $mapTo !== null ? $parentAlias : null, 'relation' => null, 'select' => $select && $meta !== null, 'mapTo' => $mapProperty, 'mapMany' => $mapMany];

            return $this;
        }

        $parentMeta = $this->aliasMeta[$parentAlias];
        if ($relation->type === RelationType::MorphTo) {
            throw new OrmException("MorphTo {$parentMeta->class}::\${$relation->property} cannot be joined (its table depends on the row): load it with the `relations` find option, or join the concrete table with an ON condition.");
        }
        $targetMeta = MetadataRegistry::get($relation->target);
        $this->aliasMeta[$alias] = $targetMeta;
        $q = fn (string $a, string $c) => $this->quote($a) . '.' . $this->quote($c);
        $on = match ($relation->type) {
            RelationType::ManyToOne => $q($alias, $targetMeta->columnName($relation->referencedColumn)) . ' = ' . $q($parentAlias, (string) $relation->joinColumn),
            RelationType::OneToOne => $relation->joinColumn !== null
                ? $q($alias, $targetMeta->columnName($relation->referencedColumn)) . ' = ' . $q($parentAlias, $relation->joinColumn)
                : $this->inverseJoinOn($relation, $targetMeta, $alias, $parentAlias, $parentMeta),
            RelationType::OneToMany => $this->inverseJoinOn($relation, $targetMeta, $alias, $parentAlias, $parentMeta),
            RelationType::ManyToMany, RelationType::MorphToMany, RelationType::MorphedByMany => $this->manyToManyJoin($type, $relation, $targetMeta, $alias, $parentAlias, $parentMeta),
            RelationType::MorphOne, RelationType::MorphMany => $q($alias, (string) $relation->morphIdColumn) . ' = ' . $q($parentAlias, $parentMeta->columnName($relation->localKey ?? (string) $parentMeta->primary->property))
                . ' AND ' . $q($alias, (string) $relation->morphTypeColumn) . ' = ' . $this->createParameter(MorphMap::aliasFor((string) $relation->morphClass)),
            RelationType::HasOneThrough, RelationType::HasManyThrough, RelationType::BelongsToThrough => $this->throughJoin($type, $relation, $alias, $parentAlias, $parentMeta),
        };
        if ($targetMeta->hasSoftDelete() && ! $this->withDeleted) {
            $on .= ' AND ' . $q($alias, $targetMeta->deletedAtColumn()->name) . ' IS NULL';
        }
        if (($discriminator = $this->discriminatorCondition($targetMeta, $alias)) !== null) {
            $on .= ' AND ' . $discriminator; // a relation to a child entity only sees rows of its type
        }
        if ($condition !== null) {
            $on .= ' AND (' . $this->translate($condition) . ')';
        }
        $this->joins[] = ['type' => $type, 'table' => $targetMeta->table, 'alias' => $alias, 'condition' => $on, 'meta' => $targetMeta, 'parentAlias' => $parentAlias, 'relation' => $relation, 'select' => $select, 'mapTo' => $mapProperty, 'mapMany' => $mapMany];

        return $this;
    }

    private function inverseJoinOn(RelationMetadata $relation, EntityMetadata $targetMeta, string $alias, string $parentAlias, EntityMetadata $parentMeta): string
    {
        $inverse = $relation->inverseSide !== null ? $targetMeta->relation($relation->inverseSide) : null;
        if ($inverse?->joinColumn === null) {
            throw new OrmException("Relation {$parentMeta->class}::\${$relation->property} has no owning side with a join column on {$targetMeta->class}.");
        }

        return $this->quote($alias) . '.' . $this->quote($inverse->joinColumn) . ' = ' . $this->quote($parentAlias) . '.' . $this->quote($parentMeta->columnName($inverse->referencedColumn));
    }

    /** Adds the join-table join and returns the ON clause for the target table. */
    private function manyToManyJoin(string $type, RelationMetadata $relation, EntityMetadata $targetMeta, string $alias, string $parentAlias, EntityMetadata $parentMeta): string
    {
        [$joinTable, $parentColumn, $targetColumn] = self::joinTableFor($relation, $parentMeta, $targetMeta);
        $jtAlias = $parentAlias . '_' . $alias . '_jt';
        $condition = $this->quote($jtAlias) . '.' . $this->quote($parentColumn) . ' = ' . $this->quote($parentAlias) . '.' . $this->quote($parentMeta->primary->name);
        if ($relation->type->isMorph()) {
            $condition .= ' AND ' . $this->quote($jtAlias) . '.' . $this->quote((string) $relation->morphTypeColumn) . ' = ' . $this->createParameter(MorphMap::aliasFor((string) $relation->morphClass));
        }
        $this->joins[] = [
            'type' => $type,
            'table' => $joinTable,
            'alias' => $jtAlias,
            'condition' => $condition,
            'meta' => null, 'parentAlias' => null, 'relation' => null, 'select' => false, 'mapTo' => null, 'mapMany' => false,
        ];

        return $this->quote($alias) . '.' . $this->quote($targetMeta->primary->name) . ' = ' . $this->quote($jtAlias) . '.' . $this->quote($targetColumn);
    }

    /** Adds the joins of a through relation's intermediate tables and returns the ON clause for the target. */
    private function throughJoin(string $type, RelationMetadata $relation, string $alias, string $parentAlias, EntityMetadata $parentMeta): string
    {
        $path = ThroughPath::for($parentMeta, $relation);
        $prevAlias = $parentAlias;
        $prevColumn = $path->parentKey;
        $last = count($path->hops) - 1;
        foreach ($path->hops as $i => $hop) {
            if ($i === $last) {
                break;
            }
            $hopAlias = $parentAlias . '_' . $alias . '_h' . $i;
            $condition = $this->quote($hopAlias) . '.' . $this->quote($hop['in']) . ' = ' . $this->quote($prevAlias) . '.' . $this->quote($prevColumn);
            if ($hop['meta']->hasSoftDelete() && ! $this->withDeleted) {
                $condition .= ' AND ' . $this->quote($hopAlias) . '.' . $this->quote($hop['meta']->deletedAtColumn()->name) . ' IS NULL';
            }
            $this->joins[] = ['type' => $type, 'table' => $hop['meta']->table, 'alias' => $hopAlias, 'condition' => $condition, 'meta' => null, 'parentAlias' => null, 'relation' => null, 'select' => false, 'mapTo' => null, 'mapMany' => false];
            $prevAlias = $hopAlias;
            $prevColumn = (string) $hop['out'];
        }

        return $this->quote($alias) . '.' . $this->quote($path->hops[$last]['in']) . ' = ' . $this->quote($prevAlias) . '.' . $this->quote($prevColumn);
    }

    /** @return array{0: string, 1: string, 2: string} [joinTable, columnReferencingOwner, columnReferencingTarget] */
    public static function joinTableFor(RelationMetadata $relation, EntityMetadata $ownerMeta, EntityMetadata $targetMeta): array
    {
        if ($relation->joinTable !== null) {
            return [$relation->joinTable, (string) $relation->joinTableColumn, (string) $relation->inverseJoinColumn];
        }
        $inverse = $relation->inverseSide !== null ? $targetMeta->relation($relation->inverseSide) : null;
        if ($inverse?->joinTable === null) {
            throw new OrmException("ManyToMany {$ownerMeta->class}::\${$relation->property} has no #[JoinTable] on either side.");
        }

        return [$inverse->joinTable, (string) $inverse->inverseJoinColumn, (string) $inverse->joinTableColumn];
    }

    // ---- order / group / limit ------------------------------------------------------------------

    /**
     * ORDER BY (replacing earlier orderings); `$nulls` 'NULLS FIRST' | 'NULLS LAST' (TypeORM; 'first' / 'last' work
     * too) — MySQL / MariaDB have no NULLS FIRST / LAST and get `ISNULL(expr)` ordered first instead.
     */
    public function orderBy(string $expr, string $direction = 'ASC', ?string $nulls = null): self
    {
        $this->orderBy = [];

        return $this->addOrderBy($expr, $direction, $nulls);
    }

    public function addOrderBy(string $expr, string $direction = 'ASC', ?string $nulls = null): self
    {
        $this->orderBy[$this->translate($expr)] = (strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC') . self::nullsOrder($nulls);

        return $this;
    }

    /** ' NULLS FIRST' / ' NULLS LAST' / '' for TypeORM's nulls option ('NULLS FIRST', 'first', …; null: the database's default). */
    public static function nullsOrder(?string $nulls): string
    {
        if ($nulls === null) {
            return '';
        }

        return match (strtoupper(trim($nulls))) {
            'NULLS FIRST', 'FIRST' => ' NULLS FIRST',
            'NULLS LAST', 'LAST' => ' NULLS LAST',
            default => throw new OrmException("Invalid nulls order \"$nulls\": use 'NULLS FIRST' or 'NULLS LAST' ('first' / 'last').")
        };
    }

    /** "expr DIRECTION" items of ORDER BY; MySQL / MariaDB emulate NULLS FIRST / LAST with ISNULL(expr). */
    private function compileOrder(): string
    {
        $items = [];
        foreach ($this->orderBy as $expr => $direction) {
            if (str_contains($direction, ' NULLS ') && $this->connection()->driver() === 'mysql') {
                [$direction, $nulls] = explode(' NULLS ', $direction);
                $items[] = "ISNULL($expr) " . ($nulls === 'FIRST' ? 'DESC' : 'ASC');
            }
            $items[] = "$expr $direction";
        }

        return implode(', ', $items);
    }

    public function groupBy(string ...$exprs): self
    {
        $this->groupBy = [];

        return $this->addGroupBy(...$exprs);
    }

    public function addGroupBy(string ...$exprs): self
    {
        foreach ($exprs as $expr) {
            $this->groupBy[] = $this->translate($expr);
        }

        return $this;
    }

    public function having(string $condition, array $params = []): self
    {
        $this->havings = [['AND', $this->translate($condition)]];

        return $this->setParameters($params);
    }

    public function andHaving(string $condition, array $params = []): self
    {
        $this->havings[] = ['AND', $this->translate($condition)];

        return $this->setParameters($params);
    }

    public function limit(?int $limit): self
    {
        $this->limit = $limit;

        return $this;
    }

    public function take(?int $limit): self
    {
        return $this->limit($limit);
    }

    public function offset(?int $offset): self
    {
        $this->offset = $offset;

        return $this;
    }

    public function skip(?int $offset): self
    {
        return $this->offset($offset);
    }

    /** Include soft-deleted rows. */
    public function withDeleted(bool $with = true): self
    {
        $this->withDeleted = $with;

        return $this;
    }

    // ---- locking --------------------------------------------------------------------------------

    /**
     * TypeORM's setLock(): 'optimistic' with a version (int for #[VersionColumn], a date for #[UpdateDateColumn],
     * checked by getOne()), or a pessimistic mode (see Lock) with optional PostgreSQL lock tables (aliases).
     *
     *   $qb->setLock('pessimistic_write')->setOnLocked('skip_locked')->getMany();   // in a transaction
     *   $qb->setLock('optimistic', 3)->getOne();
     *
     * @param list<string>|null $tables
     */
    public function setLock(?string $mode, int|\DateTimeInterface|null $version = null, ?array $tables = null): self
    {
        $this->lock = $mode === null ? null : new Lock($mode, $version, $tables);

        return $this;
    }

    /** 'nowait' | 'skip_locked' (TypeORM's setOnLocked()); null clears it. */
    public function setOnLocked(?string $onLocked): self
    {
        if ($onLocked !== null && ! in_array($onLocked, ['nowait', 'skip_locked'], true)) {
            throw new OrmException("onLocked must be \"nowait\" or \"skip_locked\", got \"$onLocked\".");
        }
        $this->onLocked = $onLocked;

        return $this;
    }

    public function getLock(): ?Lock
    {
        return $this->lock === null ? null : $this->lock->withOnLocked($this->onLocked ?? $this->lock->onLocked);
    }

    /**
     * Run on this QueryRunner (TypeORM's setQueryRunner()): its connection — a 'slave' runner's replica, or the
     * master to read your own writes with replication: `$qb->setQueryRunner($ds->createQueryRunner('master'))`.
     */
    public function setQueryRunner(?QueryRunner $queryRunner): self
    {
        $this->queryRunner = $queryRunner;

        return $this;
    }

    public function getQueryRunner(): ?QueryRunner
    {
        return $this->queryRunner;
    }

    /**
     * Cache the results of this query in the DataSource's query result cache (TypeORM's cache(); needs the
     * `cache` DataSource option). getMany / getOne / getRawMany / getRawOne / getCount read the cache first.
     *
     *   $qb->cache(true);                    // default duration (cache.duration, else 1000 ms)
     *   $qb->cache(60_000);                  // one minute
     *   $qb->cache('users_admins', 25_000);  // an id, to remove it later: $ds->queryResultCache->remove(['users_admins'])
     *   $qb->cache(false);                   // not even with cache.alwaysEnabled
     */
    public function cache(bool|int|string $enabledOrMillisecondsOrId, ?int $milliseconds = null): self
    {
        if (is_bool($enabledOrMillisecondsOrId)) {
            $this->cache = $enabledOrMillisecondsOrId;
        } elseif (is_int($enabledOrMillisecondsOrId)) {
            $this->cache = true;
            $this->cacheDuration = $enabledOrMillisecondsOrId;
        } else {
            $this->cache = true;
            $this->cacheId = $enabledOrMillisecondsOrId;
        }
        if ($milliseconds !== null) {
            $this->cacheDuration = $milliseconds;
        }

        return $this;
    }

    /** Insert / update / delete fire the subscribers' events unless disabled (TypeORM's callListeners()). */
    public function callListeners(bool $enabled): self
    {
        $this->callListeners = $enabled;

        return $this;
    }

    // ---- write queries --------------------------------------------------------------------------

    public function insert(): self
    {
        $this->type = 'insert';

        return $this;
    }

    public function into(string $entityOrTable): self
    {
        return $this->from($entityOrTable);
    }

    /** @param array<string, mixed>|list<array<string, mixed>> $values property (or column) => value */
    public function values(array $values): self
    {
        $this->insertRows = $values !== [] && array_is_list($values) ? $values : [$values];

        return $this;
    }

    public function update(?string $entityOrTable = null, ?string $alias = null): self
    {
        $this->type = 'update';

        return $entityOrTable !== null ? $this->from($entityOrTable, $alias) : $this;
    }

    /** @param array<string, mixed> $values */
    public function set(array $values): self
    {
        $this->updateSet = $values;

        return $this;
    }

    public function delete(): self
    {
        $this->type = 'delete';

        return $this;
    }

    /** Runs an insert / update / delete and returns the affected row count. */
    public function execute(): int
    {
        if ($this->type !== 'select') {
            $this->meta?->assertWritable($this->type . '()');
        }
        if ($this->meta !== null && $this->callListeners && $this->type !== 'select') {
            return $this->dataSource->broadcaster()->write(
                $this->type,
                $this->meta,
                $this->type === 'insert' ? $this->insertRows : $this->updateSet,
                $this->executeStatement(...),
            );
        }

        return $this->executeStatement();
    }

    private function executeStatement(): int
    {
        if ($this->type === 'insert' && count($this->insertRows) > 1) {
            // Rows with different column sets become separate statements (SQLite has no DEFAULT keyword in VALUES).
            $groups = [];
            foreach ($this->insertRows as $row) {
                $groups[implode(',', array_keys($row))][] = $row;
            }
            if (count($groups) > 1) {
                $all = $this->insertRows;
                try {
                    return $this->writeConnection()->transaction(function () use ($groups): int {
                        $affected = 0;
                        foreach ($groups as $rows) {
                            $this->insertRows = $rows;
                            $this->params = [];
                            $affected += $this->writeConnection()->execute($this->getSql(), $this->getParameters());
                        }

                        return $affected;
                    });
                } finally {
                    $this->insertRows = $all;
                }
            }
        }

        return $this->writeConnection()->execute($this->getSql(), $this->getParameters());
    }

    // ---- results --------------------------------------------------------------------------------

    /** @return list<object> */
    public function getMany(): array
    {
        $this->assertEntity();
        $this->assertLock();

        $conn = $this->readConnection();

        // relation-id / mapped-relation queries of the hydration read from the same node
        return $this->dataSource->readingFrom($conn, fn () => $this->dataSource->broadcaster()->loading(fn () => $this->hydrateRows($this->fetchRows($conn))));
    }

    public function getOne(): ?object
    {
        $this->assertEntity();
        $qb = clone $this;
        if (! $qb->hasToManyJoin()) {
            $qb->limit = 1;
        }
        $lock = $this->getLock();
        if ($lock?->mode !== 'optimistic') {
            return $qb->getMany()[0] ?? null;
        }
        $qb->lock = null;
        $entity = $qb->getMany()[0] ?? null;
        if ($entity !== null) {
            $this->checkOptimisticLock($lock, $entity);
        }

        return $entity;
    }

    /** TypeORM's optimistic lock: the loaded version (or update date) must be the expected one. */
    private function checkOptimisticLock(Lock $lock, object $entity): void
    {
        $meta = $this->meta;
        $byDate = $lock->version instanceof \DateTimeInterface;
        $property = $byDate ? $meta->updatedAt : $meta->version;
        if ($property === null) {
            if ($meta->version === null && $meta->updatedAt === null) {
                throw new NoVersionOrUpdateDateColumnException($meta->class);
            }
            throw new OrmException('An optimistic lock on ' . $meta->class . ' needs ' . ($byDate ? 'an integer version (it has a #[VersionColumn], no #[UpdateDateColumn])' : 'a date version (it has an #[UpdateDateColumn], no #[VersionColumn])') . '.');
        }
        $actual = $meta->isInitialized($entity, $property) ? $entity->{$property} : null;
        $same = $byDate
            ? $actual instanceof \DateTimeInterface && $actual->format('Y-m-d H:i:s.u') === $lock->version->format('Y-m-d H:i:s.u')
            : $actual !== null && (int) $actual === $lock->version;
        if (! $same) {
            throw new OptimisticLockVersionMismatchException($meta->class, $lock->version, $actual);
        }
    }

    /** Lock preconditions: a pessimistic lock needs a transaction; an optimistic one only works with getOne(). */
    private function assertLock(): void
    {
        $lock = $this->lock;
        if ($lock === null) {
            return;
        }
        if ($lock->mode === 'optimistic') {
            throw new OptimisticLockCanNotBeUsedException;
        }
        if ($lock->isPessimistic() && ! $this->readConnection()->inTransaction()) {
            throw new PessimisticLockTransactionRequiredException;
        }
    }

    /** The locking clause of the main SELECT. */
    private function compileLock(): string
    {
        $lock = $this->getLock();
        if ($lock === null) {
            return '';
        }

        return $lock->sql($this->connection(), function (string $table): string {
            if (! isset($this->aliasMeta[$table]) && $this->meta !== null && in_array($table, [$this->meta->table, Mapping\Naming::shortName($this->meta->class)], true)) {
                $table = (string) $this->alias; // the entity's table / name stands for the main alias
            }

            return $this->quote($table);
        });
    }

    public function getOneOrFail(): object
    {
        return $this->getOne() ?? throw EntityNotFoundException::for((string) $this->meta?->class);
    }

    /** @return list<array<string, mixed>> */
    public function getRawMany(): array
    {
        $this->assertLock();

        return $this->loadRawResults($this->readConnection(), $this->getSql(), $this->getParameters());
    }

    /** @return array<string, mixed>|null */
    public function getRawOne(): ?array
    {
        $this->assertLock();

        if ($this->cachingEnabled()) {
            return $this->loadRawResults($this->readConnection(), $this->getSql(), $this->getParameters())[0] ?? null;
        }

        return $this->readConnection()->fetchOne($this->getSql(), $this->getParameters());
    }

    public function getCount(): int
    {
        $pk = $this->meta !== null
            ? 'COUNT(DISTINCT ' . $this->quote((string) $this->alias) . '.' . $this->quote($this->meta->primary->name) . ')'
            : 'COUNT(*)';
        $sql = $this->compileSelect([$pk], false, false);

        if ($this->cachingEnabled()) {
            $row = $this->loadRawResults($this->readConnection(), $sql, $this->getParameters(), '-count')[0] ?? [];

            return (int) (reset($row) ?: 0);
        }

        return (int) $this->readConnection()->fetchColumn($sql, $this->getParameters());
    }

    /** @return array{0: list<object>, 1: int} */
    public function getManyAndCount(): array
    {
        return [$this->getMany(), $this->getCount()];
    }

    /**
     * The raw rows of the query as a generator, without loading the whole result into memory (TypeORM's
     * stream(); rows look like getRawMany()'s). See Connection::cursor() for how each database streams.
     *
     *   foreach ($ds->createQueryBuilder(Event::class, 'e')->where('e.day = :d', ['d' => $day])->stream() as $row) {
     *       fputcsv($out, [$row['e__id'], $row['e__name']]);
     *   }
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function stream(int $batchSize = 1000): \Generator
    {
        $this->assertLock();

        return $this->readConnection()->cursor($this->getSql(), $this->getParameters(), $batchSize);
    }

    /**
     * The entities of the query as a generator, hydrated $batchSize root entities at a time from a streamed
     * result (see stream()): memory stays bounded by the batch, not the result. $eachBatch(list $entities) runs
     * on every batch before its entities are yielded (load relations per batch, …). With joined to-many
     * relations the rows are ordered by the root key last, so each entity's rows arrive together.
     *
     *   foreach ($users->createQueryBuilder('u')->where(['active' => true])->getManyIterable(500) as $user) { ... }
     *
     * @param (\Closure(list<object>): void)|null $eachBatch
     * @return \Generator<int, object>
     */
    public function getManyIterable(int $batchSize = 100, ?\Closure $eachBatch = null): \Generator
    {
        $this->assertEntity();
        $this->assertLock();
        $batchSize = max(1, $batchSize);
        $qb = $this;
        $pk = $this->quote((string) $this->alias) . '.' . $this->quote($this->meta->primary->name);
        if ($this->hasToManyJoin() && ! isset($this->orderBy[$pk])) {
            $qb = clone $this;
            $qb->orderBy[$pk] = 'ASC';
        }
        $conn = $this->readConnection();
        $query = $qb->entityQuery(fn (string $sql, array $params) => $conn->fetchAll($sql, $params));
        if ($query === null) {
            return;
        }
        $hydrate = fn (array $rows): array => $this->dataSource->readingFrom($conn, fn () => $this->dataSource->broadcaster()->loading(function () use ($qb, $rows, $eachBatch): array {
            $entities = $qb->hydrateRows($rows);
            if ($eachBatch !== null && $entities !== []) {
                $eachBatch($entities);
            }

            return $entities;
        }));
        $buffer = [];
        $roots = [];
        foreach ($conn->cursor($query[0], $query[1]) as $row) {
            $key = $qb->rootKey($row);
            if ($key !== null && ! isset($roots[$key]) && count($roots) >= $batchSize) {
                yield from $hydrate($buffer);
                $buffer = [];
                $roots = [];
            }
            if ($key !== null) {
                $roots[$key] = true;
            }
            $buffer[] = $row;
        }
        if ($buffer !== []) {
            yield from $hydrate($buffer);
        }
    }

    public function getSql(): string
    {
        return match ($this->type) {
            'insert' => $this->compileInsert(),
            'update' => $this->compileUpdate(),
            'delete' => $this->compileDelete(),
            default => $this->compileSelect(null, true, true),
        };
    }

    /** @return array<string, mixed> */
    public function getParameters(): array
    {
        return $this->params;
    }

    public function getMetadata(): ?EntityMetadata
    {
        return $this->meta;
    }

    public function getAlias(): ?string
    {
        return $this->alias;
    }

    /** The master connection (used for quoting and SQL dialect; see readConnection() for where reads run). */
    public function connection(): Connection
    {
        return $this->dataSource->getConnection();
    }

    /** Where this query's SELECTs run: its QueryRunner's connection, else DataSource::readConnection(). */
    public function readConnection(): Connection
    {
        return $this->dataSource->readConnection($this->queryRunner);
    }

    /** Where insert / update / delete run: its QueryRunner's connection, else the master. */
    private function writeConnection(): Connection
    {
        return $this->queryRunner !== null && ! $this->queryRunner->isReleased() ? $this->queryRunner->connection() : $this->dataSource->getConnection();
    }

    // ---- compilation ----------------------------------------------------------------------------

    private function compileSelect(?array $selectOverride, bool $withOrder, bool $withLimit, bool $forEntities = false): string
    {
        if ($this->table === null) {
            throw new OrmException('No table/entity set on the query builder: call from().');
        }
        $q = fn (string $s) => $this->quote($s);
        $columns = $selectOverride ?? $this->expandSelects($forEntities);
        $sql = 'SELECT ' . ($this->distinct ? 'DISTINCT ' : '') . implode(', ', $columns)
            . ' FROM ' . $q($this->table) . ' AS ' . $q((string) $this->alias);
        foreach ($this->joins as $join) {
            $sql .= ' ' . $join['type'] . ' JOIN ' . $q($join['table']) . ' AS ' . $q($join['alias']) . ' ON ' . $join['condition'];
        }
        $sql .= $this->compileWhere(true);
        if ($this->groupBy !== []) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groupBy);
        }
        if ($this->havings !== []) {
            $sql .= ' HAVING ' . $this->joinConditions($this->havings);
        }
        if ($withOrder && $this->orderBy !== []) {
            $sql .= ' ORDER BY ' . $this->compileOrder();
        }
        if ($withLimit) {
            $sql .= $this->compileLimit();
            if ($selectOverride === null) {
                $sql .= $this->compileLock(); // never on counts / the id pre-query of paginated joins
            }
        }

        return $sql;
    }

    private function compileLimit(): string
    {
        $sql = '';
        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . (int) $this->limit;
        } elseif ($this->offset !== null && $this->connection()->driver() !== 'pgsql') {
            $sql .= ' LIMIT ' . ($this->connection()->driver() === 'mysql' ? '18446744073709551615' : '-1');
        }
        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . (int) $this->offset;
        }

        return $sql;
    }

    /**
     * SELECT list. `alias` selects every column of an entity alias as "alias__column"; for entity results
     * (getMany / getOne) `alias.property` items select only those columns of that alias, plus its primary key and
     * foreign keys (TypeORM's partial selection); raw results keep `alias.property` as written.
     *
     * @return list<string>
     */
    private function expandSelects(bool $forEntities = false): array
    {
        $items = $this->selects;
        if ($items === [] || ($this->selectsAlias && $this->meta !== null && ! in_array((string) $this->alias, $items, true))) {
            array_unshift($items, (string) $this->alias);
        }
        $partial = [];
        $plain = [];
        foreach ($items as $item) {
            // alias.property, alias.embedded or alias.embedded.property
            if ($forEntities && preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\.([A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*)$/', $item, $m) === 1 && isset($this->aliasMeta[$m[1]])) {
                $meta = $this->aliasMeta[$m[1]];
                $column = $meta->column($m[2]);
                $columns = $column !== null ? [$column] : $meta->embeddedColumns($m[2]);
                if ($columns !== []) {
                    foreach ($columns as $c) {
                        $partial[$m[1]][] = $c->name;
                    }
                    continue;
                }
            }
            $plain[] = $item;
        }
        foreach ($this->joins as $join) {
            if ($join['select'] && ! in_array($join['alias'], $plain, true) && ! isset($partial[$join['alias']])) {
                $plain[] = $join['alias'];
            }
        }
        $out = [];
        $added = [];
        $add = function (string $alias, string $column) use (&$out, &$added): void {
            if (! isset($added[$alias . '__' . $column])) {
                $added[$alias . '__' . $column] = true;
                $out[] = $this->quote($alias) . '.' . $this->quote($column) . ' AS ' . $this->quote($alias . '__' . $column);
            }
        };
        foreach ($plain as $item) {
            if (isset($this->aliasMeta[$item])) {
                foreach ($this->aliasMeta[$item]->selectColumns() as $column) {
                    // select: false columns only when selected by name (TypeORM)
                    if ($column->select || in_array($column->name, $partial[$item] ?? [], true)) {
                        $add($item, $column->name);
                    }
                }
            } elseif ($item === (string) $this->alias) {
                $out[] = $this->quote($item) . '.*';
            } else {
                $out[] = $this->translate($item);
            }
        }
        foreach ($partial as $alias => $names) {
            if (in_array($alias, $plain, true)) {
                continue; // the whole alias is selected (plus the named select: false columns)
            }
            foreach ($this->aliasMeta[$alias]->columns as $column) {
                if ($column->primary || $column->relation !== null || $column->special === 'discriminator' || in_array($column->name, $names, true)) {
                    $add($alias, $column->name);
                }
            }
        }

        return $out;
    }

    private function compileWhere(bool $aliased): string
    {
        $wheres = $this->wheres;
        if ($this->meta !== null && $this->meta->hasSoftDelete() && ! $this->withDeleted && $this->type === 'select') {
            $col = ($aliased ? $this->quote((string) $this->alias) . '.' : '') . $this->quote($this->meta->deletedAtColumn()->name);
            $wheres[] = ['AND', $col . ' IS NULL'];
        }
        if ($this->meta !== null && $this->type !== 'insert' && ($discriminator = $this->discriminatorCondition($this->meta, $aliased ? (string) $this->alias : null)) !== null) {
            $wheres[] = ['AND', $discriminator]; // a child entity's query only sees rows of its type (and its children's)
        }
        if ($wheres === []) {
            return '';
        }

        return ' WHERE ' . $this->joinConditions($wheres);
    }

    /** @param list<array{0: string, 1: string}> $conditions */
    private function joinConditions(array $conditions): string
    {
        $sql = '';
        foreach ($conditions as $i => [$glue, $condition]) {
            $sql .= ($i === 0 ? '' : " $glue ") . '(' . $condition . ')';
        }

        return $sql;
    }

    private function compileInsert(): string
    {
        if ($this->table === null || $this->insertRows === []) {
            throw new OrmException('insert() needs into() and values().');
        }
        $version = $this->meta?->versionColumn()?->name;
        $discriminator = $this->meta?->discriminatorColumn;
        $columns = [];
        $mappedRows = [];
        foreach (array_map($this->flattenEmbedded(...), $this->insertRows) as $row) {
            $mapped = [];
            foreach ($row as $key => $value) {
                if (($relation = $this->relationColumns((string) $key, $value, 'insert()')) !== null) {
                    $mapped = array_merge($mapped, $relation);
                } elseif ($this->writable((string) $key, 'insert')) {
                    $mapped[$this->columnName((string) $key)] = $this->convertValue((string) $key, $value);
                }
            }
            $columns += array_fill_keys(array_keys($mapped), true);
            if ($version !== null) {
                $mapped[$version] = 1; // like TypeORM's insert query builder: a new row starts at version 1
            }
            if ($discriminator !== null) {
                $mapped[$discriminator] = $this->meta->discriminatorValue; // single table inheritance: the row's type
            }
            $mappedRows[] = $mapped;
        }
        foreach ([$version, $discriminator] as $column) {
            if ($column !== null) {
                $columns[$column] = true;
            }
        }
        $columns = array_keys($columns);
        $valueSets = [];
        foreach ($mappedRows as $mapped) {
            $set = [];
            foreach ($columns as $column) {
                $set[] = array_key_exists($column, $mapped) ? $this->createParameter($mapped[$column]) : 'NULL';
            }
            $valueSets[] = '(' . implode(', ', $set) . ')';
        }

        return 'INSERT INTO ' . $this->quote($this->table) . ' (' . implode(', ', array_map($this->quote(...), $columns)) . ') VALUES ' . implode(', ', $valueSets);
    }

    private function compileUpdate(): string
    {
        if ($this->table === null || $this->updateSet === []) {
            throw new OrmException('update() needs a table and set().');
        }
        $sets = [];
        $written = [];
        foreach ($this->flattenEmbedded($this->updateSet) as $key => $value) {
            if (($relation = $this->relationColumns((string) $key, $value, 'update()')) !== null) {
                foreach ($relation as $column => $dbValue) {
                    $written[] = $column;
                    $sets[] = $this->quote($column) . ' = ' . $this->createParameter($dbValue);
                }
            } elseif ($this->writable((string) $key, 'update')) {
                $written[] = $column = $this->columnName((string) $key);
                $sets[] = $this->quote($column) . ' = ' . $this->createParameter($this->convertValue((string) $key, $value));
            }
        }
        if ($sets === []) {
            throw new OrmException('update(): none of the columns in set() can be updated (update: false, readonly or generated columns).');
        }
        // like TypeORM's UpdateQueryBuilder: bump the #[VersionColumn] and stamp the #[UpdateDateColumn]
        if (($version = $this->meta?->versionColumn()) !== null && ! in_array($version->name, $written, true)) {
            $sets[] = $this->quote($version->name) . ' = ' . $this->quote($version->name) . ' + 1';
        }
        if ($this->meta?->updatedAt !== null && ! in_array($updated = $this->meta->columnName($this->meta->updatedAt), $written, true)) {
            $sets[] = $this->quote($updated) . ' = ' . $this->createParameter(TypeConverter::toDatabase($this->meta->column($this->meta->updatedAt), new \DateTimeImmutable));
        }

        return 'UPDATE ' . $this->quote($this->table) . ' SET ' . implode(', ', $sets) . $this->stripAlias($this->compileWhere(false));
    }

    private function compileDelete(): string
    {
        if ($this->table === null) {
            throw new OrmException('delete() needs from().');
        }

        return 'DELETE FROM ' . $this->quote($this->table) . $this->stripAlias($this->compileWhere(false));
    }

    /** UPDATE/DELETE cannot portably use aliases: turn "alias"."col" into "col". */
    private function stripAlias(string $sql): string
    {
        $alias = preg_quote($this->quote((string) $this->alias), '/');

        return (string) preg_replace('/' . $alias . '\./', '', $sql);
    }

    // ---- helpers --------------------------------------------------------------------------------

    private function compileCondition(string|array $condition): string
    {
        if (is_string($condition)) {
            return $this->translate($condition);
        }
        [$meta, $column, $join] = $this->criteriaScope((string) $this->alias, $this->meta);

        return Criteria::compile($condition, $meta, $column, fn (mixed $value) => $this->createParameter($value), $this->connection()->driver(), $join);
    }

    /**
     * [meta, column mapper, relation joiner] for array criteria on $alias. A nested relation condition
     * (['author' => ['name' => 'Ada']]) LEFT JOINs the relation once (or reuses its existing join, like TypeORM).
     *
     * @return array{0: ?EntityMetadata, 1: Closure(string): string, 2: ?Closure(string): array}
     */
    private function criteriaScope(string $alias, ?EntityMetadata $meta): array
    {
        $column = fn (string $key): string => str_contains($key, '.') && $meta?->column($key) === null && ($meta === null || $meta->columnName($key) === $key)
            ? $this->translate($key)
            : $this->quote($alias) . '.' . $this->quote($meta?->columnName($key) ?? $key);
        $join = $meta === null ? null : function (string $property) use ($alias): array {
            $joinAlias = $this->joinedAlias($alias, $property);
            if ($joinAlias === null) {
                $joinAlias = $alias . '__' . $property;
                while (isset($this->aliasMeta[$joinAlias])) {
                    $joinAlias .= '_';
                }
                $this->leftJoin($alias . '.' . $property, $joinAlias);
            }

            return $this->criteriaScope($joinAlias, $this->aliasMeta[$joinAlias]);
        };

        return [$meta, $column, $join];
    }

    /** Quoted reference for "alias.prop" or a bare property/column of the root alias. */
    private function ref(string $key): string
    {
        if (str_contains($key, '.')) {
            return $this->translate($key);
        }

        return $this->quote((string) $this->alias) . '.' . $this->quote($this->columnName($key));
    }

    /** Replace `alias.identifier` references for known aliases with quoted column references. */
    private function translate(string $expr): string
    {
        $aliases = array_keys($this->aliasMeta);
        if ($this->alias !== null && ! in_array($this->alias, $aliases, true)) {
            $aliases[] = $this->alias;
        }
        foreach ($this->joins as $join) {
            if (! in_array($join['alias'], $aliases, true)) {
                $aliases[] = $join['alias'];
            }
        }
        if ($aliases === []) {
            return $expr;
        }
        $pattern = '/(?<![\w"`:])(' . implode('|', array_map(fn ($a) => preg_quote($a, '/'), $aliases)) . ')\.((?:[A-Za-z_][A-Za-z0-9_]*)(?:\.[A-Za-z_][A-Za-z0-9_]*)*|\*)\b/';

        return (string) preg_replace_callback($pattern, function (array $m): string {
            $meta = $this->aliasMeta[$m[1]] ?? null;
            $path = $m[2];
            $rest = '';
            // embedded paths (u.address.city) resolve as a whole; otherwise only the first segment is a property
            while (str_contains($path, '.') && $meta?->column($path) === null) {
                $rest = substr($path, (int) strrpos($path, '.')) . $rest;
                $path = substr($path, 0, (int) strrpos($path, '.'));
            }
            $column = $path === '*' ? '*' : ($meta?->columnName($path) ?? $path);

            return $this->quote($m[1]) . '.' . $this->quote($column) . $rest;
        }, $expr);
    }

    /** @param array<string, mixed> $values @return array<string, mixed> #[Embedded] values flattened to their columns */
    private function flattenEmbedded(array $values): array
    {
        return $this->meta === null ? $values : EmbeddedValues::flatten($this->meta, $values);
    }

    /** Entity columns with insert: false / update: false (readonly, generated) are left out of INSERT / UPDATE. */
    private function writable(string $key, string $operation): bool
    {
        $column = $this->meta?->column($key);

        return $column === null || ($operation === 'insert' ? $column->insert : $column->update);
    }

    private function columnName(string $key): string
    {
        return $this->meta?->columnName($key) ?? $key;
    }

    private function convertValue(string $key, mixed $value): mixed
    {
        $column = $this->meta?->column($key);

        return $column !== null ? TypeConverter::toDatabase($column, $value) : TypeConverter::toParameter($value);
    }

    /**
     * A relation property in values() / set(): its join column(s) and database value(s) — an entity, its key or
     * ['id' => …] (see Mapping\RelationValues); null when $key is no relation.
     *
     * @return array<string, mixed>|null
     */
    private function relationColumns(string $key, mixed $value, string $operation): ?array
    {
        return $this->meta === null ? null : RelationValues::columns($this->meta, $key, $value, $operation);
    }

    /** "alias.type IN (…)" limiting a single table inheritance child to its discriminator values; null otherwise. */
    private function discriminatorCondition(EntityMetadata $meta, ?string $alias): ?string
    {
        $values = $meta->discriminatorValues();
        if ($values === null) {
            return null;
        }
        $name = 'nesto_discriminator_' . preg_replace('/\W/', '_', $alias ?? 'root');
        $this->params[$name] = $values;

        return ($alias !== null ? $this->quote($alias) . '.' : '') . $this->quote((string) $meta->discriminatorColumn) . ' IN (:' . $name . ')';
    }

    private function createParameter(mixed $value): string
    {
        $name = 'nesto_p' . $this->paramCounter++;
        $this->params[$name] = $value;

        return ':' . $name;
    }

    private function quote(string $identifier): string
    {
        return $this->connection()->quote($identifier);
    }

    private function assertEntity(): void
    {
        if ($this->meta === null) {
            throw new OrmException('getMany()/getOne() need an entity: use from(Entity::class) or getRawMany() for tables.');
        }
    }

    /** A joined to-many relation (selected or only filtered on) multiplies the root rows. */
    private function hasToManyJoin(): bool
    {
        foreach ($this->joins as $join) {
            if ($join['relation']?->isToMany() || $join['mapMany']) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array<string, mixed>> */
    private function fetchRows(Connection $conn): array
    {
        $query = $this->entityQuery(fn (string $sql, array $params) => $this->loadRawResults($conn, $sql, $params, '-pagination'));

        return $query === null ? [] : $this->loadRawResults($conn, $query[0], $query[1]);
    }

    /**
     * The SELECT of an entity query. Paginated to-many joins take two steps: the ROOT ids of the page first
     * ($fetchIds runs that query), then the joined graph for those ids only. Null when the page is empty.
     *
     * @param \Closure(string, array<string, mixed>): list<array<string, mixed>> $fetchIds
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function entityQuery(\Closure $fetchIds): ?array
    {
        if (($this->limit !== null || $this->offset !== null) && $this->hasToManyJoin()) {
            $pk = $this->quote((string) $this->alias) . '.' . $this->quote($this->meta->primary->name);
            $select = [$pk];
            foreach (array_keys($this->orderBy) as $expr) {
                if ($expr !== $pk) {
                    $select[] = $expr;
                }
            }
            $full = $this->compileSelect(['x'], true, true);
            $idsSql = 'SELECT DISTINCT ' . implode(', ', $select) . substr($full, (int) strpos($full, ' FROM '));
            $ids = array_values(array_unique(array_map(fn (array $r) => reset($r), $fetchIds($idsSql, $this->params))));
            if ($ids === []) {
                return null;
            }
            $clone = clone $this;
            $clone->limit = null;
            $clone->offset = null;
            $clone->wheres[] = ['AND', $pk . ' IN (' . implode(', ', array_map($clone->createParameter(...), $ids)) . ')'];

            return [$clone->compileSelect(null, true, false, true), $clone->params];
        }

        return [$this->compileSelect(null, true, true, true), $this->params];
    }

    /** Is the query result cache used for this query (cache(true), or cache.alwaysEnabled unless cache(false))? */
    private function cachingEnabled(): bool
    {
        if ($this->dataSource->queryResultCache === null) {
            return false;
        }

        return $this->cache === true || (! empty($this->dataSource->cacheOptions()['alwaysEnabled']) && $this->cache !== false);
    }

    /**
     * Rows of a SELECT, through the query result cache when enabled (TypeORM's loadRawResults()): the entry is
     * found by the cache id (+ $idSuffix for the count / pagination queries) or by the SQL and its parameters.
     *
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function loadRawResults(Connection $conn, string $sql, array $params, string $idSuffix = ''): array
    {
        if (! $this->cachingEnabled()) {
            return $conn->fetchAll($sql, $params);
        }
        $cache = $this->dataSource->queryResultCache;
        $options = $this->dataSource->cacheOptions();
        $ignoreErrors = ! empty($options['ignoreErrors']);
        $entry = [
            'identifier' => $this->cacheId !== null ? $this->cacheId . $idSuffix : null,
            'query' => $sql . ' -- PARAMETERS: ' . json_encode(array_map(static fn (mixed $v) => is_array($v) ? array_map(TypeConverter::toParameter(...), $v) : TypeConverter::toParameter($v), $params), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            'duration' => (int) ($this->cacheDuration ?? $options['duration'] ?? 1000),
        ];
        $saved = null;
        $failed = false;
        try {
            $saved = $cache->getFromCache($entry, $this->queryRunner);
            if ($saved !== null && ! $cache->isExpired($saved)) {
                $rows = json_decode($saved['result'], true);
                if (is_array($rows)) {
                    return $rows;
                }
            }
        } catch (\Throwable $e) {
            if (! $ignoreErrors) {
                throw $e;
            }
            $failed = true;
        }
        $rows = $conn->fetchAll($sql, $params);
        if ($failed) {
            return $rows;
        }
        try {
            $result = json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (\JsonException) {
            return $rows; // binary values / streams: not cacheable
        }
        try {
            $cache->storeInCache($entry + ['time' => (int) floor(microtime(true) * 1000), 'result' => $result], $saved, $this->queryRunner);
        } catch (\Throwable $e) {
            if (! $ignoreErrors) {
                throw $e;
            }
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<object>
     */
    private function hydrateRows(array $rows): array
    {
        $hydrator = $this->dataSource->hydrator();
        $rootAlias = (string) $this->alias;
        $roots = [];
        $identity = [];
        $attached = [];
        $selectJoins = array_values(array_filter($this->joins, fn ($j) => $j['select'] && $j['meta'] !== null && $j['parentAlias'] !== null && ($j['relation'] !== null || $j['mapTo'] !== null)));

        foreach ($rows as $row) {
            $rootPk = $this->rootKey($row);
            if ($rootPk === null) {
                continue;
            }
            if (! isset($roots[$rootPk])) {
                $roots[$rootPk] = $hydrator->hydrate($this->meta, $row, $rootAlias . '__');
                $this->initRelationProps($roots[$rootPk], $rootAlias, $selectJoins);
            }
            $rowEntities = [$rootAlias => $roots[$rootPk]];
            $rowKeys = [$rootAlias => $rootPk];

            foreach ($selectJoins as $join) {
                $parent = $rowEntities[$join['parentAlias']] ?? null;
                if ($parent === null) {
                    continue;
                }
                $alias = $join['alias'];
                $childPk = $row[$alias . '__' . $join['meta']->primary->name] ?? null;
                if ($childPk === null) {
                    continue;
                }
                if (! isset($identity[$alias][$childPk])) {
                    $identity[$alias][$childPk] = $hydrator->hydrate($join['meta'], $row, $alias . '__');
                    $this->initRelationProps($identity[$alias][$childPk], $alias, $selectJoins);
                }
                $child = $identity[$alias][$childPk];
                [$property, $many] = self::joinTarget($join);
                $parentKey = $join['parentAlias'] . ':' . $rowKeys[$join['parentAlias']];
                if ($many) {
                    if (! isset($attached[$alias][$parentKey][$childPk])) {
                        $attached[$alias][$parentKey][$childPk] = true;
                        $parent->{$property}[] = $child;
                    }
                } elseif ($join['mapTo'] === null || ! isset($attached[$alias][$parentKey])) {
                    $attached[$alias][$parentKey] = true; // mapOne keeps the first match
                    $parent->{$property} = $child;
                }
                $rowEntities[$alias] = $child;
                $rowKeys[$alias] = $childPk;
            }
        }

        // Remember the related ids that were loaded (to-many and inverse to-one), so saving the entity unchanged
        // writes nothing and rows removed from a collection can be told apart.
        $state = $this->dataSource->state();
        foreach ($selectJoins as $join) {
            $relation = $join['relation'];
            if ($relation === null || $join['mapTo'] !== null || $relation->holdsForeignKey() || $relation->isReadOnly() || $relation->type === RelationType::MorphTo) {
                continue;
            }
            $parents = $join['parentAlias'] === $rootAlias ? $roots : ($identity[$join['parentAlias']] ?? []);
            foreach ($parents as $parent) {
                $value = $parent->{$relation->property} ?? null;
                $related = is_array($value) ? $value : (is_object($value) ? [$value] : []);
                $state->setRelationIds($parent, $relation->property, array_map(
                    fn (object $c) => TypeConverter::toDatabase($join['meta']->primary, $join['meta']->primaryValue($c)),
                    $related,
                ));
            }
        }

        $relationIds = new RelationIdLoader($this->dataSource);
        $relationIds->load($this->meta, array_values($roots));
        foreach ($selectJoins as $join) {
            $relationIds->load($join['meta'], array_values($identity[$join['alias']] ?? []));
        }
        $byAlias = [$rootAlias => array_values($roots)];
        foreach ($selectJoins as $join) {
            $byAlias[$join['alias']] = array_values($identity[$join['alias']] ?? []);
        }
        foreach ($this->relationMaps as [$kind, $alias, $property, $relation, $condition, $relatedAlias]) {
            $entities = $byAlias[$alias] ?? [];
            if ($entities === []) {
                continue;
            }
            $meta = $this->aliasMeta[$alias];
            $values = $kind === 'count'
                ? $relationIds->counts($meta, $relation, $entities, $condition, $relatedAlias)
                : $relationIds->ids($meta, $relation, $entities, $condition, $relatedAlias);
            foreach ($entities as $entity) {
                RelationIdLoader::assign($entity, $property, $values[spl_object_id($entity)]);
            }
        }

        return array_values($roots);
    }

    /**
     * Identity of the root entity of a result row: its primary key — for a view entity (no key), all of its
     * columns, like TypeORM. Null when the row has none (a LEFT JOIN without root).
     *
     * @param array<string, mixed> $row
     */
    private function rootKey(array $row): string|int|null
    {
        $prefix = (string) $this->alias . '__';
        if ($this->meta->view === null) {
            $key = $row[$prefix . $this->meta->primary->name] ?? null;

            return is_int($key) || is_string($key) ? $key : ($key === null ? null : (string) $key);
        }
        $values = [];
        foreach ($this->meta->columns as $column) {
            if (array_key_exists($prefix . $column->name, $row)) {
                $values[] = $row[$prefix . $column->name];
            }
        }

        return $values === [] ? null : json_encode($values, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /** @return array{0: string, 1: bool} property a selected join fills on its parent, and whether it is a list */
    private static function joinTarget(array $join): array
    {
        return $join['mapTo'] !== null ? [$join['mapTo'], $join['mapMany']] : [$join['relation']->property, $join['relation']->isToMany()];
    }

    /** Give selected to-many relations an empty array (and nullable to-one relations null) before rows attach. */
    private function initRelationProps(object $entity, string $alias, array $selectJoins): void
    {
        foreach ($selectJoins as $join) {
            if ($join['parentAlias'] !== $alias) {
                continue;
            }
            [$property, $many] = self::joinTarget($join);
            if ($many) {
                $entity->{$property} = [];
            } elseif (Hydrator::allowsNull($entity, $property)) {
                $entity->{$property} = null;
            }
        }
    }

    /** Split "a, b(c, d), e" on top-level commas. @return list<string> */
    private function splitTopLevel(string $expr): array
    {
        $parts = [];
        $depth = 0;
        $current = '';
        foreach (str_split($expr) as $char) {
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }
            if ($char === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        if (trim($current) !== '') {
            $parts[] = $current;
        }

        return $parts;
    }
}
