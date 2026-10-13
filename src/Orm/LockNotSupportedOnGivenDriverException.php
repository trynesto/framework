<?php

declare(strict_types=1);

namespace Nesto\Orm;

/** TypeORM's LockNotSupportedOnGivenDriverError: the lock mode (or lock tables / onLocked) has no SQL on this database. */
class LockNotSupportedOnGivenDriverException extends OrmException {}
