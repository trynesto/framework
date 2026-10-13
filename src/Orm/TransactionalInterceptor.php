<?php

declare(strict_types=1);

namespace Nesto\Orm;

use Nesto\Common\Contracts\CallHandler;
use Nesto\Common\Contracts\Interceptor;
use Nesto\Core\Container;
use Nesto\Core\ExecutionContext;
use Nesto\Orm\Attributes\Transactional;

/**
 * Wraps handlers marked #[Transactional] in a DataSource transaction (rolled back on exceptions), through
 * DataSource::transaction() — the same QueryRunner machinery as manual transactions. The runner is released
 * when the handler returns or throws, so no transaction outlives the request (worker mode).
 *
 *   #[Controller('transfers')] #[UseInterceptors(TransactionalInterceptor::class)]
 *   final class TransfersController {
 *       #[Post] #[Transactional] public function create(#[Body] array $dto) { ... }
 *   }
 * or globally: providers: [new Provider(Constants::APP_INTERCEPTOR, useClass: TransactionalInterceptor::class)]
 */
final class TransactionalInterceptor implements Interceptor
{
    public function __construct(private readonly Container $container) {}

    public function intercept(ExecutionContext $context, CallHandler $next): mixed
    {
        $attribute = ($context->getHandler()->getAttributes(Transactional::class)[0] ?? $context->getClass()->getAttributes(Transactional::class)[0] ?? null)?->newInstance();
        if ($attribute === null) {
            return $next->handle();
        }
        $dataSource = $this->container->get(OrmModule::getDataSourceToken($attribute->dataSource));
        if (! $dataSource instanceof DataSource) {
            throw new OrmException('#[Transactional] needs a DataSource' . ($attribute->dataSource !== null ? " named \"$attribute->dataSource\"" : '') . ': import OrmModule.');
        }

        return $attribute->isolationLevel !== null
            ? $dataSource->transaction($attribute->isolationLevel, fn () => $next->handle())
            : $dataSource->transaction(fn () => $next->handle());
    }
}
