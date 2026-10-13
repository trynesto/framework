<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Events;

use Nesto\Orm\Attributes\BeforeInsert;
use Nesto\Orm\Attributes\BeforeUpdate;

/** Parent class with listeners: they run before the child's. */
abstract class Audited
{
    #[BeforeInsert]
    private function auditInsert(): void
    {
        EventLog::add('parent beforeInsert');
    }

    #[BeforeUpdate]
    public function auditUpdate(): void
    {
        EventLog::add('parent beforeUpdate');
    }
}
