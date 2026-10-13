<?php

declare(strict_types=1);

namespace Nesto\Filesystem;

/**
 * Static access to the disks, for places without DI (Laravel's Storage facade). Prefer injecting
 * FilesystemManager in services; this proxies to the same instance.
 *
 *   Storage::disk('public')->put('a.txt', 'hi');
 *   Storage::put('a.txt', 'hi');              // default disk
 *   Storage::fake('s3');                       // tests
 *
 * @method static bool put(string $path, mixed $contents, array|string $options = [])
 * @method static ?string get(string $path)
 * @method static bool exists(string $path)
 * @method static bool delete(string|array $paths)
 * @method static string putFile(string $directory, \Nesto\Http\File|string $file, array|string $options = [])
 * @method static string url(string $path)
 */
final class Storage
{
    public static function disk(?string $name = null): Disk
    {
        return FilesystemManager::instance()->disk($name);
    }

    public static function fake(?string $name = null, array $config = []): Disk
    {
        return FilesystemManager::instance()->fake($name, $config);
    }

    public static function manager(): FilesystemManager
    {
        return FilesystemManager::instance();
    }

    /** @param list<mixed> $arguments */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        return self::disk()->{$method}(...$arguments);
    }
}
