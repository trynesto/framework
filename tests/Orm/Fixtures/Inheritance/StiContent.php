<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Inheritance;

use DateTimeImmutable;
use Nesto\Orm\Attributes\BeforeInsert;
use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\CreateDateColumn;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\Index;
use Nesto\Orm\Attributes\ManyToOne;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;
use Nesto\Orm\Attributes\TableInheritance;
use Nesto\Orm\Attributes\VersionColumn;

/** Single table inheritance root (table sti_contents): StiPhoto, StiVideo and StiHdVideo share it. */
#[Entity('sti_contents')]
#[TableInheritance(column: ['name' => 'kind', 'type' => 'varchar', 'length' => 32])]
#[Index(['title'])]
abstract class StiContent
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $title;

    #[ManyToOne(StiAuthor::class, inverseSide: 'contents', onDelete: 'CASCADE')]
    public ?StiAuthor $author = null;

    #[CreateDateColumn]
    public DateTimeImmutable $createdAt;

    #[VersionColumn]
    public int $version;

    #[BeforeInsert]
    public function trimTitle(): void
    {
        $this->title = trim($this->title);
    }
}
