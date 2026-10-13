<?php

declare(strict_types=1);

namespace Nesto\Orm\Mapping;

enum RelationType: string
{
    case ManyToOne = 'many-to-one';
    case OneToMany = 'one-to-many';
    case OneToOne = 'one-to-one';
    case ManyToMany = 'many-to-many';
    // polymorphic (Laravel's morphTo / morphOne / morphMany / morphToMany / morphedByMany)
    case MorphTo = 'morph-to';
    case MorphOne = 'morph-one';
    case MorphMany = 'morph-many';
    case MorphToMany = 'morph-to-many';
    case MorphedByMany = 'morphed-by-many';
    // read-only "through" relations
    case HasOneThrough = 'has-one-through';
    case HasManyThrough = 'has-many-through';
    case BelongsToThrough = 'belongs-to-through';

    public function isToMany(): bool
    {
        return match ($this) {
            self::OneToMany, self::ManyToMany, self::MorphMany, self::MorphToMany, self::MorphedByMany, self::HasManyThrough => true,
            default => false,
        };
    }

    public function isMorph(): bool
    {
        return match ($this) {
            self::MorphTo, self::MorphOne, self::MorphMany, self::MorphToMany, self::MorphedByMany => true,
            default => false,
        };
    }

    public function isThrough(): bool
    {
        return match ($this) {
            self::HasOneThrough, self::HasManyThrough, self::BelongsToThrough => true,
            default => false,
        };
    }

    /** Relations backed by a pivot table (ManyToMany and the polymorphic MorphToMany / MorphedByMany). */
    public function usesPivot(): bool
    {
        return $this === self::ManyToMany || $this === self::MorphToMany || $this === self::MorphedByMany;
    }
}
