<?php

declare(strict_types=1);

namespace Nesto\Cli\Commands;

use Nesto\Cli\Command;
use Nesto\Cli\Generator\Str;
use Nesto\Cli\Generator\Stub;

/** `php nesto make:command send-emails` → src/Commands/SendEmailsCommand.php (then add it to nesto.config.php). */
final class MakeCommandCommand extends Command
{
    protected string $signature = 'make:command {name : Command class name, e.g. send-emails} {--force : Overwrite if the file exists}';
    protected string $description = 'Create a new console command class';

    public function handle(): int
    {
        $ctx = $this->context();
        $raw = preg_replace('/command$/i', '', Str::pascal((string) $this->argument('name'))) ?: 'Custom';
        $class = $raw . 'Command';
        $commandName = Str::kebab($raw);
        $namespace = $ctx->appNamespace() . '\\Commands';
        $file = $ctx->sourceRoot() . "/Commands/$class.php";

        if (is_file($file) && ! $this->option('force')) {
            $this->warn("$file already exists (use --force to overwrite).");

            return self::FAILURE;
        }
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, Stub::render('command', ['namespace' => $namespace, 'class' => $class, 'commandName' => $commandName]));
        $relative = str_replace(rtrim($ctx->rootDir(), '/') . '/', '', $file);
        $this->line($this->output->color('CREATE', 'green') . " $relative");
        $this->newLine();
        $this->info('Register it in nesto.config.php:');
        $this->line("    'commands' => [\\$namespace\\$class::class],");
        $this->line("then run: php nesto $commandName"); // project commands run through the project's console

        return self::SUCCESS;
    }
}
