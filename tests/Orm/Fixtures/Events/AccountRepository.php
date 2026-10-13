<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Events;

use Nesto\Orm\Attributes\CustomRepository;
use Nesto\Orm\Repository;

/** Custom repository (TypeORM 0.3 style: a class extending Repository). */
#[CustomRepository(Account::class)]
class AccountRepository extends Repository
{
    public function richest(): ?Account
    {
        return $this->findOne(['order' => ['balance' => 'DESC']]);
    }
}
