<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Bank;

use Nesto\Common\Attributes\Injectable;
use Nesto\Orm\Attributes\InjectDataSource;
use Nesto\Orm\Attributes\InjectEntityManager;
use Nesto\Orm\Attributes\InjectRepository;
use Nesto\Orm\DataSource;
use Nesto\Orm\EntityManager;
use Nesto\Orm\Repository;
use Nesto\Tests\Orm\Fixtures\Events\Account;
use Nesto\Tests\Orm\Fixtures\Events\AccountRepository;
use Nesto\Tests\Orm\Fixtures\Tag;

#[Injectable]
final class BankService
{
    public function __construct(
        public readonly AccountRepository $accounts,
        #[InjectRepository(Account::class)] public readonly Repository $viaToken,
        #[InjectDataSource] public readonly DataSource $dataSource,
        #[InjectEntityManager] public readonly EntityManager $manager,
        #[InjectDataSource('analytics')] public readonly DataSource $analytics,
        #[InjectEntityManager('analytics')] public readonly EntityManager $analyticsManager,
        #[InjectRepository(Tag::class, 'analytics')] public readonly Repository $tags,
    ) {}
}
