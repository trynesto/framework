<?php

declare(strict_types=1);

namespace Nesto\Common\Contracts;

use Nesto\Common\DynamicModule;

/**
 * Lets a module be imported as a configured instance inside a #[Module] attribute
 * (PHP attributes cannot call ConfigModule::forRoot(), but they can `new ConfigModule(isGlobal: true)`):
 *
 *   #[Module(imports: [new ConfigModule(isGlobal: true), new OrmModule(entities: [Cat::class])])]
 */
interface DynamicModuleFactory
{
    public function toDynamicModule(): DynamicModule;
}
