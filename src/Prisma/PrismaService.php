<?php

declare(strict_types=1);

namespace Nesto\Prisma;

use Nesto\Common\Attributes\Injectable;
use Nesto\Common\Contracts\OnApplicationShutdown;
use Nesto\Common\Contracts\OnModuleInit;
use Nesto\Prisma\Client\PrismaClient;

/**
 * NestJS' Prisma recipe without code generation: the runtime client (models reached as `$prisma->user`, untyped)
 * as an injectable provider that connects when the modules initialize and disconnects on shutdown.
 *
 * `php nesto prisma:generate` writes a typed `PrismaService` next to the generated client; PrismaModule
 * prefers that one when it exists. Your own service works too:
 *
 *   #[Injectable]
 *   class PrismaService extends \App\Prisma\PrismaClient implements OnModuleInit, OnApplicationShutdown { ... }
 *
 *   PrismaModule::forRoot(['service' => PrismaService::class])
 */
#[Injectable]
class PrismaService extends PrismaClient implements OnModuleInit, OnApplicationShutdown
{
    public function onModuleInit(): void
    {
        $this->connect();
    }

    public function onApplicationShutdown(?string $signal = null): void
    {
        $this->disconnect();
    }
}
