<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Drivers\Fixtures\Schema\V2;

use DateTimeImmutable;
use Nesto\Orm\Attributes\Check;
use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\DeleteDateColumn;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\Index;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;
use Nesto\Orm\SqlExpression;

/**
 * Version 2 of `sd_authors`: name longer (+ comment), email no longer unique but indexed, code and avatar
 * dropped, nickname added (NOT NULL, no default), rating smallint → int, views made NOT NULL with a default,
 * other defaults changed, enum values added (MySQL ENUM and a PostgreSQL native enum), the (status,
 * created_at) index and the unnamed balance check removed, a views check added.
 */
#[Entity(name: 'sd_authors')]
#[Index(columns: ['email'], name: 'idx_sd_authors_live_email', where: 'deleted_at IS NULL')]
#[Check('chk_sd_authors_rating', 'rating >= 0')]
#[Check('views >= 0')]
final class SdAuthor
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column(length: 120, comment: 'Shown name')]
    public string $name;

    #[Index]
    #[Column]
    public string $email;

    #[Column]
    public string $nickname;

    #[Column(type: 'int', unsigned: true)]
    public int $rating = 0;

    #[Column(type: 'bigint')]
    public int $views = 0;

    #[Column(type: 'decimal', precision: 12, scale: 3)]
    public string $balance = '1.500';

    #[Column(type: 'double', nullable: true)]
    public ?float $score = null;

    #[Column]
    public bool $active = false;

    #[Column(type: 'text', nullable: true)]
    public ?string $bio = null;

    #[Column(type: 'json')]
    public array $settings = ['theme' => 'light'];

    #[Column(type: 'date', nullable: true)]
    public ?DateTimeImmutable $birthday = null;

    #[Column(type: 'time', nullable: true)]
    public ?DateTimeImmutable $wakeUp = null;

    #[Column(type: 'timestamp', default: new SqlExpression('CURRENT_TIMESTAMP'))]
    public ?DateTimeImmutable $createdAt = null;

    #[Column(type: 'timestamptz', precision: 3, nullable: true)]
    public ?DateTimeImmutable $loggedAt = null;

    #[Column(type: 'enum', enum: ['draft', 'live', 'archived'])]
    public string $status = 'draft';

    #[Column(type: 'enum', enum: ['happy', 'sad', 'meh'], enumName: 'sd_mood', nullable: true)]
    public ?string $mood = null;

    #[Column(type: 'uuid', nullable: true)]
    public ?string $externalId = null;

    #[DeleteDateColumn]
    public ?DateTimeImmutable $deletedAt = null;
}
