<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Bank;

use Nesto\Orm\DataSource;
use Nesto\Orm\Events\EntitySubscriberInterface;
use Nesto\Orm\Events\InsertEvent;

/** The @nestjs/typeorm pattern: a provider that pushes itself into dataSource.subscribers. */
final class NestStyleSubscriber implements EntitySubscriberInterface
{
    public int $inserts = 0;

    public function __construct(DataSource $dataSource)
    {
        $dataSource->subscribers[] = $this;
    }

    public function beforeInsert(InsertEvent $event): void
    {
        $this->inserts++;
    }
}
