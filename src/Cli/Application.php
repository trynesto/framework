<?php

declare(strict_types=1);

namespace Nesto\Cli;

/**
 * The console application: a registry of commands plus argv dispatch.
 *
 *   $app = new Application();                       // name "nesto"
 *   $app->add(new HelloCommand());
 *   $app->setContext(ProjectContext::discover(getcwd()));
 *   exit($app->run(array_slice($argv, 1)));
 *
 * Built-in behaviour: `list` (default when no command is given), `help <command>`, `--help`/`-h`
 * on any command, `--version`/`-V`, `--no-ansi`, `--no-interaction`/`-n`.
 */
final class Application
{
    /** Fallback when Composer can't tell (a git checkout without a tag); releases read the installed version. */
    public const VERSION = '0.1.3';

    /** The installed nesto/framework version ("0.1.2"), from Composer's metadata when available. */
    public static function frameworkVersion(): string
    {
        if (class_exists(\Composer\InstalledVersions::class) && \Composer\InstalledVersions::isInstalled('nesto/framework')) {
            $version = \Composer\InstalledVersions::getPrettyVersion('nesto/framework');
            if ($version !== null && preg_match('/^v?(\d+\.\d+\.\d+)$/', $version, $m) === 1) {
                return $m[1];
            }
        }

        return self::VERSION;
    }

    /** @var array<string, Command> name => command */
    private array $commands = [];
    /** @var array<string, string> alias => name */
    private array $aliases = [];
    private Output $output;
    /** @var resource|null */
    private $inputStream = null;
    private ?ProjectContext $context = null;
    private bool $interactive = true;
    private bool $catchExceptions = true;
    /** Nesting of run() calls (Command::call() runs another command inside the current one). */
    private int $depth = 0;

    public function __construct(
        private readonly string $name = 'nesto',
        private readonly string $version = self::VERSION,
    ) {
        $this->output = new Output();
    }

    public function name(): string
    {
        return $this->name;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function add(Command ...$commands): self
    {
        foreach ($commands as $command) {
            $command->setApplication($this);
            $this->commands[$command->name()] = $command;
            foreach ($command->aliases() as $alias) {
                $this->aliases[$alias] = $command->name();
            }
        }

        return $this;
    }

    /** @return array<string, Command> */
    public function commands(): array
    {
        ksort($this->commands);

        return $this->commands;
    }

    public function has(string $name): bool
    {
        return isset($this->commands[$name]) || isset($this->aliases[$name]);
    }

    public function find(string $name): Command
    {
        $name = $this->aliases[$name] ?? $name;
        if (! isset($this->commands[$name])) {
            $suggestion = $this->suggest($name);
            throw new CliException("Command \"$name\" is not defined." . ($suggestion ? " Did you mean \"$suggestion\"?" : ''));
        }

        return $this->commands[$name];
    }

    public function setOutput(Output $output): self
    {
        $this->output = $output;

        return $this;
    }

    public function output(): Output
    {
        return $this->output;
    }

    /** @param resource $stream */
    public function setInputStream($stream): self
    {
        $this->inputStream = $stream;

        return $this;
    }

    public function setContext(?ProjectContext $context): self
    {
        $this->context = $context;

        return $this;
    }

    public function context(): ?ProjectContext
    {
        return $this->context;
    }

    public function setInteractive(bool $interactive): self
    {
        $this->interactive = $interactive;

        return $this;
    }

    /** When false, exceptions thrown by commands propagate (useful in tests). */
    public function setCatchExceptions(bool $catch): self
    {
        $this->catchExceptions = $catch;

        return $this;
    }

    /**
     * Run with argv tokens (without the script name). Returns the exit code.
     * Once the outermost command is done (or failed), the application context it booted is closed,
     * so OnModuleDestroy / BeforeApplicationShutdown / OnApplicationShutdown hooks run.
     *
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $this->depth++;
        try {
            $code = $this->dispatch($argv);
        } finally {
            if (--$this->depth === 0 && ! $this->closeApp()) {
                $code = Command::FAILURE;
            }
        }

        return $code;
    }

    /** app.close() for the app booted by the command; false when a shutdown hook failed. */
    private function closeApp(): bool
    {
        try {
            $this->context?->closeApp();
        } catch (\Throwable $e) {
            if (! $this->catchExceptions) {
                throw $e;
            }
            $this->output->error('Shutdown failed: ' . get_class($e) . ': ' . $e->getMessage());

            return false;
        }

        return true;
    }

    /** @param list<string> $argv */
    private function dispatch(array $argv): int
    {
        $tokens = array_values($argv);
        $wantHelp = false;
        foreach ($tokens as $i => $token) {
            if ($token === '--no-ansi') {
                $this->output->setAnsi(false);
                unset($tokens[$i]);
            } elseif ($token === '--ansi') {
                $this->output->setAnsi(true);
                unset($tokens[$i]);
            } elseif ($token === '--no-interaction' || $token === '-n') {
                $this->interactive = false;
                unset($tokens[$i]);
            } elseif ($token === '--help' || $token === '-h') {
                $wantHelp = true;
                unset($tokens[$i]);
            } elseif ($token === '--version' || $token === '-V') {
                $this->output->line("{$this->name} {$this->version}");

                return Command::SUCCESS;
            }
        }
        $tokens = array_values($tokens);

        $name = $tokens[0] ?? null;
        if ($name === null || $name === 'list') {
            $this->renderList();

            return Command::SUCCESS;
        }
        if ($name === 'help') {
            if (! isset($tokens[1])) {
                $this->renderList();

                return Command::SUCCESS;
            }
            $wantHelp = true;
            $name = $tokens[1];
            $tokens = [$name];
        }

        try {
            $command = $this->find($name);
            if ($wantHelp) {
                $this->renderHelp($command);

                return Command::SUCCESS;
            }
            $input = Input::fromTokens($command->signature(), array_slice($tokens, 1), $this->inputStream, $this->interactive);
            $command->setInput($input);
            $command->setOutput($this->output);
            $command->setContext($this->context);

            return $command->handle();
        } catch (\Throwable $e) {
            if (! $this->catchExceptions) {
                throw $e;
            }
            if (! $e instanceof CliException) {
                // a command crashed: the trace goes to the configured log (LOG_CHANNEL), the console gets the message
                (new \Nesto\Logger\Logger('Console'))->error(sprintf('Command "%s" failed: %s', $name, $e->getMessage()), ['exception' => $e]);
            }
            $this->output->error(($e instanceof CliException ? '' : get_class($e) . ': ') . $e->getMessage());
            if (isset($command) && $e instanceof CliException) {
                $this->output->comment('Usage: ' . $command->signature()->synopsis());
            }

            return Command::FAILURE;
        }
    }

    // ---- built-in rendering ---------------------------------------------------------------

    public function renderList(): void
    {
        $out = $this->output;
        $out->line($out->bold("{$this->name} {$this->version}"));
        $out->newLine();
        $out->line($out->color('Usage:', 'yellow'));
        $out->line("  {$this->name} <command> [arguments] [options]");
        $out->newLine();
        $out->line($out->color('Global options:', 'yellow'));
        $out->line('  --help, -h            Show help for a command');
        $out->line('  --version, -V         Print the version');
        $out->line('  --no-ansi             Disable colours');
        $out->line('  --no-interaction, -n  Never ask questions');
        $out->newLine();
        $out->line($out->color('Available commands:', 'yellow'));

        $groups = [];
        foreach ($this->commands() as $name => $command) {
            $group = str_contains($name, ':') ? explode(':', $name, 2)[0] : '';
            $groups[$group][$name] = $command;
        }
        ksort($groups);
        $labels = [];
        foreach ($this->commands() as $name => $command) {
            $labels[$name] = $name . ($command->aliases() !== [] ? ' (' . implode(', ', $command->aliases()) . ')' : '');
        }
        $width = max([20, ...array_map('strlen', array_values($labels))]) + 2;
        foreach ($groups as $group => $commands) {
            if ($group !== '') {
                $out->line(' ' . $out->color($group, 'yellow'));
            }
            foreach ($commands as $name => $command) {
                $out->line('  ' . $out->color(str_pad($labels[$name], $width), 'green') . $command->description());
            }
        }
    }

    public function renderHelp(Command $command): void
    {
        $out = $this->output;
        $sig = $command->signature();
        $out->line($out->color('Description:', 'yellow'));
        $out->line('  ' . $command->description());
        $out->newLine();
        $out->line($out->color('Usage:', 'yellow'));
        $out->line('  ' . $sig->synopsis());
        if ($sig->aliases !== []) {
            $out->line('  Aliases: ' . implode(', ', $sig->aliases));
        }
        $optionLabels = [];
        foreach ($sig->options as $key => $opt) {
            $optionLabels[$key] = ($opt['shortcut'] ? '-' . $opt['shortcut'] . ', ' : '') . '--' . $opt['name'] . ($opt['flag'] ? '' : '=' . strtoupper($opt['name']));
        }
        // one column for arguments and options, wide enough for the longest label (+2 spaces)
        $width = max([20, ...array_map('strlen', [...array_column($sig->arguments, 'name'), ...array_values($optionLabels)])]) + 2;
        if ($sig->arguments !== []) {
            $out->newLine();
            $out->line($out->color('Arguments:', 'yellow'));
            foreach ($sig->arguments as $arg) {
                $extra = $arg['default'] !== null && $arg['default'] !== [] ? " [default: {$arg['default']}]" : '';
                $out->line('  ' . $out->color(str_pad($arg['name'], $width), 'green') . $arg['description'] . $extra);
            }
        }
        if ($sig->options !== []) {
            $out->newLine();
            $out->line($out->color('Options:', 'yellow'));
            foreach ($sig->options as $key => $opt) {
                $default = ! $opt['flag'] && $opt['default'] !== null && $opt['default'] !== [] ? " [default: {$opt['default']}]" : '';
                $out->line('  ' . $out->color(str_pad($optionLabels[$key], $width), 'green') . $opt['description'] . $default);
            }
        }
        if ($command->helpText() !== '') {
            $out->newLine();
            $out->line($out->color('Help:', 'yellow'));
            foreach (explode("\n", $command->helpText()) as $line) {
                $out->line('  ' . $line);
            }
        }
    }

    private function suggest(string $name): ?string
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;
        foreach ([...array_keys($this->commands), ...array_keys($this->aliases)] as $candidate) {
            $d = levenshtein($name, $candidate);
            if ($d < $bestDistance && $d <= 3) {
                $bestDistance = $d;
                $best = $candidate;
            }
        }

        return $best;
    }
}
