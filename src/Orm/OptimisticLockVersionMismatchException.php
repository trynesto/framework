<?php

declare(strict_types=1);

namespace Nesto\Orm;

use Nesto\Common\Exceptions\ConflictException;

/**
 * TypeORM's OptimisticLockVersionMismatchError: findOne() with `lock: ['mode' => 'optimistic', 'version' => …]`
 * found the row at another version (or another #[UpdateDateColumn] value). Unhandled, it becomes a 409.
 */
class OptimisticLockVersionMismatchException extends ConflictException
{
    public function __construct(
        public readonly string $entity,
        public readonly mixed $expectedVersion,
        public readonly mixed $actualVersion,
    ) {
        $format = static fn (mixed $v): string => $v instanceof \DateTimeInterface ? $v->format('Y-m-d H:i:s.u') : var_export($v, true);
        parent::__construct(sprintf(
            'The optimistic lock on entity %s failed, version %s was expected, but is actually %s.',
            Mapping\Naming::shortName($entity),
            $format($expectedVersion),
            $format($actualVersion),
        ));
    }
}
