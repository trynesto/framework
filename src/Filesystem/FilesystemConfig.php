<?php

declare(strict_types=1);

namespace Nesto\Filesystem;

use Nesto\Config\Env;
use Nesto\Core\Paths;

/**
 * The `filesystems` configuration: built-in defaults (Laravel's local / public / s3 disks) under the
 * project's config/filesystems.php.
 */
final class FilesystemConfig
{
    /** @return array{default: string, disks: array<string, array<string, mixed>>, links: array<string, string>} */
    public static function defaults(): array
    {
        $appUrl = rtrim((string) (env('APP_URL') ?: 'http://localhost:3000'), '/');

        return [
            'default' => (string) (env('FILESYSTEM_DISK') ?: 'local'),
            'disks' => [
                'local' => [
                    'driver' => 'local',
                    'root' => Paths::storage('app/private'),
                ],
                'public' => [
                    'driver' => 'local',
                    'root' => Paths::storage('app/public'),
                    'url' => "$appUrl/storage",
                    'visibility' => 'public',
                ],
                's3' => [
                    'driver' => 's3',
                    'key' => env('AWS_ACCESS_KEY_ID'),
                    'secret' => env('AWS_SECRET_ACCESS_KEY'),
                    'region' => env('AWS_DEFAULT_REGION') ?: 'us-east-1',
                    'bucket' => env('AWS_BUCKET'),
                    'url' => env('AWS_URL'),
                    'endpoint' => env('AWS_ENDPOINT'),
                    'use_path_style_endpoint' => filter_var(env('AWS_USE_PATH_STYLE_ENDPOINT', false), FILTER_VALIDATE_BOOLEAN),
                ],
            ],
            // public/storage → storage/app/public, created by `php nesto storage:link`
            'links' => [
                Paths::public('storage') => Paths::storage('app/public'),
            ],
        ];
    }

    /**
     * Defaults with $config on top: scalar keys are replaced, a disk defined by the project replaces the
     * default disk of the same name entirely, links are replaced when given.
     *
     * @param array<string, mixed> $config
     * @return array{default: string, disks: array<string, array<string, mixed>>, links: array<string, string>}
     */
    public static function merge(array $config): array
    {
        $defaults = self::defaults();

        return [
            'default' => (string) ($config['default'] ?? $defaults['default']),
            'disks' => array_replace($defaults['disks'], (array) ($config['disks'] ?? [])),
            'links' => isset($config['links']) ? (array) $config['links'] : $defaults['links'],
        ];
    }

    /** Read .env and config/filesystems.php without the DI container (CLI commands, Storage outside a module). */
    public static function load(): array
    {
        $env = Paths::base('.env');
        if (is_file($env)) {
            Env::load([$env]);
        }
        $file = Paths::config('filesystems.php');
        $config = is_file($file) ? (static fn (string $__file) => require $__file)($file) : [];

        return self::merge(is_array($config) ? $config : []);
    }
}
