<?php

declare(strict_types=1);

namespace Nesto\Config;

use Nesto\Common\Contracts\DynamicModuleFactory;
use Nesto\Common\DynamicModule;
use Nesto\Common\Provider;
use Nesto\Core\Packages\PackageManifest;
use Nesto\Core\Paths;

/**
 * Nest's @nestjs/config ConfigModule.
 *
 * Inside a #[Module] attribute (constant expressions only):
 *
 *   #[Module(imports: [
 *       new ConfigModule(
 *           isGlobal: true,
 *           envFilePath: ['.env', '.env.local'],
 *           load: [[AppConfig::class, 'load'], 'database' => [DatabaseConfig::class, 'load']],
 *           validationSchema: ['PORT' => 'required|int', 'APP_ENV' => 'in:dev,prod|default:dev'],
 *       ),
 *   ])]
 *
 * At runtime (closures allowed):
 *
 *   ConfigModule::forRoot(['isGlobal' => true, 'load' => [fn () => ['app' => ['name' => 'Nesto']]]]);
 *   ConfigModule::forFeature(fn () => ['mail' => ['from' => 'noreply@example.com']]);
 *
 * `load` callables return arrays that are deep-merged into the config tree; a string key namespaces the result
 * (`'database' => $callable` ⇒ `$config->get('database.host')`). The .env file(s) are loaded when the module is
 * converted (at scan time), so `env()` / `$_ENV` are populated before any other provider factory runs.
 *
 * Config files: every `config/<key>.php` of the project returns an array available under `<key>`
 * (`config/mail.php` ⇒ `$config->get('mail.from')`). Installed packages contribute their defaults under the same
 * keys (composer.json `extra.nesto.config`), so the layering is:
 *
 *   package default config  <  project config/<key>.php  <  `load` factories      (and .env feeds them all via env())
 */
final class ConfigModule implements DynamicModuleFactory
{
    public const OPTIONS = 'CONFIG_MODULE_OPTIONS';
    public const VALIDATED_ENV = 'CONFIG_VALIDATED_ENV';

    private static int $featureCounter = 0;

    /**
     * @param string|list<string>                       $envFilePath      file(s) relative to the project root (or absolute); missing files are ignored
     * @param list<callable|array|string>|array<string, callable|array|string> $load config factories ([Class::class, 'method'], closure, or invokable class name)
     * @param array<string, string|list<string>>|null   $validationSchema  rule strings per env key, see SchemaValidator
     */
    public function __construct(
        public readonly bool $isGlobal = false,
        public readonly string|array $envFilePath = '.env',
        public readonly bool $ignoreEnvFile = false,
        public readonly array $load = [],
        public readonly ?array $validationSchema = null,
        public readonly bool $cache = true,
        public readonly bool $expandVariables = true,
        public readonly bool $override = false,
        public readonly string|false|null $configPath = null,
        public readonly bool $packageConfig = true,
        public readonly ?string $vendorDir = null,
    ) {}

    /**
     * @param array{isGlobal?: bool, envFilePath?: string|list<string>, ignoreEnvFile?: bool, load?: array, validationSchema?: ?array, cache?: bool, expandVariables?: bool, override?: bool, configPath?: string|false|null, packageConfig?: bool, vendorDir?: ?string} $options
     */
    public static function forRoot(array $options = []): DynamicModule
    {
        return (new self(...$options))->toDynamicModule();
    }

    /**
     * Register additional configuration that is merged into the (already registered) ConfigService.
     *
     * @param array<string, mixed>|callable|array{0: class-string, 1: string} $config a tree or a factory returning one
     */
    public static function forFeature(array|callable $config): DynamicModule
    {
        $token = 'CONFIG_FEATURE#' . (++self::$featureCounter);
        $factory = function (ConfigService $service) use ($config): array {
            $tree = self::isFactory($config) ? self::invoke($config) : $config;
            $service->merge($tree);

            return $tree;
        };

        return new DynamicModule(
            module: self::class,
            providers: [new Provider($token, useFactory: $factory, inject: [ConfigService::class])],
            exports: [$token],
        );
    }

    public function toDynamicModule(): DynamicModule
    {
        $service = $this->buildService();

        return new DynamicModule(
            module: self::class,
            providers: [
                new Provider(ConfigService::class, useValue: $service),
                new Provider(self::OPTIONS, useValue: $this->optionsArray()),
            ],
            exports: [ConfigService::class, self::OPTIONS],
            global: $this->isGlobal,
        );
    }

    /** Build the ConfigService outside of a module graph (tests, scripts). */
    public function buildService(): ConfigService
    {
        if (! $this->ignoreEnvFile) {
            Env::load($this->resolvePaths(), $this->override);
        }

        $tree = [];
        if ($this->packageConfig) {
            $tree = self::loadFiles(PackageManifest::forProject($this->vendorDir)->configFiles());
        }
        $directory = $this->configDirectory();
        if ($directory !== null) {
            $tree = self::merge($tree, self::loadFiles(self::filesIn($directory)));
        }

        foreach ($this->load as $namespace => $factory) {
            $loaded = self::invoke($factory);
            if (! is_array($loaded)) {
                throw new ConfigException('ConfigModule `load` factories must return an array.');
            }
            $tree = array_replace_recursive($tree, is_string($namespace) ? [$namespace => $loaded] : $loaded);
        }

        $validated = [];
        if ($this->validationSchema !== null) {
            $env = [];
            foreach (array_keys($this->validationSchema) as $key) {
                $env[$key] = Env::get($key);
            }
            $validated = SchemaValidator::validate($this->validationSchema, $env);
            // make defaults visible to env()/getenv() consumers too (never overriding real values)
            Env::populate($validated, override: false);
        }

        return new ConfigService($tree, $validated, $this->cache);
    }

    /** `config/` next to vendor/ unless configPath says otherwise (false disables config files). */
    private function configDirectory(): ?string
    {
        if ($this->configPath === false) {
            return null;
        }
        if ($this->configPath !== null) {
            return Paths::resolve($this->configPath);
        }

        return $this->vendorDir !== null ? dirname($this->vendorDir) . '/config' : Paths::config();
    }

    /** @return array<string, string> key => file */
    private static function filesIn(string $directory): array
    {
        $files = [];
        foreach (glob(rtrim($directory, '/') . '/*.php') ?: [] as $file) {
            $files[basename($file, '.php')] = $file;
        }

        return $files;
    }

    /**
     * @param array<string, string> $files key => file returning an array
     * @return array<string, mixed>
     */
    private static function loadFiles(array $files): array
    {
        $tree = [];
        foreach ($files as $key => $file) {
            if (! is_file($file)) {
                continue;
            }
            $values = (static fn (string $__file) => require $__file)($file);
            if (! is_array($values)) {
                throw new ConfigException("Config file $file must return an array.");
            }
            $tree = self::merge($tree, [$key => $values]);
        }

        return $tree;
    }

    /**
     * Deep merge where $over wins; lists are replaced as a whole (a published `'channels' => ['slack']`
     * must not keep the package's other default channels).
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    public static function merge(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && ! array_is_list($value) && ! array_is_list($base[$key])) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /** @return list<string> */
    private function resolvePaths(): array
    {
        $paths = [];
        foreach ((array) $this->envFilePath as $path) {
            $paths[] = Paths::resolve($path); // relative to the project root, whatever the cwd (FPM runs in public/)
        }

        return $paths;
    }

    /** @return array<string, mixed> */
    private function optionsArray(): array
    {
        return [
            'isGlobal' => $this->isGlobal,
            'envFilePath' => $this->envFilePath,
            'ignoreEnvFile' => $this->ignoreEnvFile,
            'validationSchema' => $this->validationSchema,
            'cache' => $this->cache,
            'expandVariables' => $this->expandVariables,
            'override' => $this->override,
            'configPath' => $this->configPath,
            'packageConfig' => $this->packageConfig,
        ];
    }

    private static function isFactory(mixed $value): bool
    {
        if ($value instanceof \Closure || is_string($value)) {
            return true;
        }

        return is_array($value) && count($value) === 2 && isset($value[0], $value[1]) && is_string($value[1]) && is_callable($value);
    }

    private static function invoke(mixed $factory): mixed
    {
        if (is_string($factory) && class_exists($factory)) {
            $factory = new $factory;
        }
        if (! is_callable($factory)) {
            throw new ConfigException('ConfigModule `load` entries must be callables ([Class::class, "method"], closures or invokable classes).');
        }

        return $factory();
    }
}
