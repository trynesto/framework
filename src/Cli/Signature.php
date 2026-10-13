<?php

declare(strict_types=1);

namespace Nesto\Cli;

/**
 * Parses a Laravel-style command signature into a name, aliases, arguments and options.
 *
 *   $s = Signature::parse('g|generate {schematic} {name?} {--flat} {--no-spec} {--p|path=} {--port=3000}');
 *   $s->name;        // 'generate'   (the last of the "|"-separated names)
 *   $s->aliases;     // ['g']
 *   $s->arguments;   // [['name' => 'schematic', 'optional' => false, 'array' => false, 'default' => null, 'description' => ''], ...]
 *   $s->options;     // ['flat' => ['flag' => true, ...], 'path' => ['flag' => false, 'shortcut' => 'p', ...], ...]
 *
 * Argument forms: {name}, {name?}, {name=default}, {name*} (array), {name : description}
 * Option forms:   {--flag}, {--opt=}, {--opt=default}, {--opt=*} (array), {--s|opt=} (shortcut), {--opt : description}
 */
final class Signature
{
    /**
     * @param list<string>                 $aliases
     * @param list<array<string, mixed>>   $arguments
     * @param array<string, array<string, mixed>> $options
     */
    public function __construct(
        public readonly string $name,
        public readonly array $aliases,
        public readonly array $arguments,
        public readonly array $options,
    ) {}

    public static function parse(string $signature): self
    {
        $signature = trim($signature);
        if ($signature === '') {
            throw new \InvalidArgumentException('A command signature cannot be empty.');
        }

        preg_match('/^([^\s{]+)/', $signature, $m);
        $names = array_values(array_filter(explode('|', $m[1] ?? ''), fn ($n) => $n !== ''));
        if ($names === []) {
            throw new \InvalidArgumentException("Unable to determine the command name from signature \"$signature\".");
        }
        $name = array_pop($names);

        $arguments = [];
        $options = [];
        preg_match_all('/\{\s*([^}]+?)\s*\}/', $signature, $matches);
        foreach ($matches[1] as $token) {
            if (str_starts_with($token, '--')) {
                $opt = self::parseOption(substr($token, 2));
                $options[$opt['name']] = $opt;
            } else {
                $arguments[] = self::parseArgument($token);
            }
        }

        return new self($name, $names, $arguments, $options);
    }

    /** @return array{name: string, optional: bool, array: bool, default: mixed, description: string} */
    private static function parseArgument(string $token): array
    {
        [$spec, $description] = self::splitDescription($token);
        $optional = false;
        $array = false;
        $default = null;

        if (str_ends_with($spec, '*')) {
            $array = true;
            $spec = substr($spec, 0, -1);
            $optional = str_ends_with($spec, '?');
            $spec = rtrim($spec, '?');
            $default = [];
        } elseif (str_ends_with($spec, '?')) {
            $optional = true;
            $spec = substr($spec, 0, -1);
        } elseif (str_contains($spec, '=')) {
            [$spec, $default] = explode('=', $spec, 2);
            $optional = true;
        }

        return ['name' => trim($spec), 'optional' => $optional, 'array' => $array, 'default' => $default, 'description' => $description];
    }

    /** @return array{name: string, shortcut: ?string, flag: bool, array: bool, default: mixed, description: string} */
    private static function parseOption(string $token): array
    {
        [$spec, $description] = self::splitDescription($token);
        $shortcut = null;
        $flag = true;
        $array = false;
        $default = false;

        if (str_contains($spec, '=')) {
            [$spec, $value] = explode('=', $spec, 2);
            $flag = false;
            if ($value === '*') {
                $array = true;
                $default = [];
            } else {
                $default = $value === '' ? null : $value;
            }
        }
        if (str_contains($spec, '|')) {
            [$shortcut, $spec] = explode('|', $spec, 2);
        }

        return ['name' => trim($spec), 'shortcut' => $shortcut, 'flag' => $flag, 'array' => $array, 'default' => $default, 'description' => $description];
    }

    /** @return array{0: string, 1: string} */
    private static function splitDescription(string $token): array
    {
        if (preg_match('/^(.*?)\s*:\s*(.*)$/s', $token, $m)) {
            return [trim($m[1]), trim($m[2])];
        }

        return [trim($token), ''];
    }

    /** Human readable synopsis, e.g. "generate <schematic> [name] [--flat] [--path=PATH]". */
    public function synopsis(): string
    {
        $parts = [$this->name];
        foreach ($this->arguments as $arg) {
            $label = $arg['name'] . ($arg['array'] ? '...' : '');
            $parts[] = $arg['optional'] ? "[$label]" : "<$label>";
        }
        foreach ($this->options as $opt) {
            $label = '--' . $opt['name'] . ($opt['flag'] ? '' : '=' . strtoupper($opt['name']));
            $parts[] = "[$label]";
        }

        return implode(' ', $parts);
    }
}
