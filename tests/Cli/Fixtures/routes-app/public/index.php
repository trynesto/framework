<?php

declare(strict_types=1);

use Nesto\Core\NestoFactory;
use Nesto\Tests\Cli\Fixtures\RoutesAppModule;

require dirname(__DIR__, 5) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/RoutesApp.php';

$app = NestoFactory::create(RoutesAppModule::class);
$app->setGlobalPrefix('api', ['exclude' => ['admin']]);
$app->enableVersioning(['defaultVersion' => '1']);

echo 'output of the bootstrap file is swallowed';

$app->listen();
