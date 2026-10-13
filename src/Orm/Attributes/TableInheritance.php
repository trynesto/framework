<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;

/**
 * Single table inheritance root (TypeORM's @TableInheritance): the #[Entity] parent and every #[ChildEntity]
 * class extending it share ONE table; a discriminator column tells the rows apart.
 *
 *   #[Entity('contents')]
 *   #[TableInheritance(column: ['name' => 'type', 'type' => 'varchar'])]
 *   abstract class Content { #[PrimaryGeneratedColumn] public int $id; #[Column] public string $title; }
 *
 *   #[ChildEntity('photo')] class Photo extends Content { #[Column] public int $size; }
 *   #[ChildEntity]          class Video extends Content { ... }      // discriminator value "Video"
 *
 * `column`: name (default "type"), type (default varchar), length (default 255). `pattern` is "STI" (the only one).
 * The child classes must be registered in the DataSource (entities) or loaded before the root is first used.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class TableInheritance
{
    /** @param array{name?: string, type?: string, length?: int} $column */
    public function __construct(
        public readonly array $column = [],
        public readonly string $pattern = 'STI',
    ) {}
}
