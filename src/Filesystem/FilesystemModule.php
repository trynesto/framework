<?php

declare(strict_types=1);

namespace Nesto\Filesystem;

use Nesto\Common\Contracts\DynamicModuleFactory;
use Nesto\Common\DynamicModule;
use Nesto\Common\Provider;

/**
 * Makes FilesystemManager (and the default Disk) injectable everywhere. Disks come from config/filesystems.php;
 * anything passed here overrides it:
 *
 *   #[Module(imports: [new ConfigModule(isGlobal: true), new FilesystemModule()])]
 *   #[Module(imports: [new FilesystemModule(default: 'public')])]
 */
final class FilesystemModule implements DynamicModuleFactory
{
    /** @param array<string, array<string, mixed>> $disks */
    public function __construct(
        public readonly ?string $default = null,
        public readonly array $disks = [],
    ) {}

    public function toDynamicModule(): DynamicModule
    {
        $options = array_filter(['default' => $this->default, 'disks' => $this->disks]);

        return new DynamicModule(
            module: self::class,
            providers: [
                new Provider(FilesystemManager::OPTIONS, useValue: $options === [] ? null : $options),
                FilesystemManager::class,
                new Provider(Disk::class, useFactory: static fn (FilesystemManager $manager) => $manager->disk(), inject: [FilesystemManager::class]),
            ],
            exports: [FilesystemManager::class, Disk::class],
            global: true,
        );
    }
}
