<?php

declare(strict_types=1);

namespace Nesto\Cli\Generator;

/**
 * Computes directory, namespace and class names for a generated artefact, Nest style.
 *
 *   $n = Naming::for('admin/user-profiles', sourceRoot: '/app/src', appNamespace: 'App');
 *   $n->folderSegments;   // ['Admin', 'UserProfiles']
 *   $n->dir;              // '/app/src/Admin/UserProfiles'
 *   $n->namespace;        // 'App\Admin\UserProfiles'
 *   $n->pascal;           // 'UserProfiles'
 *   $n->kebab;            // 'user-profiles'
 *   $n->singular;         // 'UserProfile'
 *
 * With flat: true the artefact's own folder segment is omitted (files go straight into the parent dir).
 * With path: 'Core' an extra leading folder/namespace segment is prepended.
 */
final class Naming
{
    /**
     * @param list<string> $folderSegments
     */
    public function __construct(
        public readonly string $raw,
        public readonly string $pascal,
        public readonly string $camel,
        public readonly string $kebab,
        public readonly string $snake,
        public readonly string $singular,
        public readonly string $singularCamel,
        public readonly string $singularKebab,
        public readonly string $plural,
        public readonly array $folderSegments,
        public readonly string $dir,
        public readonly string $namespace,
        public readonly string $relativeDir,
    ) {}

    public static function for(string $name, string $sourceRoot, string $appNamespace, bool $flat = false, ?string $path = null, string $relativeSourceRoot = 'src'): self
    {
        $name = trim(str_replace('\\', '/', $name), '/');
        if ($name === '') {
            throw new \InvalidArgumentException('A name is required.');
        }
        $segments = array_values(array_filter(explode('/', $name), fn ($s) => $s !== ''));
        $base = array_pop($segments);
        $prefix = [];
        if ($path !== null && trim($path, '/') !== '') {
            foreach (explode('/', trim(str_replace('\\', '/', $path), '/')) as $seg) {
                if ($seg !== '') {
                    $prefix[] = Str::pascal($seg);
                }
            }
        }
        $pascal = Str::pascal($base);
        $folder = [...$prefix, ...array_map(Str::pascal(...), $segments)];
        if (! $flat) {
            $folder[] = $pascal;
        }
        $singular = Str::singular($pascal);
        $relativeDir = rtrim($relativeSourceRoot . '/' . implode('/', $folder), '/');

        return new self(
            raw: $name,
            pascal: $pascal,
            camel: Str::camel($base),
            kebab: Str::kebab($base),
            snake: Str::snake($base),
            singular: $singular,
            singularCamel: lcfirst($singular),
            singularKebab: Str::kebab($singular),
            plural: Str::plural($pascal),
            folderSegments: $folder,
            dir: rtrim($sourceRoot . '/' . implode('/', $folder), '/'),
            namespace: implode('\\', array_filter([trim($appNamespace, '\\'), ...$folder], fn ($s) => $s !== '')),
            relativeDir: $relativeDir,
        );
    }

    /** Namespace for a sub-folder (e.g. 'Dto') of this artefact. */
    public function subNamespace(string $sub): string
    {
        return $this->namespace . '\\' . $sub;
    }
}
