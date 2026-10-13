<?php

declare(strict_types=1);

namespace Nesto\Cli\Commands;

use Nesto\Cli\CliException;
use Nesto\Cli\Command;

/**
 * `php nesto start:prod` runs the app on FrankenPHP in worker mode: each worker boots the app once and keeps it
 * in memory (container, config, database connection) like `node dist/main` for Nest. Code changes apply only
 * after a restart (`pm2 restart`, `systemctl restart`, ...).
 *
 * The PHP process is replaced by frankenphp (pcntl_exec), so pm2/systemd signal the server directly.
 */
final class StartProdCommand extends Command
{
    protected string $signature = 'start:prod {--host=0.0.0.0 : Host to bind} {--port=3000 : Port to listen on} {--workers= : Worker count (default: FrankenPHP starts 2 per CPU)} {--max-requests=0 : Restart a worker after this many requests (0 = never)} {--domain= : Serve HTTPS for this domain with automatic certificates (ignores --host/--port)} {--access-log : Log every request} {--binary= : Path to the frankenphp binary} {--dry-run : Print the command instead of running it}';
    protected string $description = 'Start the production server (FrankenPHP worker mode, app kept in memory)';

    public function handle(): int
    {
        $context = $this->context();
        $bootstrap = $context->path((string) $context->config('bootstrap'));
        if (! is_file($bootstrap)) {
            throw new CliException("Bootstrap file \"$bootstrap\" does not exist (check \"bootstrap\" in nesto.config.php).");
        }

        $binary = $this->binary();
        $command = $this->command($binary, $bootstrap);
        $env = [];
        $maxRequests = (int) $this->option('max-requests');
        if ($maxRequests > 0) {
            $env['MAX_REQUESTS'] = (string) $maxRequests;
        }

        if ($this->option('dry-run')) {
            $prefix = '';
            foreach ($env as $name => $value) {
                $prefix .= "$name=" . escapeshellarg($value) . ' ';
            }
            $this->line($prefix . implode(' ', array_map('escapeshellarg', $command)));

            return self::SUCCESS;
        }

        $domain = (string) $this->option('domain');
        $this->success($domain !== ''
            ? "Nesto production server starting: https://$domain"
            : sprintf('Nesto production server starting: http://%s:%d', $this->option('host'), (int) $this->option('port')));
        $this->comment('FrankenPHP worker mode: the app stays in memory, restart to apply code changes.');

        chdir($context->path());
        if (function_exists('pcntl_exec')) {
            pcntl_exec($binary, array_slice($command, 1), $env + getenv());
            throw new CliException("Unable to start $binary.");
        }

        foreach ($env as $name => $value) {
            putenv("$name=$value");
        }
        passthru(implode(' ', array_map('escapeshellarg', $command)), $code);

        return $code === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<string> */
    private function command(string $binary, string $bootstrap): array
    {
        $workers = (int) $this->option('workers');
        $command = [
            $binary, 'php-server',
            '--root', dirname($bootstrap),
            '--worker', $workers > 0 ? "$bootstrap,$workers" : $bootstrap,
        ];

        $domain = (string) $this->option('domain');
        if ($domain !== '') {
            array_push($command, '--domain', $domain);
        } else {
            array_push($command, '--listen', sprintf('%s:%d', $this->option('host'), (int) $this->option('port')));
        }
        if ($this->option('access-log')) {
            $command[] = '--access-log';
        }

        return $command;
    }

    private function binary(): string
    {
        $binary = (string) ($this->option('binary') ?: getenv('FRANKENPHP_BINARY') ?: '');
        if ($binary === '') {
            $binary = trim((string) shell_exec('command -v frankenphp 2>/dev/null'));
        }
        if ($binary === '' || ! is_executable($binary)) {
            throw new CliException(
                "FrankenPHP was not found. Install it (https://frankenphp.dev/docs/#standalone-binary):\n"
                . "  curl https://frankenphp.dev/install.sh | sh && sudo mv frankenphp /usr/local/bin/\n"
                . 'or pass --binary=/path/to/frankenphp (or set FRANKENPHP_BINARY).'
            );
        }

        return $binary;
    }
}
