<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;
use Nesto\Common\Attributes\Inject;
use Nesto\Orm\OrmModule;

/**
 * Injects a DataSource's EntityManager (@nestjs/typeorm's @InjectEntityManager()). The default one is also
 * injectable by type.
 *
 *   public function __construct(#[InjectEntityManager] private EntityManager $em) {}
 *   public function __construct(#[InjectEntityManager('analytics')] private EntityManager $analytics) {}
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class InjectEntityManager extends Inject
{
    public function __construct(public readonly ?string $dataSource = null)
    {
        parent::__construct(OrmModule::getEntityManagerToken($dataSource));
    }
}
