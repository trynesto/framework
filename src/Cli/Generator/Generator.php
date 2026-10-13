<?php

declare(strict_types=1);

namespace Nesto\Cli\Generator;

use Nesto\Cli\CliException;
use Nesto\Cli\ProjectContext;

/**
 * Runs a schematic (module, controller, service, ... resource) against a project.
 *
 *   $gen = new Generator($context);
 *   $result = $gen->generate('resource', 'cats', ['spec' => true, 'orm' => false]);
 *   $result['created'];   // ['src/Cats/CatsModule.php', 'src/Cats/CatsController.php', ...]
 *   $result['updated'];   // ['src/AppModule.php']
 *   $result['skipped'];   // files that already existed
 *   $result['registered'];// [['file' => 'src/AppModule.php', 'key' => 'imports', 'class' => 'CatsModule']]
 *   $result['warnings'];  // e.g. a controller that could not be added to any module
 *
 * Options: flat (bool), path (?string), spec (bool, default true), orm (bool), force (bool).
 */
final class Generator
{
    /** Short class names the resource stubs import; an entity with one of these names gets aliased. */
    private const RESOURCE_IMPORTS = [
        'Body', 'Controller', 'Delete', 'Get', 'HttpCode', 'Param', 'Patch', 'Post',
        'Module', 'OrmModule', 'Injectable', 'NotFoundException', 'InjectRepository', 'Repository',
    ];

    public const SCHEMATICS = [
        'module' => ['mo'],
        'controller' => ['co'],
        'service' => ['s'],
        'guard' => ['gu'],
        'pipe' => ['pi'],
        'interceptor' => ['itc'],
        'filter' => ['f'],
        'middleware' => ['mi'],
        'class' => ['cl'],
        'dto' => [],
        'entity' => ['e'],
        'resource' => ['res'],
    ];

    /** @var array{created: list<string>, updated: list<string>, skipped: list<string>, registered: list<array{file: string, key: string, class: string}>, warnings: list<string>} */
    private array $result = ['created' => [], 'updated' => [], 'skipped' => [], 'registered' => [], 'warnings' => []];
    /** @var array<string, mixed> */
    private array $options = [];

    public function __construct(private readonly ProjectContext $context) {}

    public static function resolveSchematic(string $input): string
    {
        $input = strtolower($input);
        foreach (self::SCHEMATICS as $name => $aliases) {
            if ($input === $name || in_array($input, $aliases, true)) {
                return $name;
            }
        }
        throw new CliException(sprintf('Unknown schematic "%s". Available: %s.', $input, implode(', ', array_keys(self::SCHEMATICS))));
    }

    /**
     * @param array<string, mixed> $options
     * @return array{created: list<string>, updated: list<string>, skipped: list<string>, registered: list<array{file: string, key: string, class: string}>, warnings: list<string>}
     */
    public function generate(string $schematic, string $name, array $options = []): array
    {
        $schematic = self::resolveSchematic($schematic);
        $this->result = ['created' => [], 'updated' => [], 'skipped' => [], 'registered' => [], 'warnings' => []];
        $this->options = $options + ['flat' => false, 'path' => null, 'spec' => true, 'orm' => false, 'force' => false];

        match ($schematic) {
            'module' => $this->module($name),
            'controller' => $this->simple($name, 'controller', 'Controller', 'spec-controller', registerAs: 'controllers'),
            'service' => $this->simple($name, 'service', 'Service', 'spec-provider', registerAs: 'providers'),
            'guard' => $this->simple($name, 'guard', 'Guard', 'spec-class'),
            'pipe' => $this->simple($name, 'pipe', 'Pipe', 'spec-class'),
            'interceptor' => $this->simple($name, 'interceptor', 'Interceptor', 'spec-class'),
            'filter' => $this->simple($name, 'filter', 'Filter', 'spec-class'),
            'middleware' => $this->simple($name, 'middleware', 'Middleware', 'spec-class'),
            'class' => $this->simple($name, 'class', '', 'spec-class'),
            'dto' => $this->dto($name),
            'entity' => $this->entity($name),
            'resource' => $this->resource($name),
        };

        return $this->result;
    }

    // ---- schematics ---------------------------------------------------------------------

    private function module(string $name): void
    {
        $n = $this->naming($name);
        $class = $n->pascal . 'Module';
        $this->write($n->dir . "/$class.php", Stub::render('module', ['namespace' => $n->namespace, 'class' => $class]));
        $this->registerInRootModule($n->namespace . '\\' . $class);
    }

    private function simple(string $name, string $stub, string $suffix, string $specStub, ?string $registerAs = null): void
    {
        $n = $this->naming($name);
        $class = str_ends_with($n->pascal, $suffix) && $suffix !== '' ? $n->pascal : $n->pascal . $suffix;
        $fqcn = $n->namespace . '\\' . $class;
        $this->write($n->dir . "/$class.php", Stub::render($stub, [
            'namespace' => $n->namespace,
            'class' => $class,
            'route' => $n->kebab,
            'kebab' => $n->kebab,
        ]));
        if ($registerAs !== null) {
            $this->registerInNearestModule($n->dir, $registerAs, $fqcn);
        }
        $this->spec($n, $class, $specStub, ['providers' => '']);
    }

    private function dto(string $name): void
    {
        // `g dto cats/create-cat` → src/Cats/Dto/CreateCatDto.php ; `g dto cats` → src/Cats/Dto/CatsDto.php
        $segments = explode('/', trim(str_replace('\\', '/', $name), '/'));
        $base = array_pop($segments);
        $n = $segments === []
            ? $this->naming($base)
            : $this->naming(implode('/', $segments) . '/' . $base, flat: true);
        $class = str_ends_with(Str::pascal($base), 'Dto') ? Str::pascal($base) : Str::pascal($base) . 'Dto';
        $dir = $this->options['flat'] ? $n->dir : $n->dir . '/Dto';
        $ns = $this->options['flat'] ? $n->namespace : $n->subNamespace('Dto');
        $this->write($dir . "/$class.php", Stub::render('dto', ['namespace' => $ns, 'class' => $class]));
    }

    private function entity(string $name): void
    {
        // `g entity cat` → src/Cats/Entities/Cat.php
        $segments = explode('/', trim(str_replace('\\', '/', $name), '/'));
        $base = array_pop($segments);
        $folderName = implode('/', [...$segments, Str::plural(Str::pascal($base))]);
        $n = $this->naming($folderName);
        $class = Str::singular(Str::pascal($base));
        $dir = $this->options['flat'] ? $n->dir : $n->dir . '/Entities';
        $ns = $this->options['flat'] ? $n->namespace : $n->subNamespace('Entities');
        $this->write($dir . "/$class.php", Stub::render('entity', ['namespace' => $ns, 'class' => $class, 'table' => Str::snake(Str::plural($base))]));
    }

    private function resource(string $name): void
    {
        $n = $this->naming($name);
        $orm = (bool) $this->options['orm'];
        $module = $n->pascal . 'Module';
        $controller = $n->pascal . 'Controller';
        $service = $n->pascal . 'Service';
        $entity = $n->singular;
        $createDto = 'Create' . $entity . 'Dto';
        $updateDto = 'Update' . $entity . 'Dto';
        // `g resource posts` would import both the #[Post] attribute and the Post entity: alias the entity.
        $entityAlias = in_array($entity, [...self::RESOURCE_IMPORTS, $module, $controller, $service, $createDto, $updateDto], true)
            ? $entity . 'Entity'
            : $entity;
        $vars = [
            'namespace' => $n->namespace,
            'route' => $n->kebab,
            'kebab' => $n->kebab,
            'module' => $module,
            'controller' => $controller,
            'service' => $service,
            'serviceVar' => lcfirst($service),
            'repoVar' => lcfirst($n->plural),
            'entity' => $entity,
            'entityAlias' => $entityAlias,
            'entityAs' => $entityAlias === $entity ? '' : " as $entityAlias",
            'createDto' => $createDto,
            'updateDto' => $updateDto,
            'table' => $n->snake,
        ];

        $this->write($n->dir . "/$module.php", Stub::render($orm ? 'resource-module-orm' : 'resource-module', $vars + ['class' => $module]));
        $this->write($n->dir . "/$controller.php", Stub::render('resource-controller', $vars + ['class' => $controller]));
        $this->write($n->dir . "/$service.php", Stub::render($orm ? 'resource-service-orm' : 'resource-service', $vars + ['class' => $service]));
        $this->write($n->dir . "/Dto/$createDto.php", Stub::render('resource-create-dto', ['namespace' => $n->subNamespace('Dto'), 'class' => $createDto, 'route' => $n->kebab]));
        $this->write($n->dir . "/Dto/$updateDto.php", Stub::render('resource-update-dto', ['namespace' => $n->subNamespace('Dto'), 'class' => $updateDto, 'route' => $n->kebab]));
        $this->write($n->dir . "/Entities/$entity.php", Stub::render($orm ? 'entity' : 'resource-entity', ['namespace' => $n->subNamespace('Entities'), 'class' => $entity, 'table' => $n->snake]));

        $this->registerInRootModule($n->namespace . '\\' . $module);

        if ($this->options['spec']) {
            $specClass = $controller . 'Test';
            $this->writeSpec($n, $specClass, Stub::render('spec-resource', [
                'testNamespace' => $this->testNamespace($n),
                'class' => $specClass,
                'moduleFqcn' => $n->namespace . '\\' . $module,
                'module' => $module,
                'route' => $n->kebab,
                'ormUse' => $orm ? "\nuse Nesto\\Orm\\OrmModule;" : '',
                'ormImport' => $orm ? "new OrmModule(type: 'sqlite', database: ':memory:', synchronize: true), " : '',
            ]));
        }
    }

    // ---- helpers ---------------------------------------------------------------------------

    private function naming(string $name, ?bool $flat = null): Naming
    {
        return Naming::for(
            $name,
            $this->context->sourceRoot(),
            $this->context->appNamespace(),
            $flat ?? (bool) $this->options['flat'],
            $this->options['path'] !== null && $this->options['path'] !== '' ? (string) $this->options['path'] : null,
            (string) $this->context->config('sourceRoot'),
        );
    }

    /** @param array<string, scalar|null> $extra */
    private function spec(Naming $n, string $class, string $stub, array $extra = []): void
    {
        if (! $this->options['spec']) {
            return;
        }
        $specClass = $class . 'Test';
        $this->writeSpec($n, $specClass, Stub::render($stub, [
            'testNamespace' => $this->testNamespace($n),
            'class' => $specClass,
            'fqcn' => $n->namespace . '\\' . $class,
            'subject' => $class,
        ] + $extra));
    }

    private function writeSpec(Naming $n, string $specClass, string $content): void
    {
        $dir = rtrim($this->context->path((string) $this->context->config('tests')) . '/' . implode('/', $n->folderSegments), '/');
        $this->write($dir . "/$specClass.php", $content);
    }

    private function testNamespace(Naming $n): string
    {
        return implode('\\', array_filter([trim((string) $this->context->config('testNamespace'), '\\'), ...$n->folderSegments], fn ($s) => $s !== ''));
    }

    private function write(string $file, string $content): void
    {
        $relative = $this->relative($file);
        if (is_file($file) && ! $this->options['force']) {
            $this->result['skipped'][] = $relative;

            return;
        }
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, $content);
        $this->result['created'][] = $relative;
    }

    /**
     * Add a controller/provider to the nearest module, like `nest g`: the first *Module.php in $dir, else in its
     * parent folders up to the source root, else the root module. Warns when no module could be updated.
     */
    private function registerInNearestModule(string $dir, string $key, string $fqcn): void
    {
        $file = $this->nearestModuleFile($dir) ?? $this->context->rootModuleFile();
        $short = substr($fqcn, (int) strrpos($fqcn, '\\') + 1);
        if ($file === null) {
            $this->result['warnings'][] = "$short was not added to any module (no *Module.php found and the root module file is unknown): add it to a module's $key yourself.";

            return;
        }
        $this->registerIn($file, $key, $fqcn);
    }

    private function nearestModuleFile(string $dir): ?string
    {
        $sourceRoot = rtrim($this->context->sourceRoot(), '/');
        for ($current = rtrim($dir, '/'); ; $current = dirname($current)) {
            $modules = glob($current . '/*Module.php') ?: [];
            if ($modules !== []) {
                sort($modules);

                return $modules[0];
            }
            if ($current === $sourceRoot || ! str_starts_with($current, $sourceRoot . '/')) {
                return null;
            }
        }
    }

    private function registerIn(string $file, string $key, string $fqcn): void
    {
        $short = substr($fqcn, (int) strrpos($fqcn, '\\') + 1);
        try {
            $changed = ModuleEditor::register($file, $key, $fqcn);
        } catch (\RuntimeException $e) {
            $this->result['warnings'][] = "$short was not added to {$this->relative($file)}: {$e->getMessage()}";

            return;
        }
        if ($changed) {
            $this->result['updated'][] = $this->relative($file);
            $this->result['registered'][] = ['file' => $this->relative($file), 'key' => $key, 'class' => $short];
        }
    }

    /** Add a module class to the root module's imports. */
    private function registerInRootModule(string $moduleFqcn): void
    {
        $root = $this->context->rootModule();
        if ($root === '' || ltrim($root, '\\') === $moduleFqcn) {
            return;
        }
        $file = $this->context->rootModuleFile();
        if ($file === null) {
            return;
        }
        $this->registerIn($file, 'imports', $moduleFqcn);
    }

    private function relative(string $file): string
    {
        $root = rtrim($this->context->rootDir(), '/') . '/';

        return str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
    }
}
