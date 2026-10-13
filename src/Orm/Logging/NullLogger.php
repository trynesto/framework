<?php

declare(strict_types=1);

namespace Nesto\Orm\Logging;

use Nesto\Orm\QueryRunner;

/** Discards everything: the DataSource's logger when neither `logging`, `logger` nor `maxQueryExecutionTime` is set. */
final class NullLogger implements Logger
{
    public function logQuery(string $query, array $parameters = [], ?QueryRunner $queryRunner = null): void {}

    public function logQueryError(string|\Throwable $error, string $query, array $parameters = [], ?QueryRunner $queryRunner = null): void {}

    public function logQuerySlow(float $time, string $query, array $parameters = [], ?QueryRunner $queryRunner = null): void {}

    public function logSchemaBuild(string $message, ?QueryRunner $queryRunner = null): void {}

    public function logMigration(string $message, ?QueryRunner $queryRunner = null): void {}

    public function log(string $level, mixed $message, ?QueryRunner $queryRunner = null): void {}
}
