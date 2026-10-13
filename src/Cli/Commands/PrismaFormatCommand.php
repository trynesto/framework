<?php

declare(strict_types=1);

namespace Nesto\Cli\Commands;

use Nesto\Cli\Command;
use Nesto\Prisma\Schema\Formatter;

/** `php nesto prisma:format` — Prisma's `prisma format`: aligns and normalizes schema.prisma in place. */
final class PrismaFormatCommand extends Command
{
    use InteractsWithPrisma;

    protected string $signature = 'prisma:format {--schema= : Path to the schema file} {--check : Fail when the file is not formatted, change nothing}';
    protected string $description = 'Format the Prisma schema file';

    public function handle(): int
    {
        return $this->prisma(function (): int {
            $project = $this->prismaProject();
            $source = $project->source();
            $formatted = Formatter::format($source);
            if ($this->option('check')) {
                if ($formatted !== $source) {
                    $this->warn(sprintf('There are unformatted files. Run `%s prisma:format` to format them.', $this->bin()));

                    return self::FAILURE;
                }
                $this->success('All files are formatted correctly!');

                return self::SUCCESS;
            }
            file_put_contents($project->schemaPath, $formatted);
            $this->success(sprintf('Formatted %s', $project->relative($project->schemaPath)));

            return self::SUCCESS;
        });
    }
}
