<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;

/**
 * Unique constraint (TypeORM's @Unique), on the entity class or on a property.
 *
 *   #[Unique(['firstName', 'lastName'])]                 // column or property names
 *   #[Unique('uq_full_name', ['firstName', 'lastName'])] // named
 *   #[Unique] #[Column] public string $email;            // on a property: that column
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final class Unique
{
    /** @var list<string> */
    public readonly array $columns;
    public readonly ?string $name;

    /** @param list<string>|string|null $name the constraint name, or the columns when no name is given */
    public function __construct(array|string|null $name = null, array $columns = [])
    {
        $this->columns = is_array($name) ? array_values($name) : array_values($columns);
        $this->name = is_string($name) ? $name : null;
    }
}
