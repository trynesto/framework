<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Bank;

use Nesto\Orm\DataSource;
use Nesto\Orm\Events\EntitySubscriberInterface;
use Nesto\Orm\Events\TransactionCommitEvent;

/** Listed in OrmModule's `subscribers` option; its constructor dependencies are injected. */
final class OptionSubscriber implements EntitySubscriberInterface
{
    public int $commits = 0;

    public function __construct(public readonly DataSource $dataSource) {}

    public function afterTransactionCommit(TransactionCommitEvent $event): void
    {
        $this->commits++;
    }
}
