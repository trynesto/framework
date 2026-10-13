<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\DataAccess;

use Nesto\Cache\CacheModule;
use Nesto\Common\Attributes\Module;
use Nesto\Orm\OrmModule;

/** OrmModule with a PSR-16 query cache taken from CacheModule, query logging options and replication. */
#[Module(imports: [
    new CacheModule(store: 'memory', ttl: 60),
    new OrmModule(
        type: 'sqlite',
        database: ':memory:',
        entities: [DaAuthor::class, DaNote::class],
        synchronize: true,
        logging: ['error'],
        logger: 'simple-console',
        maxQueryExecutionTime: 5000,
        cache: ['type' => 'psr16', 'duration' => 60_000],
    ),
])]
final class CachedAppModule
{
}
