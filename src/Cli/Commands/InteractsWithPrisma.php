<?php

declare(strict_types=1);

namespace Nesto\Cli\Commands;

use Nesto\Prisma\Migrate\Reporter;
use Nesto\Prisma\PrismaException;
use Nesto\Prisma\PrismaProject;
use Nesto\Prisma\Schema\SchemaException;

/**
 * Shared plumbing of the `prisma:*` commands: locating the project's schema (`--schema`, nesto.config.php
 * `prisma.schema`, prisma/schema.prisma), a Reporter over the console, running `prisma:generate` (when a
 * command by that name is registered) and the seed command, and turning Prisma errors into console errors.
 */
trait InteractsWithPrisma
{
    protected function prismaProject(): PrismaProject
    {
        $root = $this->context !== null ? $this->context->rootDir() : (string) getcwd();
        $config = $this->context?->config('prisma', []) ?? [];
        $schema = $this->input->hasOption('schema') ? $this->option('schema') : null;

        return PrismaProject::resolve($root, is_string($schema) && $schema !== '' ? $schema : null, is_array($config) ? $config : []);
    }

    protected function reporter(): Reporter
    {
        $command = $this;

        return new class ($command) implements Reporter {
            public function __construct(private readonly object $command) {}

            public function info(string $message): void
            {
                $this->command->output()->line($message);
            }

            public function warn(string $message): void
            {
                $this->command->output()->warn($message);
            }

            public function confirm(string $question, bool $default = false): bool
            {
                return $this->command->input()->confirm($question, $default, $this->command->output());
            }

            public function ask(string $question, ?string $default = null): ?string
            {
                return $this->command->input()->ask($question, $default, $this->command->output());
            }

            public function interactive(): bool
            {
                return $this->command->input()->isInteractive();
            }
        };
    }

    /** Run $fn, printing Prisma / database errors instead of throwing them. */
    protected function prisma(\Closure $fn): int
    {
        try {
            $result = $fn();

            return is_int($result) ? $result : self::SUCCESS;
        } catch (SchemaException $e) {
            $this->output->error($e->getMessage());

            return self::FAILURE;
        } catch (PrismaException|\PDOException|\Nesto\Orm\OrmException|\Nesto\Orm\QueryException $e) {
            $this->output->error('Error: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    /** `prisma:generate` after schema changes (stage 2 registers it); skipped when absent or with --skip-generate. */
    protected function runGenerate(PrismaProject $project): void
    {
        if (($this->input->hasOption('skip-generate') && $this->option('skip-generate')) || $this->application === null || ! $this->application->has('prisma:generate')) {
            return;
        }
        $this->call('prisma:generate', ['--schema=' . $project->schemaPath]);
    }

    /** Run the seed command (`prisma.seed`); false when it failed. */
    protected function runSeed(PrismaProject $project, bool $required = false): bool
    {
        $command = $project->seedCommand();
        if ($command === null) {
            if ($required) {
                $this->warn('No seed command configured. Set "prisma" => ["seed" => "prisma/seed.php"] in nesto.config.php (or add prisma/seed.php).');
            }

            return ! $required;
        }
        $this->line(sprintf('Running seed command `%s` ...', $command));
        $process = proc_open($command, [0 => STDIN, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $project->rootDir);
        if (! is_resource($process)) {
            $this->error('Could not start the seed command.');

            return false;
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($stdout !== '') {
            $this->output->write($stdout);
        }
        if ($code !== 0) {
            $this->error(sprintf('An error occurred while running the seed command:%s', $stderr !== '' ? "\n" . $stderr : " exit code $code"));

            return false;
        }
        $this->success('The seed command has been executed.');

        return true;
    }
}
