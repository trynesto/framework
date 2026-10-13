<?php

declare(strict_types=1);

namespace Nesto\Cli;

/**
 * Base class for console commands.
 *
 *   final class HelloCommand extends Command
 *   {
 *       protected string $signature = 'hello {name=World} {--shout}';
 *       protected string $description = 'Say hello';
 *
 *       public function handle(): int
 *       {
 *           $greeting = 'Hello ' . $this->argument('name') . '!';
 *           $this->success($this->option('shout') ? strtoupper($greeting) : $greeting);
 *
 *           return self::SUCCESS;
 *       }
 *   }
 */
abstract class Command
{
    public const SUCCESS = 0;
    public const FAILURE = 1;

    protected string $signature = '';
    protected string $description = '';
    /** Longer text shown by `php nesto help <command>`. */
    protected string $help = '';

    private ?Signature $parsed = null;
    protected Input $input;
    protected Output $output;
    protected ?ProjectContext $context = null;
    protected ?Application $application = null;

    abstract public function handle(): int;

    public function signature(): Signature
    {
        return $this->parsed ??= Signature::parse($this->signature);
    }

    public function name(): string
    {
        return $this->signature()->name;
    }

    /** @return list<string> */
    public function aliases(): array
    {
        return $this->signature()->aliases;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function helpText(): string
    {
        return str_replace('{bin}', $this->bin(), $this->help);
    }

    /** How the user invokes the console: "php nesto" in a project, "nesto" for the global binary. */
    protected function bin(): string
    {
        return $this->application?->name() ?? 'php nesto';
    }

    /** Whether this command needs the application context (root module booted). Informational only. */
    public function needsApp(): bool
    {
        return false;
    }

    // ---- wiring (called by Application) -------------------------------------------

    public function setInput(Input $input): void
    {
        $this->input = $input;
    }

    public function setOutput(Output $output): void
    {
        $this->output = $output;
    }

    public function setContext(?ProjectContext $context): void
    {
        $this->context = $context;
    }

    public function setApplication(Application $application): void
    {
        $this->application = $application;
    }

    public function input(): Input
    {
        return $this->input;
    }

    public function output(): Output
    {
        return $this->output;
    }

    /** The project context (root dir, nesto.config.php, lazy app). Throws when run without a project. */
    protected function context(): ProjectContext
    {
        if ($this->context === null) {
            throw new CliException('This command must be run inside a Nesto project (no composer.json found).');
        }

        return $this->context;
    }

    /**
     * The booted application context of the project (Nest's NestFactory.createApplicationContext()).
     * Commands are created by the CLI before the app exists, so services are pulled from here
     * instead of the constructor:
     *
     *   $posts = $this->get(PostsService::class);
     */
    protected function app(): \Nesto\Core\NestoApplication
    {
        return $this->context()->app();
    }

    /** Resolve a provider from the application context (app.get() — ignores module boundaries). */
    protected function get(string $token): mixed
    {
        return $this->app()->get($token);
    }

    // ---- shortcuts --------------------------------------------------------------------

    protected function argument(string $name, mixed $default = null): mixed
    {
        return $this->input->argument($name, $default);
    }

    protected function option(string $name, mixed $default = null): mixed
    {
        return $this->input->option($name, $default);
    }

    protected function line(string $text = ''): void
    {
        $this->output->line($text);
    }

    protected function info(string $text): void
    {
        $this->output->info($text);
    }

    protected function success(string $text): void
    {
        $this->output->success($text);
    }

    protected function warn(string $text): void
    {
        $this->output->warn($text);
    }

    protected function error(string $text): void
    {
        $this->output->error($text);
    }

    protected function comment(string $text): void
    {
        $this->output->comment($text);
    }

    protected function newLine(int $count = 1): void
    {
        $this->output->newLine($count);
    }

    /** @param list<string> $headers @param list<list<mixed>> $rows */
    protected function table(array $headers, array $rows): void
    {
        $this->output->table($headers, $rows);
    }

    protected function ask(string $question, ?string $default = null): ?string
    {
        return $this->input->ask($question, $default, $this->output);
    }

    protected function confirm(string $question, bool $default = false): bool
    {
        return $this->input->confirm($question, $default, $this->output);
    }

    /** @param list<string> $choices */
    protected function choice(string $question, array $choices, ?string $default = null): ?string
    {
        return $this->input->choice($question, $choices, $default, $this->output);
    }

    /** Run another command of the same application. */
    protected function call(string $command, array $tokens = []): int
    {
        if ($this->application === null) {
            throw new CliException('Cannot call another command without an Application.');
        }

        return $this->application->run([$command, ...$tokens]);
    }
}
