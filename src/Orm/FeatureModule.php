<?php

declare(strict_types=1);

namespace Nesto\Orm;

/**
 * Internal module node that hosts the Repository providers registered by OrmFeatureModule /
 * OrmModule::forFeature(). The core instantiates the module class of every DynamicModule, and
 * OrmFeatureModule's variadic constructor is a configuration object, not an injectable — so the
 * providers live here. Import OrmFeatureModule, never this class directly.
 */
final class FeatureModule {}
