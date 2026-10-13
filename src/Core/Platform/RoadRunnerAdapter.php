<?php

declare(strict_types=1);

namespace Nesto\Core\Platform;

use Nesto\Core\NestoApplication;
use Nesto\Http\Request;

/**
 * RoadRunner worker. Requires the user to install spiral/roadrunner-http (PSR-7 worker) themselves:
 *
 *   composer require spiral/roadrunner-http nyholm/psr7
 *
 *   // worker.php
 *   $app = NestoFactory::create(AppModule::class);
 *   RoadRunnerAdapter::run($app);
 */
final class RoadRunnerAdapter
{
    public static function run(NestoApplication $app): void
    {
        if (! class_exists(\Spiral\RoadRunner\Http\PSR7Worker::class)) {
            throw new \RuntimeException('Install spiral/roadrunner-http to use the RoadRunner adapter.');
        }
        $app->init();
        $factory = new \Nyholm\Psr7\Factory\Psr17Factory;
        $worker = new \Spiral\RoadRunner\Http\PSR7Worker(\Spiral\RoadRunner\Worker::create(), $factory, $factory, $factory);

        while (true) {
            try {
                $psrRequest = $worker->waitRequest();
                if ($psrRequest === null) {
                    break;
                }
            } catch (\Throwable $e) {
                $worker->respond(new \Nyholm\Psr7\Response(400));
                continue;
            }
            try {
                $worker->respond($app->handle(new Request($psrRequest)));
            } catch (\Throwable $e) {
                $worker->getWorker()->error((string) $e);
            }
            gc_collect_cycles();
        }

        $app->close('worker-exit');
    }
}
