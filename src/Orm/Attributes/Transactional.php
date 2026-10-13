<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;

/**
 * With TransactionalInterceptor active, the handler runs inside a DataSource transaction (on a QueryRunner, like
 * DataSource::transaction()) that is rolled back when it throws.
 *
 *   #[Post] #[Transactional] public function transfer(#[Body] array $dto) { ... }
 *   #[Post] #[Transactional(isolationLevel: 'SERIALIZABLE')] public function book(...) { ... }
 *   #[Post] #[Transactional(dataSource: 'analytics')] public function track(...) { ... }
 *
 * Inside it, the injected EntityManager / repositories write through the same connection, so they take part in
 * the transaction; subscribers' events report its QueryRunner.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class Transactional
{
    public function __construct(
        public readonly ?string $isolationLevel = null,
        public readonly ?string $dataSource = null,
    ) {}
}
