<?php

declare(strict_types=1);

namespace Nesto\Cli\Commands;

use Nesto\Cli\Command;
use Nesto\Core\NestoApplication;

/**
 * `php nesto routes` (alias `route:list`) prints every registered route with the URL the app really serves:
 * the bootstrap file (public/index.php, "bootstrap" in nesto.config.php) is loaded without serving, so the global
 * prefix, versioning and anything else configured there apply.
 */
final class RoutesCommand extends Command
{
    protected string $signature = 'route:list|routes {--method= : Only this HTTP method} {--path= : Only paths containing this text} {--json : Print JSON instead of a table}';
    protected string $description = 'List all registered routes';

    public function needsApp(): bool
    {
        return true;
    }

    public function handle(): int
    {
        $app = $this->servedApp() ?? $this->context()->app();
        $rows = [];
        $method = strtoupper((string) ($this->option('method') ?? ''));
        $needle = (string) ($this->option('path') ?? '');
        foreach ($app->router()->routes() as $route) {
            if ($method !== '' && $route->method !== $method) {
                continue;
            }
            if ($needle !== '' && ! str_contains($route->path, $needle)) {
                continue;
            }
            $rows[] = [
                $route->method === '*' ? 'ALL' : $route->method,
                $route->path,
                $route->controller . '::' . $route->handler,
                $route->moduleClass !== null ? substr($route->moduleClass, (int) strrpos('\\' . $route->moduleClass, '\\')) : '',
                implode(', ', (array) ($route->meta['host'] ?? [])),
            ];
        }
        usort($rows, fn ($a, $b) => [$a[1], $a[0], $a[4]] <=> [$b[1], $b[0], $b[4]]);

        if ($this->option('json')) {
            $this->line((string) json_encode(array_map(fn ($r) => ['method' => $r[0], 'path' => $r[1], 'handler' => $r[2], 'module' => $r[3], 'host' => $r[4] !== '' ? $r[4] : null], $rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        if ($rows === []) {
            $this->warn('No routes registered.');

            return self::SUCCESS;
        }
        if (array_filter(array_column($rows, 4)) !== []) {
            $this->table(['METHOD', 'HOST', 'PATH', 'HANDLER', 'MODULE'], array_map(fn ($r) => [$r[0], $r[4] !== '' ? $r[4] : '*', $r[1], $r[2], $r[3]], $rows));
        } else {
            $this->table(['METHOD', 'PATH', 'HANDLER', 'MODULE'], array_map(fn ($r) => array_slice($r, 0, 4), $rows));
        }
        $this->comment(count($rows) . ' route(s)');

        return self::SUCCESS;
    }

    /** The app as the bootstrap file configures it, captured at its listen() call; null without a bootstrap file. */
    private function servedApp(): ?NestoApplication
    {
        $context = $this->context();
        if ($context->hasApp()) {
            return null;
        }
        $file = $context->path((string) $context->config('bootstrap'));
        if (! is_file($file)) {
            return null;
        }
        $captured = null;
        NestoApplication::interceptListen(static function (NestoApplication $app) use (&$captured): void {
            $captured = $app;
        });
        ob_start();
        try {
            (static function (string $file): void {
                require $file;
            })($file);
        } finally {
            ob_end_clean();
            NestoApplication::interceptListen(null);
        }
        if ($captured !== null) {
            $context->setApp($captured);
        }

        return $captured;
    }
}
