<?php

declare(strict_types=1);

namespace Nesto\Cli\Commands;

use Nesto\Cli\Command;

/**
 * `php nesto schema:sync` — TypeORM's schema:sync: compares the entities with the database and applies
 * the difference (creates tables, adds / changes / drops columns, keys, indexes, foreign keys, checks).
 * Refused when APP_ENV is production unless --force is given: use migrations there.
 */
final class SchemaSyncCommand extends Command
{
    use InteractsWithPackages;

    protected string $signature = 'schema:sync {--drop : Drop the entity tables before re-creating them} {--force : Allow it when APP_ENV is production}';
    protected string $description = 'Synchronize the database schema with the entities (development only)';

    public function needsApp(): bool
    {
        return true;
    }

    public function handle(): int
    {
        if (self::isProduction() && ! $this->option('force')) {
            $this->error('Refusing to synchronize the schema while APP_ENV is production: it can drop columns and data.');
            $this->comment("Use migrations (\"{$this->bin()} migration:generate <name>\", then \"migration:run\"), or pass --force.");

            return self::FAILURE;
        }
        $ds = $this->package('Nesto\Orm\DataSource', 'ORM', 'OrmModule::forRoot([...])');
        if ($ds === null) {
            return self::FAILURE;
        }
        $method = $this->firstExistingMethod($ds, 'synchronize', 'sync');
        if ($method === null) {
            $this->error('DataSource has no synchronize() method.');

            return self::FAILURE;
        }
        try {
            $statements = method_exists($ds, 'logSchema') && ! $this->option('drop') ? $ds->logSchema() : null;
            if ($statements === []) {
                $this->success('Schema synchronized: it was already in sync with the entities.');

                return self::SUCCESS;
            }
            $this->callWithKnownArgs($ds, $method, ['dropBeforeSync' => (bool) $this->option('drop'), 'drop' => (bool) $this->option('drop')]);
        } catch (\Throwable $e) {
            $this->error('Schema sync failed: ' . $e->getMessage());

            return self::FAILURE;
        }
        $this->success('Schema synchronized' . ($statements !== null ? ' (' . count($statements) . ' statement' . (count($statements) === 1 ? '' : 's') . ').' : '.'));

        return self::SUCCESS;
    }

    /** APP_ENV is "production" / "prod". */
    public static function isProduction(): bool
    {
        $env = getenv('APP_ENV');
        $env = $env !== false ? $env : ($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? '');

        return in_array(strtolower(trim((string) $env)), ['production', 'prod'], true);
    }
}
