<?php

declare(strict_types=1);

namespace Nesto\Config;

/**
 * Nest's ConfigService. Reads from the loaded configuration tree first, then from the environment.
 *
 *   $config->get('app.name');                 // config tree: ['app' => ['name' => ...]]
 *                                             // then env: APP.NAME, then APP_NAME
 *   $config->get('database.host', '127.0.0.1');
 *   $config->get('PORT');                     // plain env var (cast: "true" → true, "3000" stays "3000")
 *   $config->getOrThrow('JWT_SECRET');        // throws ConfigException when missing
 *   $config->has('app.debug');
 *   $config->all();                           // the whole tree (validated env values merged under their key)
 *
 * Env lookups try the path as given, then the upper-snake form of the path:
 * `app.name` → `APP_NAME`, `database.readHost` → `DATABASE_READ_HOST`.
 * Values validated through `validationSchema` win over raw env values (and keep their cast type).
 */
class ConfigService
{
    /** @var array<string, mixed> */
    private array $cache = [];

    /**
     * @param array<string, mixed> $config       nested configuration tree
     * @param array<string, mixed> $validatedEnv env values that passed the validation schema (already cast)
     */
    public function __construct(
        private array $config = [],
        private array $validatedEnv = [],
        private readonly bool $cacheEnabled = true,
    ) {}

    /** Dot-path lookup in the config tree, falling back to the environment. */
    public function get(string $path, mixed $default = null): mixed
    {
        if ($this->cacheEnabled && array_key_exists($path, $this->cache)) {
            return $this->cache[$path] ?? $default;
        }

        $value = $this->lookup($path);
        if ($this->cacheEnabled) {
            $this->cache[$path] = $value;
        }

        return $value ?? $default;
    }

    /** Like get() but throws when the key resolves to null. */
    public function getOrThrow(string $path): mixed
    {
        $value = $this->get($path);
        if ($value === null) {
            throw new ConfigException("Configuration key \"$path\" is missing (looked in the config tree and the environment).");
        }

        return $value;
    }

    public function has(string $path): bool
    {
        return $this->get($path) !== null;
    }

    /** @return array<string, mixed> config tree + validated env values */
    public function all(): array
    {
        return array_replace_recursive($this->validatedEnv, $this->config);
    }

    /** Set a value in the config tree (dot-path). */
    public function set(string $path, mixed $value): void
    {
        $ref = &$this->config;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($ref)) {
                $ref = [];
            }
            if (! array_key_exists($segment, $ref) || ! is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
        $ref = $value;
        $this->cache = [];
    }

    /**
     * Deep-merge another tree into the configuration (used by ConfigModule::forFeature()).
     *
     * @param array<string, mixed> $config
     */
    public function merge(array $config): void
    {
        $this->config = array_replace_recursive($this->config, $config);
        $this->cache = [];
    }

    private function lookup(string $path): mixed
    {
        $sentinel = new \stdClass;
        $found = data_get($this->config, $path, $sentinel);
        if ($found !== $sentinel && $found !== null) {
            return $found;
        }

        foreach ($this->envCandidates($path) as $key) {
            if (array_key_exists($key, $this->validatedEnv)) {
                return $this->validatedEnv[$key];
            }
        }
        foreach ($this->envCandidates($path) as $key) {
            $raw = Env::get($key);
            if ($raw !== null) {
                return Env::cast($raw);
            }
        }

        // config tree may legitimately contain null; expose it as null (callers get $default)
        return null;
    }

    /** @return list<string> */
    private function envCandidates(string $path): array
    {
        $snake = strtoupper((string) preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', str_replace(['.', '-'], '_', $path)));

        return array_values(array_unique([$path, $snake]));
    }
}
