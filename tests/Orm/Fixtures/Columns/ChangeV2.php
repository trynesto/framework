<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Columns;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

/** Version 2 of col_changes: another generated expression, one more enum value, a new generated column. */
#[Entity(name: 'col_changes')]
final class ChangeV2
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column(type: 'int')]
    public int $base = 0;

    #[Column(type: 'int', generatedType: 'STORED', asExpression: 'base * 3')]
    public ?int $derived = null;

    #[Column(type: 'int', generatedType: 'STORED', asExpression: 'base + 100')]
    public ?int $shifted = null;

    #[Column(type: 'enum', enum: ['draft', 'live', 'archived'])]
    public string $state = 'draft';

    #[Column(type: 'simple-array')]
    public array $labels = [];
}
