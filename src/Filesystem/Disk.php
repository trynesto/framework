<?php

declare(strict_types=1);

namespace Nesto\Filesystem;

use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\Visibility;
use Nesto\Http\File;
use Nyholm\Psr7\Response as PsrResponse;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * One configured disk (Laravel's Storage::disk('s3')), backed by a Flysystem operator.
 *
 *   $disk->put('reports/today.txt', 'hello');
 *   $path = $disk->putFile('avatars', $uploadedFile);      // avatars/3f9c…e1.jpg
 *   $disk->url($path);                                     // https://cdn.example.com/avatars/3f9c…e1.jpg
 *   return $disk->download('invoices/7.pdf');              // PSR-7 response
 */
final class Disk
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private FilesystemOperator $driver,
        private array $config = [],
        public readonly string $name = '',
    ) {}

    /** @internal Storage::fake() points a disk that was already injected at the fake one. */
    public function swap(self $disk): void
    {
        $this->driver = $disk->driver;
        $this->config = $disk->config;
    }

    // ---- reading -----------------------------------------------------------------------

    public function exists(string $path): bool
    {
        try {
            return $this->driver->fileExists($path) || $this->driver->directoryExists($path);
        } catch (UnableToCheckExistence) {
            return false;
        }
    }

    public function missing(string $path): bool
    {
        return ! $this->exists($path);
    }

    /** File contents, or null when the file does not exist. */
    public function get(string $path): ?string
    {
        try {
            return $this->driver->read($path);
        } catch (UnableToReadFile) {
            return null;
        }
    }

    /** @return resource|null */
    public function readStream(string $path)
    {
        try {
            return $this->driver->readStream($path);
        } catch (UnableToReadFile) {
            return null;
        }
    }

    public function size(string $path): int
    {
        return $this->driver->fileSize($path);
    }

    public function lastModified(string $path): int
    {
        return $this->driver->lastModified($path);
    }

    public function mimeType(string $path): string
    {
        return $this->driver->mimeType($path);
    }

    public function getVisibility(string $path): string
    {
        return $this->driver->visibility($path);
    }

    // ---- writing -----------------------------------------------------------------------

    /**
     * Write contents (string, stream resource, PSR stream or uploaded File) to $path.
     *
     * @param array<string, mixed>|string $options a visibility ('public' / 'private') or Flysystem config
     */
    public function put(string $path, mixed $contents, array|string $options = []): bool
    {
        $config = $this->writeConfig($options);
        if ($contents instanceof File) {
            $contents = $contents->stream();
        }
        $opened = null;
        if ($contents instanceof StreamInterface) {
            // read through our own handle: the caller keeps a usable stream (mimeType(), a second store(), ...)
            $uri = $contents->getMetadata('uri');
            if (is_string($uri) && is_file($uri) && ($opened = @fopen($uri, 'rb')) !== false) {
                $contents = $opened;
            } else {
                if ($contents->isSeekable()) {
                    $contents->rewind();
                }
                $contents = $contents->getContents();
            }
        }

        try {
            is_resource($contents)
                ? $this->driver->writeStream($path, $contents, $config)
                : $this->driver->write($path, (string) $contents, $config);
        } finally {
            if (is_resource($opened)) {
                fclose($opened);
            }
        }

        return true;
    }

    /**
     * Store an uploaded File (or a local file path) under $directory with a random name and its real extension.
     *
     * @param array<string, mixed>|string $options
     */
    public function putFile(string $directory, File|string $file, array|string $options = []): string
    {
        $extension = $file instanceof File ? $file->extension() : strtolower(pathinfo($file, PATHINFO_EXTENSION));

        return $this->putFileAs($directory, $file, bin2hex(random_bytes(16)) . ($extension !== '' ? ".$extension" : ''), $options);
    }

    /** @param array<string, mixed>|string $options */
    public function putFileAs(string $directory, File|string $file, string $name, array|string $options = []): string
    {
        if ($name === '' || $name !== basename($name) || str_starts_with($name, '.')) {
            throw new \InvalidArgumentException("Invalid file name \"$name\".");
        }
        $path = trim(trim($directory, '/') . '/' . $name, '/');
        if (is_string($file)) {
            $stream = @fopen($file, 'rb') ?: throw new \RuntimeException("Unable to open $file.");
            try {
                $this->put($path, $stream, $options);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        } else {
            $this->put($path, $file, $options);
        }

        return $path;
    }

    public function prepend(string $path, string $data, string $separator = PHP_EOL): bool
    {
        return $this->put($path, $this->exists($path) ? $data . $separator . $this->get($path) : $data);
    }

    public function append(string $path, string $data, string $separator = PHP_EOL): bool
    {
        return $this->put($path, $this->exists($path) ? $this->get($path) . $separator . $data : $data);
    }

    /** @param string|list<string> $paths */
    public function delete(string|array $paths): bool
    {
        $ok = true;
        foreach ((array) $paths as $path) {
            try {
                $this->driver->delete($path);
            } catch (FilesystemException) {
                $ok = false;
            }
        }

        return $ok;
    }

    public function copy(string $from, string $to): bool
    {
        $this->driver->copy($from, $to);

        return true;
    }

    public function move(string $from, string $to): bool
    {
        $this->driver->move($from, $to);

        return true;
    }

    public function setVisibility(string $path, string $visibility): bool
    {
        $this->driver->setVisibility($path, $visibility);

        return true;
    }

    // ---- directories -------------------------------------------------------------------

    /** @return list<string> */
    public function files(string $directory = '', bool $recursive = false): array
    {
        return $this->listPaths($directory, $recursive, static fn (StorageAttributes $a) => $a instanceof FileAttributes);
    }

    /** @return list<string> */
    public function allFiles(string $directory = ''): array
    {
        return $this->files($directory, true);
    }

    /** @return list<string> */
    public function directories(string $directory = '', bool $recursive = false): array
    {
        return $this->listPaths($directory, $recursive, static fn (StorageAttributes $a) => $a instanceof DirectoryAttributes);
    }

    /** @return list<string> */
    public function allDirectories(string $directory = ''): array
    {
        return $this->directories($directory, true);
    }

    public function makeDirectory(string $path): bool
    {
        $this->driver->createDirectory($path, $this->writeConfig([]));

        return true;
    }

    public function deleteDirectory(string $path): bool
    {
        $this->driver->deleteDirectory($path);

        return true;
    }

    // ---- URLs and responses ------------------------------------------------------------

    /** Public URL: the disk's "url" option + path (+ the s3 "root" key prefix), or the driver's own (S3). */
    public function url(string $path): string
    {
        $base = $this->config['url'] ?? null;
        if (is_string($base) && $base !== '') {
            // a local root is a folder, an s3 root prefixes every key ("prefix" is the older name)
            $prefix = trim((string) ($this->config['prefix'] ?? (($this->config['driver'] ?? null) === 's3' ? $this->config['root'] ?? '' : '')), '/');

            return rtrim($base, '/') . '/' . ltrim(($prefix !== '' ? "$prefix/" : '') . ltrim($path, '/'), '/');
        }
        try {
            return $this->driver->publicUrl($path);
        } catch (FilesystemException $e) {
            throw new \RuntimeException("Disk [{$this->name}] has no public URL: set its \"url\" option in config/filesystems.php.", 0, $e);
        }
    }

    /** A URL that expires (S3 presigned URL). */
    public function temporaryUrl(string $path, \DateTimeInterface $expiresAt, array $options = []): string
    {
        try {
            return $this->driver->temporaryUrl($path, $expiresAt, $options);
        } catch (FilesystemException $e) {
            throw new \RuntimeException("Disk [{$this->name}] does not support temporary URLs.", 0, $e);
        }
    }

    /** Absolute path on a local disk. */
    public function path(string $path = ''): string
    {
        if (($this->config['driver'] ?? null) !== 'local') {
            throw new \RuntimeException("Disk [{$this->name}] is not a local disk.");
        }

        return rtrim((string) $this->config['root'], '/') . ($path !== '' ? '/' . ltrim($path, '/') : '');
    }

    /** Stream the file back as an attachment. */
    public function download(string $path, ?string $name = null, array $headers = []): ResponseInterface
    {
        return $this->response($path, $name, $headers, 'attachment');
    }

    /** Stream the file back inline (images, PDFs shown in the browser). */
    public function response(string $path, ?string $name = null, array $headers = [], string $disposition = 'inline'): ResponseInterface
    {
        $stream = $this->readStream($path) ?? throw new \Nesto\Common\Exceptions\NotFoundException("File $path not found");
        $name ??= basename($path);
        $fallback = preg_replace('/[^\x20-\x7e]|["\\\\%\/]/', '_', $name);

        return new PsrResponse(200, $headers + [
            'Content-Type' => $this->mimeType($path),
            'Content-Length' => (string) $this->size($path),
            'Content-Disposition' => sprintf('%s; filename="%s"; filename*=UTF-8\'\'%s', $disposition, $fallback, rawurlencode($name)),
        ], Stream::create($stream));
    }

    // ---- escape hatches ----------------------------------------------------------------

    /** The underlying Flysystem operator, for anything not wrapped here. */
    public function operator(): FilesystemOperator
    {
        return $this->driver;
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return $this->config;
    }

    /** @param array<string, mixed>|string $options @return array<string, mixed> */
    private function writeConfig(array|string $options): array
    {
        $options = is_string($options) ? ['visibility' => $options] : $options;
        if (! isset($options['visibility']) && isset($this->config['visibility'])) {
            $options['visibility'] = $this->config['visibility'] === Visibility::PUBLIC ? Visibility::PUBLIC : Visibility::PRIVATE;
        }

        return $options;
    }

    /** @return list<string> */
    private function listPaths(string $directory, bool $recursive, \Closure $filter): array
    {
        $paths = [];
        foreach ($this->driver->listContents($directory, $recursive)->filter($filter) as $item) {
            $paths[] = $item->path();
        }
        sort($paths);

        return $paths;
    }
}
