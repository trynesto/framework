<?php

declare(strict_types=1);

namespace Nesto\Cli\Commands;

use Nesto\Cli\Command;

/** The commands shipped with the framework, in the order they are registered. */
final class BuiltIn
{
    /** @return list<class-string<Command>> */
    public static function classes(): array
    {
        return [
            NewCommand::class,
            StartCommand::class,
            StartProdCommand::class,
            GenerateCommand::class,
            RoutesCommand::class,
            InfoCommand::class,
            MigrateCommand::class,
            MigrateRevertCommand::class,
            MigrateStatusCommand::class,
            MigrationCreateCommand::class,
            MigrationGenerateCommand::class,
            SchemaSyncCommand::class,
            SchemaLogCommand::class,
            SchemaDropCommand::class,
            DbSeedCommand::class,
            PrismaInitCommand::class,
            PrismaFormatCommand::class,
            PrismaValidateCommand::class,
            PrismaDbCommand::class,
            PrismaMigrateCommand::class,
            PrismaGenerateCommand::class,
            QueueWorkCommand::class,
            ScheduleRunCommand::class,
            ScheduleWorkCommand::class,
            MakeCommandCommand::class,
            AddCommand::class,
            ConfigPublishCommand::class,
            PackageDiscoverCommand::class,
            StorageLinkCommand::class,
            StorageUnlinkCommand::class,
        ];
    }

    /** @return list<Command> */
    public static function commands(): array
    {
        return array_map(fn (string $class) => new $class(), self::classes());
    }
}
