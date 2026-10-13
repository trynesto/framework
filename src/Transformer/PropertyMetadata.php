<?php

declare(strict_types=1);

namespace Nesto\Transformer;

use Nesto\Transformer\Attributes\Exclude;
use Nesto\Transformer\Attributes\Expose;
use Nesto\Transformer\Attributes\Transform;
use Nesto\Transformer\Attributes\Type;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;

/**
 * Everything ClassTransformer needs to know about one public property, computed once per class.
 * @internal
 */
final class PropertyMetadata
{
    public readonly string $name;
    public readonly ?Expose $expose;
    public readonly ?Exclude $exclude;
    public readonly ?Type $type;
    public readonly ?Transform $transform;
    public readonly bool $promoted;
    public readonly bool $readonly;
    public readonly bool $nullable;
    /** @var list<string> declared type names (builtin lower-case or class-string), empty when untyped */
    public readonly array $types;

    public function __construct(public readonly ReflectionProperty $reflection)
    {
        $this->name = $reflection->getName();
        $this->expose = self::attr($reflection, Expose::class);
        $this->exclude = self::attr($reflection, Exclude::class);
        $this->type = self::attr($reflection, Type::class);
        $this->transform = self::attr($reflection, Transform::class);
        $this->promoted = $reflection->isPromoted();
        $this->readonly = $reflection->isReadOnly();
        $t = $reflection->getType();
        $this->nullable = $t === null || $t->allowsNull();
        $this->types = self::typeNames($t);
    }

    /** The key used in the plain representation. */
    public function plainKey(?string $namingStrategy): string
    {
        if ($this->expose?->name !== null) {
            return $this->expose->name;
        }

        return match ($namingStrategy) {
            'snake' => strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $this->name)),
            'camel' => lcfirst(str_replace('_', '', ucwords($this->name, '_'))),
            default => $this->name,
        };
    }

    /** @return list<string> */
    public function groups(): array
    {
        return $this->expose?->groups ?? [];
    }

    public function isExcludedToClass(): bool
    {
        return ($this->exclude !== null && ! $this->exclude->toPlainOnly)
            || ($this->expose !== null && $this->expose->toPlainOnly);
    }

    public function isExcludedToPlain(): bool
    {
        return ($this->exclude !== null && ! $this->exclude->toClassOnly)
            || ($this->expose !== null && $this->expose->toClassOnly);
    }

    /** First declared class type (for nested hydration), or null. */
    public function classType(): ?string
    {
        foreach ($this->types as $t) {
            if (class_exists($t) || interface_exists($t) || enum_exists($t)) {
                return $t;
            }
        }

        return null;
    }

    public function hasType(string $builtin): bool
    {
        return in_array($builtin, $this->types, true);
    }

    /** @return list<string> */
    private static function typeNames(?ReflectionType $t): array
    {
        if ($t instanceof ReflectionNamedType) {
            return $t->getName() === 'null' ? [] : [$t->isBuiltin() ? strtolower($t->getName()) : ltrim($t->getName(), '\\')];
        }
        if ($t instanceof ReflectionUnionType) {
            $out = [];
            foreach ($t->getTypes() as $inner) {
                $out = [...$out, ...self::typeNames($inner)];
            }
            // reflection canonicalises the order (string|int); cast attempts go from strict to permissive
            $rank = ['int' => 1, 'float' => 2, 'bool' => 3, 'array' => 4, 'iterable' => 5, 'object' => 6, 'string' => 8, 'mixed' => 9];
            usort($out, static fn ($a, $b) => ($rank[$a] ?? 0) <=> ($rank[$b] ?? 0));

            return $out;
        }

        return [];
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T|null
     */
    private static function attr(ReflectionProperty $p, string $class): ?object
    {
        $a = $p->getAttributes($class)[0] ?? null;

        return $a?->newInstance();
    }
}
