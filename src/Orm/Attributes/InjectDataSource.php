<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;
use Nesto\Common\Attributes\Inject;
use Nesto\Orm\OrmModule;

/**
 * Injects a DataSource (@nestjs/typeorm's @InjectDataSource()). The default one is also injectable by type.
 *
 *   public function __construct(#[InjectDataSource] private DataSource $ds) {}
 *   public function __construct(#[InjectDataSource('analytics')] private DataSource $analytics) {}
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class InjectDataSource extends Inject
{
    public function __construct(public readonly ?string $dataSource = null)
    {
        parent::__construct(OrmModule::getDataSourceToken($dataSource));
    }
}
