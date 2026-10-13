<?php

declare(strict_types=1);

namespace Nesto\Orm;

use WeakMap;

/**
 * Per-DataSource bookkeeping about loaded entities, kept OUTSIDE the entity objects
 * (so get_object_vars()/JSON only ever see the user's own properties):
 * the original row snapshot (for dirty checking and foreign keys), the ids of loaded relations
 * (so save() only writes join-table rows / orphan updates that changed), relation properties still
 * holding their class default since hydration ("not loaded"), and pending foreign keys set by cascades.
 */
final class EntityState
{
    private const EMPTY = ['row' => [], 'm2m' => [], 'fk' => [], 'defaults' => [], 'given' => []];

    /** @var WeakMap<object, array{row: array<string, mixed>, m2m: array<string, list<mixed>>, fk: array<string, mixed>, defaults: array<string, true>, given: array<string, true>}> */
    private WeakMap $map;

    public function __construct()
    {
        $this->map = new WeakMap;
    }

    /** @return array<string, mixed>|null raw column => db value as last read/written */
    public function snapshot(object $entity): ?array
    {
        return $this->map[$entity]['row'] ?? null;
    }

    /** @param array<string, mixed> $row */
    public function setSnapshot(object $entity, array $row): void
    {
        $entry = $this->map[$entity] ?? self::EMPTY;
        $entry['row'] = $row;
        $this->map[$entity] = $entry;
    }

    public function isManaged(object $entity): bool
    {
        return isset($this->map[$entity]) && $this->map[$entity]['row'] !== [];
    }

    /**
     * Database ids of the related entities a relation held when it was loaded / last saved
     * (to-many and inverse to-one relations), or null when unknown.
     *
     * @return list<mixed>|null
     */
    public function relationIds(object $entity, string $property): ?array
    {
        return $this->map[$entity]['m2m'][$property] ?? null;
    }

    /**
     * Remember the ids a relation holds now (loaded or saved). The property is no longer "untouched default".
     *
     * @param list<mixed> $ids
     */
    public function setRelationIds(object $entity, string $property, array $ids): void
    {
        $entry = $this->map[$entity] ?? self::EMPTY;
        $entry['m2m'][$property] = array_values($ids);
        unset($entry['defaults'][$property]);
        $this->map[$entity] = $entry;
    }

    /** @return list<mixed>|null */
    public function manyToManyIds(object $entity, string $property): ?array
    {
        return $this->relationIds($entity, $property);
    }

    /** @param list<mixed> $ids */
    public function setManyToManyIds(object $entity, string $property, array $ids): void
    {
        $this->setRelationIds($entity, $property, $ids);
    }

    /**
     * Relation properties that hydration left at their class default (`public ?Profile $profile = null;`,
     * `public array $posts = [];`): like TypeORM's undefined, save() ignores them until they are loaded or set.
     *
     * @param list<string> $properties
     */
    public function markDefaultRelations(object $entity, array $properties): void
    {
        if ($properties === []) {
            return;
        }
        $entry = $this->map[$entity] ?? self::EMPTY;
        foreach ($properties as $property) {
            $entry['defaults'][$property] = true;
        }
        $this->map[$entity] = $entry;
    }

    public function isDefaultRelation(object $entity, string $property): bool
    {
        return isset($this->map[$entity]['defaults'][$property]);
    }

    /** The relation was loaded or explicitly assigned: save() handles it from now on. */
    public function touchRelation(object $entity, string $property): void
    {
        if (isset($this->map[$entity]['defaults'][$property])) {
            $entry = $this->map[$entity];
            unset($entry['defaults'][$property]);
            $this->map[$entity] = $entry;
        }
    }

    public function pendingForeignKey(object $entity, string $column): mixed
    {
        return $this->map[$entity]['fk'][$column] ?? null;
    }

    public function setPendingForeignKey(object $entity, string $column, mixed $value): void
    {
        $entry = $this->map[$entity] ?? self::EMPTY;
        $entry['fk'][$column] = $value;
        $this->map[$entity] = $entry;
    }

    /**
     * Properties given to create() / merge() (dotted paths for #[Embedded] values): a value given there is meant,
     * even when it equals the class default — see Persistence\Persister's handling of entities never loaded.
     *
     * @param list<string> $properties
     */
    public function markGiven(object $entity, array $properties): void
    {
        if ($properties === []) {
            return;
        }
        $entry = $this->map[$entity] ?? self::EMPTY;
        foreach ($properties as $property) {
            $entry['given'][$property] = true;
        }
        $this->map[$entity] = $entry;
    }

    /** Was the property (or the #[Embedded] object holding it) given to create() / merge()? */
    public function isGiven(object $entity, string $property): bool
    {
        $given = $this->map[$entity]['given'] ?? [];
        if ($given === []) {
            return false;
        }
        for ($path = $property; ; $path = substr($path, 0, (int) strrpos($path, '.'))) {
            if (isset($given[$path])) {
                return true;
            }
            if (! str_contains($path, '.')) {
                return false;
            }
        }
    }

    public function forget(object $entity): void
    {
        unset($this->map[$entity]);
    }
}
