<?php

declare(strict_types=1);

namespace Nesto\Filesystem;

use Closure;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use League\Flysystem\Visibility;
use Nesto\Common\Attributes\Inject;
use Nesto\Common\Attributes\Optional;
use Nesto\Common\Contracts\OnApplicationShutdown;
use Nesto\Config\ConfigService;
use Nesto\Core\Paths;

/**
 * Resolves the disks of config/filesystems.php (Laravel's Storage / FilesystemManager).
 *
 *   public function __construct(private readonly FilesystemManager $storage) {}
 *   $this->storage->disk('s3')->put('reports/today.csv', $csv);
 *
 * Drivers: local, s3 (needs league/flysystem-aws-s3-v3), anything registered with extend(), or an invokable
 * class named as the driver (`'driver' => DropboxDriver::class` receives the disk config).
 *
 * Storage::fake() holds until the application closes, whenever it is called: a manager built later (the
 * application's, at boot) takes over the fakes of the current one, and disks already injected are swapped in place.
 */
final class FilesystemManager implements OnApplicationShutdown
{
    public const OPTIONS = 'NESTO_FILESYSTEM_OPTIONS';

    private static ?self $instance = null;

    /** @var array{default: string, disks: array<string, array<string, mixed>>, links: array<string, string>}|null */
    private ?array $config = null;

    /** @var array<string, Disk> */
    private array $disks = [];

    /** @var array<string, Disk> disks swapped by fake() */
    private array $fakes = [];

    /** @var array<string, Closure(array<string, mixed>, self): (FilesystemAdapter|FilesystemOperator|Disk)> */
    private array $drivers = [];

    /** @param array<string, mixed>|null $options overrides from `new FilesystemModule(...)` */
    public function __construct(
        #[Optional] private readonly ?ConfigService $configService = null,
        #[Optional] #[Inject(self::OPTIONS)] private readonly ?array $options = null,
    ) {
        // Storage::fake() before the application built its manager: the fakes carry over
        foreach (self::$instance->fakes ?? [] as $name => $disk) {
            $this->fakes[$name] = $this->disks[$name] = $disk;
        }
        self::$instance = $this;
    }

    /** A manager from an explicit configuration (tests, scripts). */
    public static function fromConfig(array $config): self
    {
        $manager = new self();
        $manager->config = FilesystemConfig::merge($config);

        return $manager;
    }

    /** The manager of the running application, or one built from config/filesystems.php. */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public static function setInstance(?self $manager): void
    {
        self::$instance = $manager;
    }

    public function disk(?string $name = null): Disk
    {
        $name ??= $this->getDefaultDisk();

        return $this->disks[$name] ??= $this->fakes[$name] ?? $this->build($this->diskConfig($name), $name);
    }

    public function getDefaultDisk(): string
    {
        return $this->config()['default'];
    }

    /**
     * Register a custom driver: `$storage->extend('ftp', fn (array $config) => new FtpAdapter(...))`.
     * The factory returns a Flysystem adapter, a Flysystem operator or a ready Disk.
     */
    public function extend(string $driver, Closure $factory): self
    {
        $this->drivers[$driver] = $factory;

        return $this;
    }

    /** Build a disk from a config array that is not in config/filesystems.php. */
    public function build(array $config, string $name = 'ondemand'): Disk
    {
        $driver = (string) ($config['driver'] ?? throw new \InvalidArgumentException("Disk [$name] has no \"driver\"."));
        $made = match (true) {
            isset($this->drivers[$driver]) => ($this->drivers[$driver])($config, $this),
            $driver === 'local' => $this->localAdapter($config, $name),
            $driver === 's3' => $this->s3Adapter($config, $name),
            class_exists($driver) && is_callable($instance = new $driver()) => $instance($config, $this),
            default => throw new \InvalidArgumentException("Disk [$name] uses driver \"$driver\", which is not supported. Register it with FilesystemManager::extend('$driver', ...)."),
        };

        if ($made instanceof Disk) {
            return $made;
        }
        if ($made instanceof FilesystemAdapter) {
            $made = new Filesystem($made, (array) ($config['options'] ?? []));
        }
        if (! $made instanceof FilesystemOperator) {
            throw new \UnexpectedValueException("The \"$driver\" driver must return a Flysystem adapter, a Flysystem operator or a Disk.");
        }
        if ($driver === 'local') {
            $config['root'] = Paths::resolve((string) $config['root']);
        }

        return new Disk($made, $config, $name);
    }

    /** Replace a disk (e.g. with a test double). */
    public function set(string $name, Disk $disk): self
    {
        $this->disks[$name] = $disk;

        return $this;
    }

    /**
     * Swap a disk for an empty local folder under storage/framework/testing (Laravel's Storage::fake()), until the
     * application closes. A Disk instance already handed out (injected) is redirected to the fake too.
     *
     * @param array<string, mixed> $config
     */
    public function fake(?string $name = null, array $config = []): Disk
    {
        $name ??= $this->getDefaultDisk();
        $root = Paths::storage('framework/testing/disks/' . $name);
        self::removeDirectory($root);
        $original = $this->config()['disks'][$name] ?? [];
        $disk = $this->build(['driver' => 'local', 'root' => $root, 'url' => $original['url'] ?? null, 'visibility' => $original['visibility'] ?? null] + $config, $name);
        if (isset($this->disks[$name])) {
            $this->disks[$name]->swap($disk);
            $disk = $this->disks[$name];
        }

        return $this->fakes[$name] = $this->disks[$name] = $disk;
    }

    /** The application closed: Storage:: no longer uses this manager (nor its fakes). */
    public function onApplicationShutdown(?string $signal = null): void
    {
        if (self::$instance === $this) {
            self::$instance = null;
        }
    }

    public function forgetDisk(string ...$names): self
    {
        foreach ($names as $name) {
            unset($this->disks[$name]);
        }

        return $this;
    }

    /** @return array{default: string, disks: array<string, array<string, mixed>>, links: array<string, string>} */
    public function config(): array
    {
        if ($this->config === null) {
            $config = $this->configService?->get('filesystems');
            $this->config = is_array($config) ? FilesystemConfig::merge($config) : FilesystemConfig::load();
            if ($this->options !== null) {
                $this->config = FilesystemConfig::merge(array_replace_recursive($this->config, $this->options));
            }
        }

        return $this->config;
    }

    /** @return array<string, mixed> */
    private function diskConfig(string $name): array
    {
        return $this->config()['disks'][$name]
            ?? throw new \InvalidArgumentException("Disk [$name] is not configured (config/filesystems.php \"disks\").");
    }

    private function localAdapter(array $config, string $name): FilesystemAdapter
    {
        $root = Paths::resolve((string) ($config['root'] ?? throw new \InvalidArgumentException("Disk [$name] needs a \"root\".")));
        $visibility = PortableVisibilityConverter::fromArray(
            (array) ($config['permissions'] ?? []),
            ($config['directory_visibility'] ?? $config['visibility'] ?? Visibility::PRIVATE) === Visibility::PUBLIC ? Visibility::PUBLIC : Visibility::PRIVATE,
        );

        return new LocalFilesystemAdapter(
            $root,
            $visibility,
            (int) ($config['lock'] ?? LOCK_EX),
            ($config['links'] ?? null) === 'skip' ? LocalFilesystemAdapter::SKIP_LINKS : LocalFilesystemAdapter::DISALLOW_LINKS,
        );
    }

    private function s3Adapter(array $config, string $name): FilesystemAdapter
    {
        if (! class_exists(AwsS3V3Adapter::class)) {
            throw new \RuntimeException("Disk [$name] uses the s3 driver: run `composer require league/flysystem-aws-s3-v3`.");
        }
        $client = [
            'version' => 'latest',
            'region' => (string) ($config['region'] ?? 'us-east-1'),
            'use_path_style_endpoint' => (bool) ($config['use_path_style_endpoint'] ?? false),
        ];
        if (! empty($config['key']) && ! empty($config['secret'])) {
            $client['credentials'] = array_filter(['key' => $config['key'], 'secret' => $config['secret'], 'token' => $config['token'] ?? null]);
        }
        if (! empty($config['endpoint'])) {
            $client['endpoint'] = (string) $config['endpoint'];
        }

        return new AwsS3V3Adapter(
            new \Aws\S3\S3Client($client + (array) ($config['client'] ?? [])),
            (string) ($config['bucket'] ?? throw new \InvalidArgumentException("Disk [$name] needs a \"bucket\" (AWS_BUCKET).")),
            trim((string) ($config['root'] ?? ''), '/'),
            new \League\Flysystem\AwsS3V3\PortableVisibilityConverter(($config['visibility'] ?? Visibility::PRIVATE) === Visibility::PUBLIC ? Visibility::PUBLIC : Visibility::PRIVATE),
            null,
            (array) ($config['options'] ?? []),
            (bool) ($config['stream_reads'] ?? true),
        );
    }

    private static function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
