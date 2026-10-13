<?php

declare(strict_types=1);

namespace Nesto\Cli\Commands;

use Nesto\Cli\Command;
use Nesto\Prisma\Migrate\DbPull;
use Nesto\Prisma\Migrate\DbPush;

/**
 * `php nesto prisma:db push|pull|seed` — Prisma's `prisma db` commands.
 *
 *   prisma:db push [--accept-data-loss] [--force-reset] [--skip-generate]
 *   prisma:db pull [--print] [--force]
 *   prisma:db seed
 */
final class PrismaDbCommand extends Command
{
    use InteractsWithPrisma;

    protected string $signature = 'prisma:db {action : push, pull or seed}'
        . ' {--schema= : Path to the schema file}'
        . ' {--accept-data-loss : push: ignore data loss warnings}'
        . ' {--force-reset : push: drop the database before pushing (all data is lost)}'
        . ' {--skip-generate : push: do not run prisma:generate}'
        . ' {--print : pull: print the introspected schema instead of writing it}'
        . ' {--force : pull: ignore the current schema (no re-introspection)}';
    protected string $description = 'Manage the database schema without migrations (push, pull) and seed it';
    protected string $help = <<<'TXT'
          {bin} prisma:db push                     make the database match schema.prisma (no migration files)
          {bin} prisma:db push --accept-data-loss  also when columns / tables with data are dropped
          {bin} prisma:db push --force-reset       empty the database first
          {bin} prisma:db pull                     write the database's tables into schema.prisma
          {bin} prisma:db pull --print             print it instead
          {bin} prisma:db seed                     run the seed script ("prisma" => ["seed" => "prisma/seed.php"] in nesto.config.php)
        TXT;

    public function handle(): int
    {
        return match ((string) $this->argument('action')) {
            'push' => $this->prisma(function (): int {
                $project = $this->prismaProject();
                (new DbPush($project, $this->reporter()))->push((bool) $this->option('accept-data-loss'), (bool) $this->option('force-reset'));
                $this->runGenerate($project);

                return self::SUCCESS;
            }),
            'pull' => $this->prisma(function (): int {
                $project = $this->prismaProject();
                $reporter = $this->option('print') ? new \Nesto\Prisma\Migrate\BufferedReporter() : $this->reporter();
                $schema = (new DbPull($project, $reporter))->pull((bool) $this->option('force'));
                if ($this->option('print')) {
                    $this->output->write($schema);

                    return self::SUCCESS;
                }
                file_put_contents($project->schemaPath, $schema);
                $project->refresh();
                $this->success(sprintf('Introspected the database and wrote %s', $project->relative($project->schemaPath)));

                return self::SUCCESS;
            }),
            'seed' => $this->prisma(fn (): int => $this->runSeed($this->prismaProject(), true) ? self::SUCCESS : self::FAILURE),
            default => $this->unknownAction(),
        };
    }

    private function unknownAction(): int
    {
        $this->error(sprintf('Unknown command "prisma:db %s". Use push, pull or seed.', (string) $this->argument('action')));

        return self::FAILURE;
    }
}
