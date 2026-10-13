<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Bank;

use Nesto\Common\Attributes\Controller;
use Nesto\Common\Attributes\Post;
use Nesto\Common\Attributes\UseInterceptors;
use Nesto\Orm\Attributes\Transactional;
use Nesto\Orm\TransactionalInterceptor;

#[Controller('bank')]
#[UseInterceptors(TransactionalInterceptor::class)]
final class BankController
{
    public function __construct(private readonly BankService $bank) {}

    #[Post('open')]
    #[Transactional(isolationLevel: 'READ UNCOMMITTED')]
    public function open(): array
    {
        $conn = $this->bank->dataSource->getConnection();
        $this->bank->accounts->save($this->bank->accounts->create(['owner' => 'Ada', 'email' => 'ada@x.io']));

        return [
            'depth' => $conn->transactionDepth(),
            'readUncommitted' => (int) $conn->fetchColumn('PRAGMA read_uncommitted'),
            'runner' => $this->bank->dataSource->currentQueryRunner()->isTransactionActive(),
        ];
    }

    #[Post('tag')]
    #[Transactional(dataSource: 'analytics')]
    public function tag(): array
    {
        $this->bank->tags->save($this->bank->tags->create(['name' => 'php']));

        return [
            'analytics' => $this->bank->analytics->getConnection()->transactionDepth(),
            'default' => $this->bank->dataSource->getConnection()->transactionDepth(),
        ];
    }
}
