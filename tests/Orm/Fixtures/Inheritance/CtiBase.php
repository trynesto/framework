<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Inheritance;

use DateTimeImmutable;
use Nesto\Orm\Attributes\BeforeInsert;
use Nesto\Orm\Attributes\BeforeUpdate;
use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\CreateDateColumn;
use Nesto\Orm\Attributes\DeleteDateColumn;
use Nesto\Orm\Attributes\Embedded;
use Nesto\Orm\Attributes\Index;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;
use Nesto\Orm\Attributes\UpdateDateColumn;
use Nesto\Orm\Attributes\VersionColumn;

/** Concrete table inheritance: not an entity; CtiCat and CtiDog each get its columns in their own table. */
#[Index(['name'])]
abstract class CtiBase
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $name;

    #[Embedded(CtiAudit::class, prefix: 'audit_')]
    public CtiAudit $audit;

    #[CreateDateColumn]
    public DateTimeImmutable $createdAt;

    #[UpdateDateColumn]
    public DateTimeImmutable $updatedAt;

    #[DeleteDateColumn]
    public ?DateTimeImmutable $deletedAt = null;

    #[VersionColumn]
    public int $version;

    /** @var list<string> */
    public array $calls = [];

    #[BeforeInsert]
    public function beforeInsertBase(): void
    {
        $this->calls[] = 'base insert';
    }

    #[BeforeUpdate]
    public function beforeUpdateBase(): void
    {
        $this->calls[] = 'base update';
    }
}
