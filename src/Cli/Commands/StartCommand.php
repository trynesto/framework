<?php

declare(strict_types=1);

namespace Nesto\Cli\Commands;

use Nesto\Cli\CliException;
use Nesto\Cli\Command;

/**
 * `php nesto start --port=3000` runs the PHP built-in web server with public/ as document root.
 * `--watch` restarts the server when a file under the source root changes.
 */
final class StartCommand extends Command
{
    protected string $signature = 'start {--port=3000 : Port to listen on} {--host=127.0.0.1 : Host to bind} {--watch : Restart when source files change} {--worker : Point to start:prod (FrankenPHP worker mode)}';
    protected string $description = 'Start the development server';

    public function handle(): int
    {
        $context = $this->context();
        $docroot = dirname($context->path((string) $context->config('bootstrap')));
        if (! is_dir($docroot)) {
            throw new CliException("Document root \"$docroot\" does not exist (check \"bootstrap\" in nesto.config.php).");
        }
        $host = (string) $this->option('host');
        $port = (int) $this->option('port');
        $router = dirname(__DIR__) . '/server-router.php';

        if ($this->option('worker')) {
            $this->info("Worker mode (app kept in memory) is the production server: run \"{$this->bin()} start:prod\".");

            return self::SUCCESS;
        }

        $command = sprintf(
            '%s -S %s:%d -t %s %s',
            escapeshellarg(PHP_BINARY),
            $host,
            $port,
            escapeshellarg($docroot),
            escapeshellarg($router)
        );
        $this->success(sprintf('Nesto development server started: http://%s:%d', $host, $port));
        $this->comment("Document root: $docroot");
        $this->comment('Press Ctrl+C to stop.');

        if (! $this->option('watch')) {
            passthru($command, $code);

            return $code === 0 ? self::SUCCESS : self::FAILURE;
        }

        return $this->watch($command, $context->sourceRoot());
    }

    private function watch(string $command, string $watchDir): int
    {
        $this->info("Watching $watchDir for changes...");
        $env = ['NESTO_DOCROOT' => getenv('NESTO_DOCROOT') ?: ''] + getenv();
        while (true) {
            $snapshot = $this->snapshot($watchDir);
            $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, null, $env);
            if (! is_resource($process)) {
                $this->error('Unable to start the server process.');

                return self::FAILURE;
            }
            while (true) {
                usleep(1_000_000);
                $status = proc_get_status($process);
                if (! $status['running']) {
                    return $status['exitcode'] === 0 ? self::SUCCESS : self::FAILURE;
                }
                if ($this->snapshot($watchDir) !== $snapshot) {
                    $this->warn('Change detected, restarting server...');
                    proc_terminate($process);
                    proc_close($process);
                    break;
                }
            }
        }
    }

    /** @return array<string, int> file => mtime */
    private function snapshot(string $dir): array
    {
        $out = [];
        if (! is_dir($dir)) {
            return $out;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $out[$file->getPathname()] = $file->getMTime();
            }
        }

        return $out;
    }
}
