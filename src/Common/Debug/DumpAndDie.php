<?php

declare(strict_types=1);

namespace Nesto\Common\Debug;

use Nesto\Http\Request;
use Nesto\Http\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * Thrown by `console()` while the framework handles a request. Exiting there would kill a FrankenPHP worker, so
 * the framework catches this before any exception filter and answers with the dump (HTTP 500, like Laravel's dd).
 * It extends \Error so `catch (\Exception $e)` blocks in application code don't swallow it.
 */
final class DumpAndDie extends \Error
{
    /** @param list<mixed> $values */
    public function __construct(public readonly array $values, public readonly string $location)
    {
        parent::__construct('console() called at ' . $location);
    }

    public function toResponse(?Request $request = null): ResponseInterface
    {
        $accept = $request?->header('Accept') ?? '';
        if (! str_contains($accept, 'text/html')) {
            return Response::text(self::text($this->values, $this->location), 500);
        }

        return Response::html(self::page($this->values, $this->location), 500);
    }

    /** @param list<mixed> $values */
    public static function text(array $values, string $location, bool $colors = false): string
    {
        $out = ($colors ? "\033[90m" . $location . "\033[0m" : $location) . "\n";
        foreach ($values as $value) {
            $out .= Dumper::text($value, $colors) . "\n";
        }

        return $out;
    }

    /** @param list<mixed> $values */
    public static function page(array $values, string $location): string
    {
        $dumps = implode('', array_map(Dumper::html(...), $values));

        return '<!doctype html><html><head><meta charset="utf-8"><title>console()</title><style>'
            . 'body{margin:0;background:#18171b;color:#ddd;font:13px/1.5 ui-monospace,Menlo,Consolas,monospace}'
            . '.mdump-loc{padding:8px 12px;color:#999;border-bottom:1px solid #333}'
            . '.mdump{margin:0;padding:10px 12px;border-bottom:1px solid #333;white-space:pre-wrap;word-break:break-word}'
            . '.mdump-toggle{cursor:pointer}.mdump-folded{display:none}'
            . '.mdump-toggle:has(+.mdump-folded)::after{content:" …";color:#888}'
            . '.mdump-string{color:#56db3a}.mdump-number{color:#1299da}.mdump-const{color:#ff8400}'
            . '.mdump-class{color:#ffd166}.mdump-key{color:#7ec8e3}.mdump-note{color:#888}'
            . '</style></head><body><div class="mdump-loc">' . htmlspecialchars($location) . '</div>' . $dumps . '</body></html>';
    }
}
