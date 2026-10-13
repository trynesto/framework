<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Events;

use Nesto\Orm\Attributes\AfterInsert;
use Nesto\Orm\Attributes\AfterLoad;
use Nesto\Orm\Attributes\BeforeInsert;
use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\JoinColumn;
use Nesto\Orm\Attributes\ManyToOne;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

#[Entity(name: 'ev_entries')]
class AccountEntry
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public int $amount;

    #[ManyToOne(Account::class, inverseSide: 'entries', onDelete: 'CASCADE')]
    #[JoinColumn(name: 'account_id')]
    public Account $account;

    #[BeforeInsert]
    public function beforeInsert(): void
    {
        EventLog::add('beforeInsert entry ' . $this->amount);
    }

    #[AfterInsert]
    public function afterInsert(): void
    {
        EventLog::add('afterInsert entry ' . $this->amount);
    }

    #[AfterLoad]
    public function loaded(): void
    {
        EventLog::add('afterLoad entry ' . $this->amount);
    }
}
