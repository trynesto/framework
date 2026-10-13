<?php

declare(strict_types=1);

namespace Nesto\Cli;

/**
 * Console writer with optional ANSI colours.
 *
 *   $out = new Output();            // STDOUT, colours when it is a TTY
 *   $out->success('Done.');
 *   $out->table(['Method', 'Path'], [['GET', '/cats'], ['POST', '/cats']]);
 *   $out->setAnsi(false);           // --no-ansi
 */
final class Output
{
    /** @var resource */
    private $stream;
    private bool $ansi;
    private string $buffer = '';
    private bool $capture = false;

    /** @param resource|null $stream */
    public function __construct($stream = null, ?bool $ansi = null)
    {
        $this->stream = $stream ?? (defined('STDOUT') ? STDOUT : fopen('php://stdout', 'w'));
        $this->ansi = $ansi ?? self::detectAnsi($this->stream);
    }

    /** An Output that only remembers what was written (tests). */
    public static function memory(): self
    {
        $out = new self(fopen('php://memory', 'w+'), false);
        $out->capture = true;

        return $out;
    }

    /** @param resource $stream */
    private static function detectAnsi($stream): bool
    {
        if (getenv('NO_COLOR') !== false) {
            return false;
        }
        if (getenv('FORCE_COLOR') !== false) {
            return true;
        }

        return function_exists('stream_isatty') && @stream_isatty($stream);
    }

    public function setAnsi(bool $ansi): self
    {
        $this->ansi = $ansi;

        return $this;
    }

    public function ansi(): bool
    {
        return $this->ansi;
    }

    // ---- writers ---------------------------------------------------------------

    public function write(string $text): void
    {
        if ($this->capture) {
            $this->buffer .= $text;
        }
        fwrite($this->stream, $text);
    }

    public function line(string $text = ''): void
    {
        $this->write($text . PHP_EOL);
    }

    public function newLine(int $count = 1): void
    {
        $this->write(str_repeat(PHP_EOL, max(1, $count)));
    }

    public function info(string $text): void
    {
        $this->line($this->color($text, 'cyan'));
    }

    public function success(string $text): void
    {
        $this->line($this->color($text, 'green'));
    }

    public function warn(string $text): void
    {
        $this->line($this->color($text, 'yellow'));
    }

    public function error(string $text): void
    {
        $this->line($this->color($text, 'red'));
    }

    public function comment(string $text): void
    {
        $this->line($this->color($text, 'gray'));
    }

    public function bold(string $text): string
    {
        return $this->ansi ? "\033[1m{$text}\033[0m" : $text;
    }

    public function color(string $text, string $color): string
    {
        if (! $this->ansi) {
            return $text;
        }
        $codes = ['red' => 31, 'green' => 32, 'yellow' => 33, 'blue' => 34, 'magenta' => 35, 'cyan' => 36, 'white' => 37, 'gray' => 90];
        $code = $codes[$color] ?? 0;

        return "\033[{$code}m{$text}\033[0m";
    }

    /**
     * Render an ASCII table.
     *
     * @param list<string>       $headers
     * @param list<list<mixed>>  $rows
     */
    public function table(array $headers, array $rows): void
    {
        $widths = [];
        $all = [$headers, ...array_map(fn ($r) => array_map(fn ($c) => (string) $c, array_values($r)), $rows)];
        foreach ($all as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, mb_strlen((string) $cell));
            }
        }
        $sep = '+' . implode('+', array_map(fn ($w) => str_repeat('-', $w + 2), $widths)) . '+';
        $render = function (array $row) use ($widths): string {
            $cells = [];
            foreach ($widths as $i => $w) {
                $cell = (string) ($row[$i] ?? '');
                $cells[] = ' ' . $cell . str_repeat(' ', $w - mb_strlen($cell)) . ' ';
            }

            return '|' . implode('|', $cells) . '|';
        };
        $this->line($sep);
        $this->line($this->bold($render($headers)));
        $this->line($sep);
        foreach ($rows as $row) {
            $this->line($render(array_map(fn ($c) => (string) $c, array_values($row))));
        }
        $this->line($sep);
    }

    /** Everything written so far (only for Output::memory()). */
    public function fetch(): string
    {
        return $this->buffer;
    }

    public function clear(): void
    {
        $this->buffer = '';
    }
}
