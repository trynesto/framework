<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;
use Nesto\Common\Attributes\Inject;
use Nesto\Orm\OrmModule;

/**
 * Injects the Repository registered by OrmFeatureModule / OrmModule::forFeature() for an entity (the custom
 * repository when one was registered for it); `dataSource` names a non-default data source.
 *
 *   public function __construct(#[InjectRepository(Cat::class)] private Repository $cats) {}
 *   public function __construct(#[InjectRepository(Event::class, 'analytics')] private Repository $events) {}
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class InjectRepository extends Inject
{
    /** @param class-string $entity */
    public function __construct(public readonly string $entity, public readonly ?string $dataSource = null)
    {
        parent::__construct(OrmModule::repositoryToken($entity, $dataSource));
    }
}
