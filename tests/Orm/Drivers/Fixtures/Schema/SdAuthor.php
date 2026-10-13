<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Drivers\Fixtures\Schema;

use DateTimeImmutable;
use Nesto\Orm\Attributes\Check;
use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\DeleteDateColumn;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\Index;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;
use Nesto\Orm\Attributes\Unique;
use Nesto\Orm\SqlExpression;

/** Schema-diff fixture, version 1: one column per type and option the diff has to round-trip. */
#[Entity(name: 'sd_authors')]
#[Index(columns: ['status', 'createdAt'])]
#[Index(columns: ['email'], name: 'idx_sd_authors_live_email', where: 'deleted_at IS NULL')]
#[Check('chk_sd_authors_rating', 'rating >= 0')]
#[Check('balance >= 0')]
#[Unique(['name', 'code'])]
final class SdAuthor
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column(length: 80, comment: 'Display name')]
    public string $name;

    #[Column(unique: true)]
    public string $email;

    #[Column(type: 'char', length: 3, nullable: true)]
    public ?string $code = null;

    #[Column(type: 'smallint', unsigned: true)]
    public int $rating = 0;

    #[Column(type: 'bigint', nullable: true)]
    public ?int $views = null;

    #[Column(type: 'decimal', precision: 12, scale: 3)]
    public string $balance = '0.000';

    #[Column(type: 'double', nullable: true)]
    public ?float $score = null;

    #[Column]
    public bool $active = true;

    #[Column(type: 'text', nullable: true)]
    public ?string $bio = null;

    #[Column(type: 'json')]
    public array $settings = ['theme' => 'dark'];

    #[Column(type: 'date', nullable: true)]
    public ?DateTimeImmutable $birthday = null;

    #[Column(type: 'time', nullable: true)]
    public ?DateTimeImmutable $wakeUp = null;

    #[Column(type: 'timestamp', default: new SqlExpression('CURRENT_TIMESTAMP'))]
    public ?DateTimeImmutable $createdAt = null;

    #[Column(type: 'timestamptz', precision: 3, nullable: true)]
    public ?DateTimeImmutable $loggedAt = null;

    #[Column(type: 'enum', enum: ['draft', 'live'])]
    public string $status = 'draft';

    #[Column(type: 'enum', enum: ['happy', 'sad'], enumName: 'sd_mood', nullable: true)]
    public ?string $mood = null;

    #[Column(type: 'blob', nullable: true)]
    public ?string $avatar = null;

    #[Column(type: 'uuid', nullable: true)]
    public ?string $externalId = null;

    #[DeleteDateColumn]
    public ?DateTimeImmutable $deletedAt = null;
}
