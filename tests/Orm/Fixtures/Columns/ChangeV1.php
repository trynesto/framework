<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Columns;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

/** Version 1 of col_changes (see ChangeV2): the driver suite synchronizes one after the other. */
#[Entity(name: 'col_changes')]
final class ChangeV1
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column(type: 'int')]
    public int $base = 0;

    #[Column(type: 'int', generatedType: 'STORED', asExpression: 'base * 2')]
    public ?int $derived = null;

    #[Column(type: 'enum', enum: ['draft', 'live'])]
    public string $state = 'draft';

    #[Column(type: 'simple-array')]
    public array $labels = [];
}
