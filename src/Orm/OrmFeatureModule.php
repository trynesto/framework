<?php

declare(strict_types=1);

namespace Nesto\Orm;

use Nesto\Common\Contracts\DynamicModuleFactory;
use Nesto\Common\DynamicModule;
use Nesto\Common\Provider;
use Nesto\Orm\Attributes\CustomRepository;

/**
 * Registers (and exports) one Repository provider per entity, resolved from the global DataSource.
 *
 *   #[Module(imports: [new OrmFeatureModule(Cat::class, Owner::class)], providers: [CatsService::class])]
 *   final class CatsModule {}
 *
 *   final class CatsService { public function __construct(#[InjectRepository(Cat::class)] private Repository $cats) {} }
 *
 * A custom repository class (#[CustomRepository(Cat::class)] class CatRepository extends Repository) listed
 * instead of / next to the entity is registered under its class and under the entity's repository token.
 * For a named data source: `OrmModule::forFeature([Cat::class], 'analytics')` / `new OrmFeatureModule(Cat::class, dataSource: 'analytics')`.
 */
final class OrmFeatureModule implements DynamicModuleFactory
{
    /** @var list<class-string> */
    private readonly array $entities;
    private readonly ?string $dataSource;

    /** @param class-string ...$entities entity / custom repository classes; the named argument `dataSource:` picks a data source */
    public function __construct(string ...$entities)
    {
        $this->dataSource = $entities['dataSource'] ?? null;
        unset($entities['dataSource']);
        $this->entities = array_values($entities);
    }

    public function toDynamicModule(): DynamicModule
    {
        return self::forFeature($this->entities, $this->dataSource);
    }

    /** @param list<class-string> $entities entities and/or custom repository classes */
    public static function forFeature(array $entities, ?string $dataSource = null): DynamicModule
    {
        $dataSourceToken = OrmModule::getDataSourceToken($dataSource);
        $providers = [];
        $exports = [];
        $custom = [];
        foreach ($entities as $class) {
            if (CustomRepository::isCustomRepository($class)) {
                $custom[CustomRepository::entityOf($class)] = $class;
            }
        }
        foreach ($entities as $class) {
            if (isset($custom[$class]) || in_array($class, $custom, true)) {
                continue;
            }
            $token = OrmModule::repositoryToken($class, $dataSource);
            $providers[] = (new Provider($token))->useFactory(
                fn (DataSource $ds) => $ds->getRepository($class),
                inject: [$dataSourceToken],
            );
            $exports[] = $token;
        }
        foreach ($custom as $entity => $class) {
            $ownConstructor = (new \ReflectionClass($class))->getConstructor()?->getDeclaringClass()->getName() !== Repository::class;
            $providers[] = $ownConstructor && $dataSource === null
                ? new Provider($class, useClass: $class)  // its own constructor: dependency injection
                : (new Provider($class))->useFactory(fn (DataSource $ds) => self::customRepository($class, $ds), inject: [$dataSourceToken]);
            $providers[] = (new Provider(OrmModule::repositoryToken($entity, $dataSource)))->useExisting($class);
            $exports[] = $class;
            $exports[] = OrmModule::repositoryToken($entity, $dataSource);
        }

        return new DynamicModule(module: FeatureModule::class, providers: $providers, exports: $exports);
    }

    private static function customRepository(string $class, DataSource $ds): Repository
    {
        $ds->getRepository(CustomRepository::entityOf($class)); // registers the entity (autoLoadEntities)

        return new $class($ds);
    }
}
