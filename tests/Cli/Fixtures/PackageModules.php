<?php

declare(strict_types=1);

namespace Nesto\Tests\Cli\Fixtures;

use Nesto\Common\Attributes\Module;
use Nesto\Orm\OrmModule;
use Nesto\Queue\QueueModule;
use Nesto\Schedule\ScheduleModule;

#[Module(imports: [new OrmModule(type: 'sqlite', database: ':memory:')])]
final class OrmAppModule
{
}

#[Module(imports: [new QueueModule(driver: 'sync')])]
final class QueueAppModule
{
}

#[Module(imports: [new ScheduleModule(storagePath: '/tmp/nesto-cli-schedule-test')])]
final class ScheduleAppModule
{
}

#[Module]
final class BareModule
{
}
