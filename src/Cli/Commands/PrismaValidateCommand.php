<?php

declare(strict_types=1);

namespace Nesto\Cli\Commands;

use Nesto\Cli\Command;

/** `php nesto prisma:validate` — Prisma's `prisma validate`. */
final class PrismaValidateCommand extends Command
{
    use InteractsWithPrisma;

    protected string $signature = 'prisma:validate {--schema= : Path to the schema file}';
    protected string $description = 'Validate the Prisma schema file';

    public function handle(): int
    {
        return $this->prisma(function (): int {
            $project = $this->prismaProject();
            $document = $project->document();
            foreach ($document->warnings as $warning) {
                $this->warn($warning);
            }
            $this->success(sprintf('The schema at %s is valid', $project->relative($project->schemaPath)));

            return self::SUCCESS;
        });
    }
}
