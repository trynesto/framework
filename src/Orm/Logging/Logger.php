<?php

declare(strict_types=1);

namespace Nesto\Orm\Logging;

use Nesto\Orm\QueryRunner;

/**
 * TypeORM's Logger: receives every query, failed and slow query, schema-build and migration event of a
 * DataSource. Pass an instance as the `logger` option (or one of the built-in names, see LoggerFactory):
 *
 *   new DataSource([..., 'logging' => ['query', 'error'], 'logger' => new MyLogger]);
 *
 * Times are milliseconds. `$queryRunner` is the runner the statement ran on when known.
 */
interface Logger
{
    /** @param array<string|int, mixed> $parameters */
    public function logQuery(string $query, array $parameters = [], ?QueryRunner $queryRunner = null): void;

    /** @param array<string|int, mixed> $parameters */
    public function logQueryError(string|\Throwable $error, string $query, array $parameters = [], ?QueryRunner $queryRunner = null): void;

    /** @param array<string|int, mixed> $parameters */
    public function logQuerySlow(float $time, string $query, array $parameters = [], ?QueryRunner $queryRunner = null): void;

    public function logSchemaBuild(string $message, ?QueryRunner $queryRunner = null): void;

    public function logMigration(string $message, ?QueryRunner $queryRunner = null): void;

    /** @param 'log'|'info'|'warn' $level */
    public function log(string $level, mixed $message, ?QueryRunner $queryRunner = null): void;
}
