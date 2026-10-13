<?php

declare(strict_types=1);

namespace Nesto\Prisma\Generator;

use Nesto\Prisma\Client\Internal\DataModel;
use Nesto\Prisma\Client\Internal\ModelInfo;
use Nesto\Prisma\Client\Internal\RelationInfo;
use Nesto\Prisma\Dmmf\Document;
use Nesto\Prisma\Dmmf\Field;
use Nesto\Prisma\Dmmf\Generator;
use Nesto\Prisma\PrismaException;

/**
 * `prisma generate` for PHP: writes the typed client of a schema from its `nesto-client` generator block.
 *
 *   generator client {
 *     provider  = "nesto-client"
 *     output    = "../generated/prisma"     // relative to the schema file (default ../generated/prisma)
 *     namespace = "App\\Prisma"             // default: from composer.json PSR-4 for the output dir, else Generated\Prisma
 *   }
 *
 * Files (deterministic, no timestamps):
 *
 *   PrismaClient.php            class PrismaClient extends Nesto\Prisma\Client\PrismaClient — typed model
 *                               properties ($prisma->user is a UserDelegate), the schema compiled in
 *   PrismaService.php           the Nest recipe: PrismaClient + OnModuleInit (connect) / OnApplicationShutdown
 *                               (disconnect), injectable through PrismaModule
 *   Delegates/UserDelegate.php  the model's queries with PHPStan / Psalm array shapes for every argument
 *                               (`UserWhereInput`, `UserSelect`, `UserCreateInput`, ...) and the record shape `User`
 *   Enums/Role.php              PHP backed enums (case value = the enum value name of the schema)
 *   dmmf.php                    the compiled schema (Dmmf\Document::toArray()), loaded once per process
 *   autoload.php                a PSR-4 loader for the namespace (when composer.json does not map it)
 *
 * Records stay plain arrays (like Prisma's plain objects: select / include / omit change their shape); the
 * array shapes give IDE and static-analysis help without a DTO per shape.
 */
final class ClientGenerator
{
    public const PROVIDER = 'nesto-client';
    public const DEFAULT_OUTPUT = '../generated/prisma';
    public const DEFAULT_NAMESPACE = 'Generated\\Prisma';

    private readonly DataModel $dataModel;

    public function __construct(
        private readonly Document $document,
        private readonly string $schemaPath,
        private readonly ?string $rootDir = null,
    ) {
        $this->dataModel = new DataModel($document);
    }

    /** The generator blocks this generator handles. @return list<Generator> */
    public function generators(): array
    {
        return array_values(array_filter($this->document->generators, fn (Generator $g) => in_array($g->provider->resolve(), [self::PROVIDER, 'prisma-client-php'], true)));
    }

    /** Absolute output directory of a generator block (relative paths are relative to the schema file). */
    public function outputDir(Generator $generator): string
    {
        $output = $generator->output?->resolve() ?? self::DEFAULT_OUTPUT;

        return self::normalizePath(str_starts_with($output, '/') ? $output : dirname($this->schemaPath) . '/' . $output);
    }

    /** The PHP namespace: the `namespace` option, else derived from composer.json PSR-4, else Generated\Prisma. */
    public function namespace(Generator $generator): string
    {
        $configured = $generator->config['namespace'] ?? null;
        if (is_string($configured) && $configured !== '') {
            $namespace = trim(str_replace(['/', '.'], '\\', $configured), '\\');
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $namespace) !== 1) {
                throw new PrismaException(sprintf('The generator option `namespace = "%s"` is not a valid PHP namespace.', $configured));
            }

            return $namespace;
        }

        return $this->composerNamespace($this->outputDir($generator)) ?? self::DEFAULT_NAMESPACE;
    }

    /** Namespace of a directory according to composer.json `autoload.psr-4`, or null. */
    public function composerNamespace(string $dir): ?string
    {
        $root = $this->rootDir ?? dirname($this->schemaPath, 2);
        $composer = $root . '/composer.json';
        if (! is_file($composer)) {
            return null;
        }
        $json = json_decode((string) file_get_contents($composer), true);
        $map = is_array($json) ? (array) ($json['autoload']['psr-4'] ?? []) : [];
        $best = null;
        foreach ($map as $prefix => $paths) {
            foreach ((array) $paths as $path) {
                $base = self::normalizePath($root . '/' . rtrim((string) $path, '/'));
                if ($dir === $base || str_starts_with($dir, $base . '/')) {
                    $rest = trim(substr($dir, strlen($base)), '/');
                    $namespace = trim((string) $prefix, '\\') . ($rest !== '' ? '\\' . str_replace('/', '\\', $rest) : '');
                    if ($best === null || strlen($base) > $best[0]) {
                        $best = [strlen($base), $namespace];
                    }
                }
            }
        }
        if ($best === null || preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $best[1]) !== 1) {
            return null;
        }

        return $best[1];
    }

    /**
     * Every file of the client, relative path => contents.
     *
     * @return array<string, string>
     */
    public function files(string $namespace, string $outputDir): array
    {
        $files = [
            'PrismaClient.php' => $this->clientFile($namespace, $outputDir),
            'PrismaService.php' => $this->serviceFile($namespace),
            'dmmf.php' => "<?php\n\n// Generated by Nesto Prisma from " . basename($this->schemaPath) . " — do not edit.\n\nreturn " . self::export($this->document->toArray()) . ";\n",
            'autoload.php' => $this->autoloadFile($namespace),
        ];
        foreach ($this->document->enums() as $enum) {
            $files['Enums/' . self::className($enum->name) . '.php'] = $this->enumFile($namespace, $enum->name);
        }
        foreach ($this->dataModel->models() as $model) {
            $files['Delegates/' . self::className($model->name) . 'Delegate.php'] = $this->delegateFile($namespace, $model);
        }
        ksort($files);

        return $files;
    }

    /**
     * Write the client of every `nesto-client` generator block; stale files of a previous generation are
     * removed. Returns [output dir => namespace].
     *
     * @return array<string, string>
     */
    public function generate(): array
    {
        $written = [];
        foreach ($this->generators() as $generator) {
            $dir = $this->outputDir($generator);
            $namespace = $this->namespace($generator);
            $this->write($dir, $this->files($namespace, $dir));
            $written[$dir] = $namespace;
        }

        return $written;
    }

    /** @param array<string, string> $files */
    public function write(string $dir, array $files): void
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new PrismaException("Cannot create the output directory $dir.");
        }
        // stale files from an earlier generation (a removed model or enum)
        foreach (['Delegates', 'Enums'] as $sub) {
            foreach (glob($dir . '/' . $sub . '/*.php') ?: [] as $existing) {
                if (! isset($files[$sub . '/' . basename($existing)]) && str_contains((string) file_get_contents($existing), '// Generated by Nesto Prisma')) {
                    unlink($existing);
                }
            }
        }
        foreach ($files as $path => $contents) {
            $file = $dir . '/' . $path;
            if (! is_dir(dirname($file))) {
                mkdir(dirname($file), 0777, true);
            }
            if (is_file($file) && file_get_contents($file) === $contents) {
                continue; // keep mtimes (opcache) when nothing changed
            }
            $temp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
            file_put_contents($temp, $contents);
            rename($temp, $file);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($file, true);
            }
        }
    }

    // ---- files -----------------------------------------------------------------------------------

    private function header(): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\n// Generated by Nesto Prisma from " . basename($this->schemaPath) . " — do not edit (php nesto prisma:generate).\n\n";
    }

    private function clientFile(string $namespace, string $outputDir): string
    {
        $models = $this->dataModel->models();
        $properties = '';
        $boot = '';
        $match = '';
        $propertyDocs = '';
        foreach ($models as $model) {
            $class = self::className($model->name) . 'Delegate';
            $property = $model->delegateName();
            $properties .= "    /** Queries of model {$model->name}. */\n    public readonly Delegates\\$class \$$property;\n";
            $boot .= "        \$this->$property = new Delegates\\$class(\$this, " . self::str($model->name) . ");\n";
            $match .= '            ' . self::str($model->name) . " => \$this->$property,\n";
            $propertyDocs .= " *   \$prisma->$property\n";
        }
        $enums = '';
        foreach ($this->document->enums() as $enum) {
            $enums .= '            ' . self::str($enum->name) . ' => Enums\\' . self::className($enum->name) . "::class,\n";
        }
        $schemaDir = self::relativePath($outputDir, dirname(self::normalizePath($this->schemaPath)));

        return $this->header() . "namespace $namespace;\n\n"
            . "use Nesto\\Prisma\\Client\\ModelDelegate;\nuse Nesto\\Prisma\\Dmmf\\Document;\n\n"
            . "/**\n * Prisma Client of this schema:\n *\n" . $propertyDocs . " *\n"
            . " *   \$prisma = new PrismaClient();                                   // DATABASE_URL from the environment / .env\n"
            . " *   \$prisma = new PrismaClient(['datasourceUrl' => 'postgresql://...', 'log' => ['query']]);\n */\n"
            . "class PrismaClient extends \\Nesto\\Prisma\\Client\\PrismaClient\n{\n"
            . $properties
            . "\n    private static ?Document \$document = null;\n\n"
            . "    protected function bootDelegates(): void\n    {\n" . $boot . "    }\n\n"
            . "    protected function createDelegate(string \$model): ModelDelegate\n    {\n        return match (\$model) {\n" . $match . "            default => parent::createDelegate(\$model),\n        };\n    }\n\n"
            . "    protected static function embeddedDocument(): Document\n    {\n        return self::\$document ??= Document::fromArray(require __DIR__ . '/dmmf.php');\n    }\n\n"
            . "    /** @return array<string, class-string<\\BackedEnum>> */\n    protected static function enumClasses(): array\n    {\n        return [\n" . $enums . "        ];\n    }\n\n"
            . "    protected static function generatedSchemaDir(): ?string\n    {\n        return __DIR__ . " . self::str('/' . $schemaDir) . ";\n    }\n}\n";
    }

    private function serviceFile(string $namespace): string
    {
        return $this->header() . "namespace $namespace;\n\n"
            . "use Nesto\\Common\\Attributes\\Injectable;\nuse Nesto\\Common\\Contracts\\OnApplicationShutdown;\nuse Nesto\\Common\\Contracts\\OnModuleInit;\n\n"
            . "/**\n * The client as a Nesto provider (NestJS' Prisma recipe): connects when the modules initialize, disconnects\n"
            . " * on shutdown. Register it with `PrismaModule::forRoot(['service' => PrismaService::class])` (picked\n"
            . " * automatically when it exists) and inject it anywhere: `public function __construct(private PrismaService \$prisma) {}`.\n */\n"
            . "#[Injectable]\nclass PrismaService extends PrismaClient implements OnModuleInit, OnApplicationShutdown\n{\n"
            . "    public function onModuleInit(): void\n    {\n        \$this->connect();\n    }\n\n"
            . "    public function onApplicationShutdown(?string \$signal = null): void\n    {\n        \$this->disconnect();\n    }\n}\n";
    }

    private function autoloadFile(string $namespace): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\n// Generated by Nesto Prisma — PSR-4 loader for $namespace (not needed when composer.json maps the namespace).\n\n"
            . "spl_autoload_register(static function (string \$class): void {\n"
            . '    $prefix = ' . self::str($namespace . '\\') . ";\n"
            . "    if (str_starts_with(\$class, \$prefix)) {\n"
            . "        \$file = __DIR__ . '/' . str_replace('\\\\', '/', substr(\$class, strlen(\$prefix))) . '.php';\n"
            . "        if (is_file(\$file)) {\n            require \$file;\n        }\n    }\n});\n";
    }

    private function enumFile(string $namespace, string $name): string
    {
        $enum = $this->document->enum($name);
        $cases = '';
        foreach ($enum?->values ?? [] as $value) {
            $doc = $value->dbName !== null ? '    /** Stored as ' . self::str($value->dbName) . ". */\n" : '';
            $cases .= $doc . '    case ' . self::caseName($value->name) . ' = ' . self::str($value->name) . ";\n";
        }
        $doc = $enum?->documentation !== null ? "/**\n * " . str_replace("\n", "\n * ", $enum->documentation) . "\n */\n" : "/** Enum {$name} of the schema. */\n";

        return $this->header() . "namespace $namespace\\Enums;\n\n" . $doc . 'enum ' . self::className($name) . ": string\n{\n" . $cases . "}\n";
    }

    private function delegateFile(string $namespace, ModelInfo $model): string
    {
        $name = $model->name;
        $types = $this->typeAliases($namespace, $model);
        $aliases = '';
        foreach ($types as $alias => $type) {
            $aliases .= " * @phpstan-type $alias $type\n";
        }
        $methods = [
            ['findUnique', "{$name}FindUniqueArgs", "{$name}|null", '?array', 'array $args'],
            ['findUniqueOrThrow', "{$name}FindUniqueArgs", $name, 'array', 'array $args'],
            ['findFirst', "{$name}FindManyArgs", "{$name}|null", '?array', 'array $args = []'],
            ['findFirstOrThrow', "{$name}FindManyArgs", $name, 'array', 'array $args = []'],
            ['findMany', "{$name}FindManyArgs", "list<{$name}>", 'array', 'array $args = []'],
            ['create', "{$name}CreateArgs", $name, 'array', 'array $args'],
            ['createMany', "{$name}CreateManyArgs", 'array{count: int}', 'array', 'array $args'],
            ['createManyAndReturn', "{$name}CreateManyAndReturnArgs", "list<{$name}>", 'array', 'array $args'],
            ['update', "{$name}UpdateArgs", $name, 'array', 'array $args'],
            ['updateMany', "{$name}UpdateManyArgs", 'array{count: int}', 'array', 'array $args'],
            ['updateManyAndReturn', "{$name}UpdateManyAndReturnArgs", "list<{$name}>", 'array', 'array $args'],
            ['upsert', "{$name}UpsertArgs", $name, 'array', 'array $args'],
            ['delete', "{$name}DeleteArgs", $name, 'array', 'array $args'],
            ['deleteMany', "{$name}DeleteManyArgs", 'array{count: int}', 'array', 'array $args = []'],
            ['count', "{$name}CountArgs", 'int|array<string, int>', 'int|array', 'array $args = []'],
            ['aggregate', "{$name}AggregateArgs", 'array<string, mixed>', 'array', 'array $args = []'],
            ['groupBy', "{$name}GroupByArgs", 'list<array<string, mixed>>', 'array', 'array $args'],
        ];
        if ($model->model->isView) {
            $methods = array_values(array_filter($methods, fn ($m) => in_array($m[0], ['findUnique', 'findUniqueOrThrow', 'findFirst', 'findFirstOrThrow', 'findMany', 'count', 'aggregate', 'groupBy'], true)));
        }
        $body = '';
        foreach ($methods as [$method, $argsType, $returnDoc, $returnType, $parameter]) {
            $body .= "\n    /**\n     * @param $argsType \$args\n     * @return $returnDoc\n     */\n"
                . "    public function $method($parameter): $returnType\n    {\n        return parent::$method(\$args);\n    }\n";
        }
        $doc = $model->model->documentation !== null ? ' * ' . str_replace("\n", "\n * ", $model->model->documentation) . "\n *\n" : '';

        return $this->header() . "namespace $namespace\\Delegates;\n\nuse Nesto\\Prisma\\Client\\ModelDelegate;\n\n"
            . "/**\n * `\$prisma->" . $model->delegateName() . "` — queries of " . ($model->model->isView ? 'view' : 'model') . " $name (table " . self::str($model->table) . ").\n *\n" . $doc
            . $aliases . " */\nfinal class " . self::className($name) . "Delegate extends ModelDelegate\n{" . $body . "}\n";
    }

    // ---- PHPStan types ---------------------------------------------------------------------------

    /** @return array<string, string> alias => type */
    private function typeAliases(string $namespace, ModelInfo $model): array
    {
        $name = $model->name;
        $record = [];
        $where = [];
        $unique = [];
        $select = [];
        $include = [];
        $omit = [];
        $orderBy = [];
        $create = [];
        $update = [];
        $updateMany = [];
        $scalarNames = [];
        foreach ($model->scalars as $field) {
            $output = $this->outputType($namespace, $field);
            $record[] = self::key($field->name) . ': ' . $output;
            $where[] = self::key($field->name) . '?: ' . $this->filterType($namespace, $field);
            $select[] = self::key($field->name) . '?: bool';
            $omit[] = self::key($field->name) . '?: bool';
            $orderBy[] = self::key($field->name) . "?: 'asc'|'desc'|array{sort: 'asc'|'desc', nulls?: 'first'|'last'}";
            $input = $this->inputType($namespace, $field);
            $optional = ! $field->isRequired || $field->hasDefaultValue || $field->isUpdatedAt || $field->isList || $this->isForeignKey($model, $field->name);
            $create[] = self::key($field->name) . ($optional ? '?: ' : ': ') . $input . ($field->isList ? '|array{set: ' . $input . '}' : '');
            $updateType = $input . '|array{set?: ' . $input . '}' . (in_array($field->type, ['Int', 'BigInt', 'Float', 'Decimal'], true) && ! $field->isList ? "|array{increment?: $input, decrement?: $input, multiply?: $input, divide?: $input}" : '') . ($field->isList ? '|array{push?: ' . $this->elementInput($namespace, $field) . '|' . $input . '}' : '');
            $update[] = self::key($field->name) . '?: ' . $updateType;
            $updateMany[] = self::key($field->name) . '?: ' . $updateType;
            $scalarNames[] = self::str($field->name);
        }
        foreach ($model->compoundKeys as $key => $fields) {
            $parts = array_map(fn (string $f) => self::key($f) . ': ' . $this->elementInput($namespace, $model->scalars[$f]), $fields);
            $unique[] = self::key($key) . '?: array{' . implode(', ', $parts) . '}';
        }
        foreach ($model->relations as $relationName => $relation) {
            $target = $relation->target;
            $record[] = self::key($relationName) . '?: ' . ($relation->isList() ? 'list<array<string, mixed>>' : 'array<string, mixed>|null');
            $where[] = self::key($relationName) . '?: ' . ($relation->isList()
                ? 'array{some?: array<string, mixed>, every?: array<string, mixed>, none?: array<string, mixed>}'
                : 'array<string, mixed>|null');
            $nested = 'bool|array{select?: array<string, mixed>, include?: array<string, mixed>, omit?: array<string, bool>' . ($relation->isList() ? ', where?: array<string, mixed>, orderBy?: array<mixed>, take?: int, skip?: int, cursor?: array<string, mixed>, distinct?: string|list<string>' : '') . '}';
            $select[] = self::key($relationName) . '?: ' . $nested;
            $include[] = self::key($relationName) . '?: ' . $nested;
            $orderBy[] = self::key($relationName) . '?: ' . ($relation->isList() ? "array{_count?: 'asc'|'desc'}" : 'array<string, mixed>');
            $ops = $relation->isList()
                ? 'create?: array<mixed>, createMany?: array{data: list<array<string, mixed>>, skipDuplicates?: bool}, connect?: array<mixed>, connectOrCreate?: array<mixed>'
                : 'create?: array<string, mixed>, connect?: array<string, mixed>, connectOrCreate?: array{where: array<string, mixed>, create: array<string, mixed>}';
            if ($relation->kind === RelationInfo::M2M) {
                $ops = 'create?: array<mixed>, connect?: array<mixed>, connectOrCreate?: array<mixed>';
            }
            $create[] = self::key($relationName) . '?: array{' . $ops . '}';
            $updateOps = $ops . ($relation->isList()
                ? ', set?: array<mixed>, disconnect?: array<mixed>, update?: array<mixed>, updateMany?: array<mixed>, upsert?: array<mixed>, delete?: array<mixed>, deleteMany?: array<mixed>'
                : ', disconnect?: bool|array<string, mixed>, update?: array<string, mixed>, upsert?: array{create: array<string, mixed>, update: array<string, mixed>, where?: array<string, mixed>}, delete?: bool|array<string, mixed>');
            $update[] = self::key($relationName) . '?: array{' . $updateOps . '}';
            unset($target);
        }
        $counts = array_keys(array_filter($model->relations, fn (RelationInfo $r) => $r->isList()));
        $countSelect = $counts === [] ? 'bool' : 'bool|array{select: array{' . implode(', ', array_map(fn ($c) => self::key($c) . '?: bool|array{where?: array<string, mixed>}', $counts)) . '}}';
        if ($counts !== []) {
            $record[] = '_count?: array{' . implode(', ', array_map(fn ($c) => self::key($c) . '?: int', $counts)) . '}';
            $select[] = '_count?: ' . $countSelect;
            $include[] = '_count?: ' . $countSelect;
        }
        $where[] = 'AND?: array<mixed>';
        $where[] = 'OR?: list<array<string, mixed>>';
        $where[] = 'NOT?: array<mixed>';
        $fieldEnum = $scalarNames === [] ? 'string' : implode('|', $scalarNames);
        $numeric = array_values(array_filter($model->scalars, fn (Field $f) => in_array($f->type, ['Int', 'BigInt', 'Float', 'Decimal'], true) && ! $f->isList));
        $numericSelect = $numeric === [] ? 'array<string, bool>' : 'array{' . implode(', ', array_map(fn (Field $f) => self::key($f->name) . '?: bool', $numeric)) . '}';
        $allSelect = 'array{' . implode(', ', ['_all?: bool', ...array_map(fn (Field $f) => self::key($f->name) . '?: bool', array_values($model->scalars))]) . '}';
        $select = $select === [] ? 'array<string, bool>' : 'array{' . implode(', ', $select) . '}';
        $include = $include === [] ? 'array<string, bool>' : 'array{' . implode(', ', $include) . '}';
        $common = "select?: {$name}Select, include?: {$name}Include, omit?: {$name}Omit";

        return [
            $name => 'array{' . implode(', ', $record) . '}',
            "{$name}ScalarField" => $fieldEnum,
            "{$name}WhereInput" => 'array{' . implode(', ', $where) . '}',
            // single @id / @unique fields are where keys already (a value or a filter); compound keys are added
            "{$name}WhereUniqueInput" => 'array{' . implode(', ', [...$unique, ...$where]) . '}',
            "{$name}Select" => $select,
            "{$name}Include" => $include,
            "{$name}Omit" => 'array{' . implode(', ', $omit) . '}',
            "{$name}OrderByInput" => 'array{' . implode(', ', $orderBy) . '}',
            "{$name}CreateInput" => 'array{' . implode(', ', $create) . '}',
            "{$name}UpdateInput" => 'array{' . implode(', ', $update) . '}',
            "{$name}UpdateManyInput" => 'array{' . implode(', ', $updateMany) . '}',
            "{$name}FindUniqueArgs" => "array{where: {$name}WhereUniqueInput, $common, relationLoadStrategy?: 'query'|'join'}",
            "{$name}FindManyArgs" => "array{where?: {$name}WhereInput, $common, orderBy?: {$name}OrderByInput|list<{$name}OrderByInput>, cursor?: {$name}WhereUniqueInput, take?: int, skip?: int, distinct?: {$name}ScalarField|list<{$name}ScalarField>, relationLoadStrategy?: 'query'|'join'}",
            "{$name}CreateArgs" => "array{data: {$name}CreateInput, $common}",
            "{$name}CreateManyArgs" => "array{data: {$name}CreateInput|list<{$name}CreateInput>, skipDuplicates?: bool}",
            "{$name}CreateManyAndReturnArgs" => "array{data: {$name}CreateInput|list<{$name}CreateInput>, skipDuplicates?: bool, $common}",
            "{$name}UpdateArgs" => "array{where: {$name}WhereUniqueInput, data: {$name}UpdateInput, $common}",
            "{$name}UpdateManyArgs" => "array{where?: {$name}WhereInput, data: {$name}UpdateManyInput, limit?: int}",
            "{$name}UpdateManyAndReturnArgs" => "array{where?: {$name}WhereInput, data: {$name}UpdateManyInput, limit?: int, $common}",
            "{$name}UpsertArgs" => "array{where: {$name}WhereUniqueInput, create: {$name}CreateInput, update: {$name}UpdateInput, $common}",
            "{$name}DeleteArgs" => "array{where: {$name}WhereUniqueInput, $common}",
            "{$name}DeleteManyArgs" => "array{where?: {$name}WhereInput, limit?: int}",
            "{$name}CountArgs" => "array{where?: {$name}WhereInput, orderBy?: {$name}OrderByInput|list<{$name}OrderByInput>, cursor?: {$name}WhereUniqueInput, take?: int, skip?: int, select?: $allSelect}",
            "{$name}AggregateArgs" => "array{where?: {$name}WhereInput, orderBy?: {$name}OrderByInput|list<{$name}OrderByInput>, cursor?: {$name}WhereUniqueInput, take?: int, skip?: int, _count?: true|$allSelect, _avg?: $numericSelect, _sum?: $numericSelect, _min?: {$name}Omit, _max?: {$name}Omit}",
            "{$name}GroupByArgs" => "array{by: {$name}ScalarField|list<{$name}ScalarField>, where?: {$name}WhereInput, having?: array<string, mixed>, orderBy?: array<mixed>, take?: int, skip?: int, _count?: true|$allSelect, _avg?: $numericSelect, _sum?: $numericSelect, _min?: {$name}Omit, _max?: {$name}Omit}",
        ];
    }

    private function isForeignKey(ModelInfo $model, string $field): bool
    {
        foreach ($model->relations as $relation) {
            if ($relation->kind === RelationInfo::OWNER && in_array($field, $relation->thisFields(), true)) {
                return true;
            }
        }

        return false;
    }

    private function scalarOutput(string $namespace, Field $field): string
    {
        if ($field->kind === Field::ENUM) {
            return '\\' . $namespace . '\\Enums\\' . self::className($field->type);
        }

        return match ($field->type) {
            'String', 'Bytes' => 'string',
            'Int' => 'int',
            'BigInt' => 'int|numeric-string',
            'Float' => 'float',
            'Decimal' => 'numeric-string',
            'Boolean' => 'bool',
            'DateTime' => '\\DateTimeImmutable',
            'Json' => 'mixed',
            default => 'mixed',
        };
    }

    private function outputType(string $namespace, Field $field): string
    {
        $type = $this->scalarOutput($namespace, $field);
        if ($field->isList) {
            return 'list<' . $type . '>';
        }

        return $field->isRequired || $type === 'mixed' ? $type : $type . '|null';
    }

    /** One value of the field as input (lists: one element). */
    private function elementInput(string $namespace, Field $field): string
    {
        if ($field->kind === Field::ENUM) {
            $enum = $this->document->enum($field->type);
            $names = array_map(fn ($v) => self::str($v->name), $enum?->values ?? []);

            return '\\' . $namespace . '\\Enums\\' . self::className($field->type) . ($names !== [] ? '|' . implode('|', $names) : '');
        }

        return match ($field->type) {
            'String', 'Bytes' => 'string',
            'Int' => 'int',
            'BigInt' => 'int|numeric-string',
            'Float' => 'float|int',
            'Decimal' => 'numeric-string|int|float',
            'Boolean' => 'bool',
            'DateTime' => '\\DateTimeInterface|string',
            default => 'mixed',
        };
    }

    private function inputType(string $namespace, Field $field): string
    {
        $type = $this->elementInput($namespace, $field);
        if ($field->isList) {
            return 'list<' . $type . '>';
        }
        if ($field->type === 'Json') {
            return 'mixed';
        }

        return $field->isRequired ? $type : $type . '|null';
    }

    private function filterType(string $namespace, Field $field): string
    {
        $type = $this->elementInput($namespace, $field);
        if ($field->isList) {
            return "list<$type>|array{equals?: list<$type>|null, has?: $type, hasEvery?: list<$type>, hasSome?: list<$type>, isEmpty?: bool}";
        }
        if ($field->type === 'Json') {
            return "array{equals?: mixed, not?: mixed, path?: string|list<string|int>, string_contains?: string, string_starts_with?: string, string_ends_with?: string, array_contains?: mixed, array_starts_with?: mixed, array_ends_with?: mixed, lt?: mixed, lte?: mixed, gt?: mixed, gte?: mixed, mode?: 'default'|'insensitive'}";
        }
        $ops = ["equals?: $type|null", "not?: $type|null|array<string, mixed>", "in?: list<$type>", "notIn?: list<$type>"];
        if (! in_array($field->type, ['Boolean', 'Bytes'], true) && $field->kind !== Field::ENUM) {
            array_push($ops, "lt?: $type", "lte?: $type", "gt?: $type", "gte?: $type");
        }
        if ($field->type === 'String' && $field->kind !== Field::ENUM) {
            array_push($ops, 'contains?: string', 'startsWith?: string', 'endsWith?: string', "mode?: 'default'|'insensitive'");
        }

        return "$type|null|array{" . implode(', ', $ops) . '}';
    }

    // ---- helpers ---------------------------------------------------------------------------------

    /** A PHP class name for a model / enum name (PSL names are identifiers already). */
    public static function className(string $name): string
    {
        $name = (string) preg_replace('/[^A-Za-z0-9_]/', '_', $name);
        $name = ucfirst($name);
        if (in_array(strtolower($name), ['list', 'array', 'string', 'int', 'float', 'bool', 'mixed', 'object', 'iterable', 'void', 'null', 'false', 'true', 'self', 'parent', 'static', 'never', 'callable', 'enum', 'match', 'readonly', 'fn', 'class', 'interface', 'trait', 'namespace', 'function', 'new', 'print', 'echo', 'include', 'require', 'use', 'abstract', 'final'], true)) {
            $name .= 'Model';
        }

        return $name;
    }

    private static function caseName(string $name): string
    {
        return strtolower($name) === 'class' ? $name . '_' : $name;
    }

    private static function key(string $name): string
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1 ? $name : self::str($name);
    }

    private static function str(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    public static function normalizePath(string $path): string
    {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return '/' . implode('/', $parts);
    }

    /** Relative path from directory $from to $to ("../../prisma"). */
    public static function relativePath(string $from, string $to): string
    {
        $from = explode('/', trim(self::normalizePath($from), '/'));
        $to = explode('/', trim(self::normalizePath($to), '/'));
        while ($from !== [] && $to !== [] && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }

        return implode('/', [...array_fill(0, count($from), '..'), ...$to]);
    }

    /** var_export with short arrays and stable formatting. */
    public static function export(mixed $value, int $indent = 0): string
    {
        if (is_array($value)) {
            if ($value === []) {
                return '[]';
            }
            $pad = str_repeat('    ', $indent + 1);
            $list = array_is_list($value);
            $lines = [];
            foreach ($value as $key => $item) {
                $lines[] = $pad . ($list ? '' : var_export($key, true) . ' => ') . self::export($item, $indent + 1) . ',';
            }

            return "[\n" . implode("\n", $lines) . "\n" . str_repeat('    ', $indent) . ']';
        }

        return $value === null ? 'null' : var_export($value, true);
    }
}
