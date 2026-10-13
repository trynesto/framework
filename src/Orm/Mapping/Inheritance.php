<?php

declare(strict_types=1);

namespace Nesto\Orm\Mapping;

use Nesto\Orm\Attributes\ChildEntity;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\TableInheritance;
use Nesto\Orm\OrmException;
use ReflectionClass;

/**
 * Single table inheritance lookups read straight from the attributes (no metadata is built, so a root and its
 * children can be resolved in any order): the root of a class, discriminator values, and the child entities
 * below a class. Children are found among the declared classes and the DataSources' entity lists (register()),
 * like TypeORM finds them among the registered entities.
 */
final class Inheritance
{
    /** @var array<class-string, true> entity classes of the DataSources (class-level, the same for every request) */
    private static array $known = [];

    /** @param list<class-string> $classes declare the classes (autoload) so the roots find their children */
    public static function register(array $classes): void
    {
        foreach ($classes as $class) {
            if (! isset(self::$known[$class]) && class_exists($class)) {
                self::$known[$class] = true;
            }
        }
    }

    /** The #[TableInheritance] root of an entity class (itself for the root), null for a regular entity. */
    public static function rootOf(string $class): ?string
    {
        $ref = new ReflectionClass($class);
        $child = $ref->getAttributes(ChildEntity::class) !== [];
        for ($current = $ref; $current !== false; $current = $current->getParentClass()) {
            if ($current->getAttributes(TableInheritance::class) !== []) {
                if ($current->getAttributes(Entity::class) === []) {
                    throw new OrmException("#[TableInheritance] on {$current->getName()} needs #[Entity] on the same class.");
                }

                return $current->getName();
            }
            if (! $child) {
                return null; // a regular #[Entity]: only its own attributes count
            }
        }

        throw new OrmException("#[ChildEntity] $class must extend an #[Entity] marked #[TableInheritance] (single table inheritance).");
    }

    /** Discriminator value of a root (its short class name) or a child (#[ChildEntity] value or short class name). */
    public static function discriminatorValue(string $class): string
    {
        $attr = ((new ReflectionClass($class))->getAttributes(ChildEntity::class)[0] ?? null)?->newInstance();

        return $attr?->discriminatorValue ?? Naming::shortName($class);
    }

    /**
     * Discriminator column of a root: [name, type, length].
     *
     * @return array{0: string, 1: string, 2: ?int}
     */
    public static function column(string $root): array
    {
        $attr = (new ReflectionClass($root))->getAttributes(TableInheritance::class)[0]->newInstance();
        if (strtoupper($attr->pattern) !== 'STI') {
            throw new OrmException("$root: #[TableInheritance] only supports the \"STI\" pattern (single table inheritance), got \"{$attr->pattern}\".");
        }
        $type = (string) ($attr->column['type'] ?? 'varchar');
        $type = in_array(strtolower($type), ['varchar', 'string', 'character varying'], true) ? 'string' : $type;
        $length = $attr->column['length'] ?? ($type === 'string' ? 255 : null);

        return [(string) ($attr->column['name'] ?? 'type'), $type, $length === null ? null : (int) $length];
    }

    /**
     * Value => class for $class and every #[ChildEntity] below it (declared or registered classes).
     *
     * @return array<string, class-string>
     */
    public static function discriminatorMap(string $class): array
    {
        $map = [self::discriminatorValue($class) => $class];
        $candidates = array_unique([...get_declared_classes(), ...array_keys(self::$known)]);
        foreach ($candidates as $candidate) {
            if (! is_subclass_of($candidate, $class)) {
                continue;
            }
            if ((new ReflectionClass($candidate))->getAttributes(ChildEntity::class) === []) {
                continue;
            }
            $value = self::discriminatorValue($candidate);
            if (isset($map[$value]) && $map[$value] !== $candidate) {
                throw new OrmException("$candidate and {$map[$value]} share the discriminator value \"$value\": give one of them #[ChildEntity('...')].");
            }
            $map[$value] = $candidate;
        }

        return $map;
    }
}
