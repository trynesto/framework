<?php

declare(strict_types=1);

namespace Nesto\Cli;

/**
 * Parsed command-line input bound to a Signature, plus interactive helpers.
 *
 *   $input = Input::fromTokens($signature, ['cats', '--flat', '--path=Admin']);
 *   $input->argument('name');   // 'cats'
 *   $input->option('flat');     // true
 *   $input->option('path');     // 'Admin'
 *   $input->ask('Name?', 'default');   // reads a line from the input stream
 */
final class Input
{
    /** @var resource */
    private $stream;

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $options
     * @param resource|null        $stream    where interactive answers are read from (defaults to STDIN)
     */
    public function __construct(
        private array $arguments = [],
        private array $options = [],
        $stream = null,
        private bool $interactive = true,
    ) {
        $this->stream = $stream ?? (defined('STDIN') ? STDIN : fopen('php://stdin', 'r'));
    }

    /**
     * Build an Input from raw argv tokens (without the script and command names).
     *
     * @param list<string>  $tokens
     * @param resource|null $stream
     */
    public static function fromTokens(Signature $signature, array $tokens, $stream = null, bool $interactive = true): self
    {
        $arguments = [];
        $options = [];
        foreach ($signature->options as $name => $opt) {
            $options[$name] = $opt['default'];
        }
        $positional = [];
        $onlyPositional = false;
        $shortcuts = [];
        foreach ($signature->options as $name => $opt) {
            if ($opt['shortcut'] !== null) {
                $shortcuts[$opt['shortcut']] = $name;
            }
        }

        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($onlyPositional) {
                $positional[] = $token;
                continue;
            }
            if ($token === '--') {
                $onlyPositional = true;
                continue;
            }
            if (str_starts_with($token, '--')) {
                $body = substr($token, 2);
                $value = null;
                $hasValue = false;
                if (str_contains($body, '=')) {
                    [$body, $value] = explode('=', $body, 2);
                    $hasValue = true;
                }
                $opt = $signature->options[$body] ?? null;
                if ($opt === null) {
                    throw new CliException("The \"--$body\" option does not exist.");
                }
                if ($opt['flag']) {
                    $options[$body] = $hasValue ? filter_var($value, FILTER_VALIDATE_BOOLEAN) : true;
                    continue;
                }
                if (! $hasValue) {
                    $next = $tokens[$i + 1] ?? null;
                    if ($next !== null && ! str_starts_with($next, '-')) {
                        $value = $next;
                        $i++;
                    } else {
                        $value = $opt['default'] === null || $opt['default'] === [] ? '' : $opt['default'];
                    }
                }
                if ($opt['array']) {
                    $options[$body] = [...(array) ($options[$body] ?? []), $value];
                } else {
                    $options[$body] = $value;
                }
                continue;
            }
            if (str_starts_with($token, '-') && strlen($token) > 1 && ! is_numeric($token)) {
                $short = substr($token, 1, 1);
                $name = $shortcuts[$short] ?? (isset($signature->options[$short]) ? $short : null);
                if ($name === null) {
                    throw new CliException("The \"-$short\" option does not exist.");
                }
                $opt = $signature->options[$name];
                if ($opt['flag']) {
                    $options[$name] = true;
                    continue;
                }
                $value = strlen($token) > 2 ? ltrim(substr($token, 2), '=') : null;
                if ($value === null) {
                    $next = $tokens[$i + 1] ?? null;
                    if ($next !== null && ! str_starts_with($next, '-')) {
                        $value = $next;
                        $i++;
                    } else {
                        $value = '';
                    }
                }
                $options[$name] = $opt['array'] ? [...(array) ($options[$name] ?? []), $value] : $value;
                continue;
            }
            $positional[] = $token;
        }

        foreach ($signature->arguments as $index => $arg) {
            if ($arg['array']) {
                $rest = array_slice($positional, $index);
                if ($rest === [] && ! $arg['optional']) {
                    throw new CliException("Not enough arguments (missing: \"{$arg['name']}\").");
                }
                $arguments[$arg['name']] = $rest;
                $positional = array_slice($positional, 0, $index);
                break;
            }
            if (array_key_exists($index, $positional)) {
                $arguments[$arg['name']] = $positional[$index];
            } elseif ($arg['optional']) {
                $arguments[$arg['name']] = $arg['default'];
            } else {
                throw new CliException("Not enough arguments (missing: \"{$arg['name']}\").");
            }
        }
        $extra = count($positional) - count($signature->arguments);
        if ($extra > 0 && ! self::hasArrayArgument($signature)) {
            throw new CliException(sprintf('Too many arguments, expected %d: "%s".', count($signature->arguments), implode(' ', $positional)));
        }

        return new self($arguments, $options, $stream, $interactive);
    }

    private static function hasArrayArgument(Signature $signature): bool
    {
        foreach ($signature->arguments as $arg) {
            if ($arg['array']) {
                return true;
            }
        }

        return false;
    }

    // ---- accessors -----------------------------------------------------------

    public function argument(string $name, mixed $default = null): mixed
    {
        return $this->arguments[$name] ?? $default;
    }

    /** @return array<string, mixed> */
    public function arguments(): array
    {
        return $this->arguments;
    }

    public function hasArgument(string $name): bool
    {
        return array_key_exists($name, $this->arguments) && $this->arguments[$name] !== null;
    }

    public function option(string $name, mixed $default = null): mixed
    {
        return array_key_exists($name, $this->options) ? $this->options[$name] : $default;
    }

    /** @return array<string, mixed> */
    public function options(): array
    {
        return $this->options;
    }

    /** True when a boolean option (flag) was given. */
    public function flag(string $name): bool
    {
        return (bool) ($this->options[$name] ?? false);
    }

    public function hasOption(string $name): bool
    {
        return array_key_exists($name, $this->options);
    }

    public function setOption(string $name, mixed $value): void
    {
        $this->options[$name] = $value;
    }

    public function setArgument(string $name, mixed $value): void
    {
        $this->arguments[$name] = $value;
    }

    public function isInteractive(): bool
    {
        return $this->interactive;
    }

    // ---- interactive ----------------------------------------------------------

    /** Ask a free-form question. Returns $default when the answer is empty or input is not interactive. */
    public function ask(string $question, ?string $default = null, ?Output $output = null): ?string
    {
        $output?->write($question . ($default !== null ? " [$default]" : '') . ': ');
        if (! $this->interactive) {
            return $default;
        }
        $line = $this->readLine();

        return $line === '' ? $default : $line;
    }

    /** Yes/no question: accepts y, yes, true, 1 (case-insensitive). */
    public function confirm(string $question, bool $default = false, ?Output $output = null): bool
    {
        $output?->write($question . ' (yes/no) [' . ($default ? 'yes' : 'no') . ']: ');
        if (! $this->interactive) {
            return $default;
        }
        $line = strtolower($this->readLine());
        if ($line === '') {
            return $default;
        }

        return in_array($line, ['y', 'yes', 'true', '1'], true);
    }

    /**
     * Pick one of the choices, by number or by exact value.
     *
     * @param list<string> $choices
     */
    public function choice(string $question, array $choices, ?string $default = null, ?Output $output = null): ?string
    {
        if ($output !== null) {
            $output->line($question);
            foreach ($choices as $i => $choice) {
                $output->line(sprintf('  [%d] %s', $i, $choice));
            }
            $output->write('> ' . ($default !== null ? "[$default] " : ''));
        }
        if (! $this->interactive) {
            return $default;
        }
        $line = $this->readLine();
        if ($line === '') {
            return $default;
        }
        if (is_numeric($line) && isset($choices[(int) $line])) {
            return $choices[(int) $line];
        }

        return in_array($line, $choices, true) ? $line : $default;
    }

    private function readLine(): string
    {
        $line = fgets($this->stream);

        return $line === false ? '' : trim($line);
    }
}
