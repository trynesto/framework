<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\SchemaOptions;

use Nesto\Orm\Attributes\Check;
use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\Index;
use Nesto\Orm\Attributes\JoinColumn;
use Nesto\Orm\Attributes\JoinTable;
use Nesto\Orm\Attributes\ManyToMany;
use Nesto\Orm\Attributes\ManyToOne;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;
use Nesto\Orm\Attributes\Unique;
use Nesto\Orm\SqlExpression;
use Nesto\Tests\Orm\Fixtures\PostStatus;

#[Entity(name: 'opt_owners')]
#[Unique('uq_opt_owners_names', ['firstName', 'lastName'])]
#[Check('age >= 0')]
#[Check('chk_named', 'age < 200')]
final class OptOwner
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column(length: 40, comment: 'Given name', charset: 'utf8mb4', collation: 'utf8mb4_bin')]
    public string $firstName;

    #[Index('idx_opt_last', where: 'last_name IS NOT NULL')]
    #[Column(nullable: true)]
    public ?string $lastName = null;

    #[Unique]
    #[Column(type: 'char', length: 2)]
    public string $code;

    #[Column(type: 'smallint', unsigned: true)]
    public int $age = 0;

    #[Column(type: 'enum', enum: ['a', 'b'], enumName: 'opt_kind')]
    public string $kind = 'a';

    #[Column(enum: PostStatus::class)]
    public string $status = 'draft';

    #[Column(type: 'timestamp', default: new SqlExpression('CURRENT_TIMESTAMP'))]
    public ?\DateTimeImmutable $seenAt = null;
}

#[Entity(name: 'opt_pets')]
final class OptPet
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[ManyToOne(OptOwner::class, onDelete: 'CASCADE', onUpdate: 'CASCADE')]
    #[JoinColumn(name: 'owner_ref', foreignKeyConstraintName: 'fk_pet_owner')]
    public ?OptOwner $owner = null;

    #[ManyToOne(OptOwner::class, createForeignKeyConstraints: false)]
    public ?OptOwner $vet = null;

    /** @var list<OptToy> */
    #[ManyToMany(OptToy::class, onDelete: 'RESTRICT', onUpdate: 'CASCADE')]
    #[JoinTable(name: 'opt_pet_toys', joinColumn: ['name' => 'pet_id', 'foreignKeyConstraintName' => 'fk_pt_pet'], inverseJoinColumn: 'toy_id')]
    public array $toys = [];

    /** @var list<OptToy> */
    #[ManyToMany(OptToy::class)]
    #[JoinTable(name: 'opt_legacy_toys', synchronize: false)]
    public array $legacyToys = [];
}

#[Entity(name: 'opt_toys')]
final class OptToy
{
    #[PrimaryGeneratedColumn]
    public int $id;
}

#[Entity(name: 'opt_views', synchronize: false)]
final class OptView
{
    #[PrimaryGeneratedColumn]
    public int $id;
}
