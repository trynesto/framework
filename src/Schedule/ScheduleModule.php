<?php

declare(strict_types=1);

namespace Nesto\Schedule;

use Nesto\Common\Contracts\DynamicModuleFactory;
use Nesto\Common\DynamicModule;
use Nesto\Common\Provider;
use Psr\Log\LoggerInterface;

/**
 * Nesto's @nestjs/schedule. Global by default; provides SchedulerRegistry, CronParser, Scheduler and
 * the ScheduleExplorer that registers #[Cron] / #[Interval] / #[Timeout] methods on bootstrap.
 *
 *   #[Module(imports: [new ScheduleModule()])]            // or ScheduleModule::forRoot(['storagePath' => ...])
 *   final class AppModule {}
 *
 *   final class TasksService {
 *       #[Cron(CronExpression::EVERY_MINUTE)]
 *       public function tick(): void {}
 *   }
 *
 *   // Drive it: `$app->get(Scheduler::class)->run()` (schedule:work) or `->runDueOnce()` (schedule:run from system cron).
 */
final class ScheduleModule implements DynamicModuleFactory
{
    public function __construct(
        private readonly ?string $storagePath = null,
        private readonly bool $global = true,
    ) {
    }

    /** @param array{storagePath?: ?string, global?: bool} $options */
    public static function forRoot(array $options = []): DynamicModule
    {
        return (new self(
            storagePath: $options['storagePath'] ?? null,
            global: $options['global'] ?? true,
        ))->toDynamicModule();
    }

    public function toDynamicModule(): DynamicModule
    {
        $storagePath = $this->storagePath;

        return new DynamicModule(
            module: self::class,
            providers: [
                CronParser::class,
                SchedulerRegistry::class,
                ScheduleExplorer::class,
                (new Provider(Scheduler::class))->useFactory(
                    fn (SchedulerRegistry $registry, CronParser $parser, ScheduleExplorer $explorer, ?LoggerInterface $logger = null) => new Scheduler($registry, $parser, $storagePath, $logger, $explorer)
                ),
            ],
            exports: [CronParser::class, SchedulerRegistry::class, ScheduleExplorer::class, Scheduler::class],
            global: $this->global,
        );
    }
}
