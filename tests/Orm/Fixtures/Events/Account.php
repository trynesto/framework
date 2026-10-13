<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Events;

use DateTimeImmutable;
use Nesto\Orm\Attributes\AfterInsert;
use Nesto\Orm\Attributes\AfterLoad;
use Nesto\Orm\Attributes\AfterRecover;
use Nesto\Orm\Attributes\AfterRemove;
use Nesto\Orm\Attributes\AfterSoftRemove;
use Nesto\Orm\Attributes\AfterUpdate;
use Nesto\Orm\Attributes\BeforeInsert;
use Nesto\Orm\Attributes\BeforeRecover;
use Nesto\Orm\Attributes\BeforeRemove;
use Nesto\Orm\Attributes\BeforeSoftRemove;
use Nesto\Orm\Attributes\BeforeUpdate;
use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\CreateDateColumn;
use Nesto\Orm\Attributes\DeleteDateColumn;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\OneToMany;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;
use Nesto\Orm\Attributes\UpdateDateColumn;
use Nesto\Orm\Attributes\VersionColumn;

#[Entity(name: 'ev_accounts')]
class Account extends Audited
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $owner;

    #[Column(unique: true)]
    public string $email;

    #[Column]
    public int $balance = 0;

    #[Column(nullable: true)]
    public ?string $slug = null;

    #[VersionColumn]
    public int $version;

    #[CreateDateColumn]
    public ?DateTimeImmutable $createdAt = null;

    #[UpdateDateColumn]
    public ?DateTimeImmutable $updatedAt = null;

    #[DeleteDateColumn]
    public ?DateTimeImmutable $deletedAt = null;

    /** @var list<AccountEntry> */
    #[OneToMany(AccountEntry::class, inverseSide: 'account', cascade: ['insert', 'update', 'remove'])]
    public array $entries;

    /** Set by AfterLoad (not a column). */
    public ?string $label = null;

    #[BeforeInsert]
    public function fillSlug(): void
    {
        EventLog::add('beforeInsert ' . $this->owner);
        $this->slug ??= strtolower($this->owner);
    }

    #[AfterInsert]
    public function inserted(): void
    {
        EventLog::add('afterInsert ' . $this->owner . ' #' . $this->id);
    }

    #[BeforeUpdate]
    protected function refreshSlug(): void
    {
        EventLog::add('beforeUpdate ' . $this->owner);
        $this->slug = strtolower($this->owner);
    }

    #[AfterUpdate]
    public function updated(): void
    {
        EventLog::add('afterUpdate ' . $this->owner . ' v' . $this->version);
    }

    #[BeforeRemove]
    public function removing(): void
    {
        EventLog::add('beforeRemove ' . $this->owner);
    }

    #[AfterRemove]
    public function removed(): void
    {
        EventLog::add('afterRemove ' . $this->owner);
    }

    #[BeforeSoftRemove]
    public function softRemoving(): void
    {
        EventLog::add('beforeSoftRemove ' . $this->owner);
    }

    #[AfterSoftRemove]
    public function softRemoved(): void
    {
        EventLog::add('afterSoftRemove ' . $this->owner);
    }

    #[BeforeRecover]
    public function recovering(): void
    {
        EventLog::add('beforeRecover ' . $this->owner);
    }

    #[AfterRecover]
    public function recovered(): void
    {
        EventLog::add('afterRecover ' . $this->owner);
    }

    #[AfterLoad]
    private function computeLabel(): void
    {
        $entries = (new \ReflectionProperty($this, 'entries'))->isInitialized($this) ? count($this->entries) . ' entries' : 'entries not loaded';
        $this->label = "$this->owner ($entries)";
        EventLog::add('afterLoad ' . $this->owner);
    }
}
