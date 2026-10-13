<?php

declare(strict_types=1);

namespace Nesto\Tests\Cli\Fixtures;

use Nesto\Common\Attributes\Controller;
use Nesto\Common\Attributes\Get;
use Nesto\Common\Attributes\Module;
use Nesto\Tests\Testing\Fixtures\CatsModule;

require_once dirname(__DIR__, 2) . '/Testing/Fixtures/Fixtures.php';

#[Controller(path: 'admin', host: ':tenant.example.com')]
final class TenantAdminController
{
    #[Get]
    public function index(): array
    {
        return [];
    }
}

/** Root module of tests/Cli/Fixtures/routes-app (`php nesto routes` reads its public/index.php). */
#[Module(imports: [CatsModule::class], controllers: [TenantAdminController::class])]
final class RoutesAppModule {}
