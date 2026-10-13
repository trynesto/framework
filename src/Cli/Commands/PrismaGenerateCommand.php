<?php

declare(strict_types=1);

namespace Nesto\Cli\Commands;

use Nesto\Cli\Command;
use Nesto\Prisma\Dmmf\DmmfCache;
use Nesto\Prisma\Generator\ClientGenerator;

/**
 * `php nesto prisma:generate` — Prisma's `prisma generate`: writes the typed PHP client of every
 * `nesto-client` generator block (see Prisma\Generator\ClientGenerator) and refreshes the compiled-schema
 * cache. prisma:db push, prisma:migrate dev and prisma:migrate reset run it automatically.
 */
final class PrismaGenerateCommand extends Command
{
    use InteractsWithPrisma;

    protected string $signature = 'prisma:generate {--schema= : Path to the schema file} {--no-hints : Hide the usage hints}';
    protected string $description = 'Generate the Prisma client from the Prisma schema';
    protected string $help = <<<'TXT'
        Reads prisma/schema.prisma and writes the client of each generator block with
        provider = "nesto-client" (output defaults to ../generated/prisma relative to the schema,
        the namespace to the composer.json PSR-4 namespace of that folder, else Generated\Prisma):

          generator client {
            provider  = "nesto-client"
            output    = "../src/Prisma"
            namespace = "App\\Prisma"
          }
        TXT;

    public function handle(): int
    {
        return $this->prisma(function (): int {
            $project = $this->prismaProject();
            $start = microtime(true);
            $document = $project->document();
            $generator = new ClientGenerator($document, $project->schemaPath, $project->rootDir);
            if ($generator->generators() === []) {
                $this->warn(sprintf('This schema has no "%s" generator. Add one to generate the client:', ClientGenerator::PROVIDER));
                $this->line("\ngenerator client {\n  provider = \"" . ClientGenerator::PROVIDER . "\"\n}");

                return self::SUCCESS;
            }
            DmmfCache::write($document, $project->dmmfCacheFile(), DmmfCache::hash($project->source()));
            foreach ($generator->generate() as $dir => $namespace) {
                $this->success(sprintf('Generated Nesto Prisma Client (%s) to ./%s in %dms', $namespace, $project->relative($dir), (int) round((microtime(true) - $start) * 1000)));
                if ($this->option('no-hints')) {
                    continue;
                }
                if ($generator->composerNamespace($dir) !== $namespace) {
                    $this->newLine();
                    $this->warn(sprintf('The namespace %s is not mapped in composer.json. Add it and run `composer dump-autoload`:', $namespace));
                    $this->line(sprintf('  "autoload": { "psr-4": { %s: "%s/" } }', json_encode($namespace . '\\'), $project->relative($dir)));
                    $this->line(sprintf('  (or require %s/autoload.php)', $project->relative($dir)));
                }
                $this->newLine();
                $this->line('Start by importing your Prisma Client:');
                $this->newLine();
                $this->line(sprintf('  use %s\\PrismaClient;', $namespace));
                $this->line('  $prisma = new PrismaClient();');
                $this->newLine();
                $this->line(sprintf('or inject %s\\PrismaService with PrismaModule::forRoot().', $namespace));
            }

            return self::SUCCESS;
        });
    }
}
