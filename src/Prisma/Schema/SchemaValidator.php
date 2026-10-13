<?php

declare(strict_types=1);

namespace Nesto\Prisma\Schema;

use Nesto\Prisma\Dmmf\Datasource;
use Nesto\Prisma\Dmmf\Document;
use Nesto\Prisma\Dmmf\Enum;
use Nesto\Prisma\Dmmf\EnumValue;
use Nesto\Prisma\Dmmf\EnvValue;
use Nesto\Prisma\Dmmf\Field;
use Nesto\Prisma\Dmmf\FieldDefault;
use Nesto\Prisma\Dmmf\Generator;
use Nesto\Prisma\Dmmf\Index;
use Nesto\Prisma\Dmmf\IndexField;
use Nesto\Prisma\Dmmf\ManyToMany;
use Nesto\Prisma\Dmmf\Model;
use Nesto\Prisma\Schema\Ast\ArrayValue;
use Nesto\Prisma\Schema\Ast\Attribute;
use Nesto\Prisma\Schema\Ast\Block;
use Nesto\Prisma\Schema\Ast\ConstantValue;
use Nesto\Prisma\Schema\Ast\Expression;
use Nesto\Prisma\Schema\Ast\Field as AstField;
use Nesto\Prisma\Schema\Ast\FunctionCall;
use Nesto\Prisma\Schema\Ast\NumericValue;
use Nesto\Prisma\Schema\Ast\Property;
use Nesto\Prisma\Schema\Ast\SchemaAst;
use Nesto\Prisma\Schema\Ast\StringValue;

/**
 * `prisma validate`: checks a parsed schema the way Prisma's psl does and builds the DMMF Document.
 *
 * Datasource / generator blocks, names (duplicates, reserved), field types, attributes and their arguments,
 * defaults per type, native types per provider, scalar lists, enums (not on SQLite), ids and unique criteria,
 * indexes and constraint name collisions, and relations: every relation field needs its opposite field,
 * ambiguous relations need names, `fields` / `references` have the same arity and matching types,
 * `references` point at a unique criterion, one-to-one relations use unique fields, referential actions.
 *
 *   $document = (new SchemaValidator)->validate($ast);   // throws SchemaException with every error
 */
final class SchemaValidator
{
    private const PROVIDERS = ['postgresql' => 'postgresql', 'postgres' => 'postgresql', 'mysql' => 'mysql', 'mariadb' => 'mysql', 'sqlite' => 'sqlite'];
    private const UNSUPPORTED_PROVIDERS = ['sqlserver', 'mongodb', 'cockroachdb'];
    private const REFERENTIAL_ACTIONS = ['Cascade', 'Restrict', 'NoAction', 'SetNull', 'SetDefault'];
    private const PREVIEW_FEATURES = [
        'views', 'relationJoins', 'fullTextSearchPostgres', 'fullTextSearch', 'fullTextIndex', 'multiSchema', 'postgresqlExtensions',
        'driverAdapters', 'metrics', 'tracing', 'omitApi', 'prismaSchemaFolder', 'typedSql', 'strictUndefinedChecks', 'nativeDistinct',
        'deno', 'interactiveTransactions', 'extendedWhereUnique', 'fieldReference', 'clientExtensions', 'filteredRelationCount',
        'orderByNulls', 'jsonProtocol', 'improvedQueryRaw', 'filterJson', 'fullTextSearchPostgres', 'postgresqlExtensions', 'queryCompiler',
    ];
    /** Preview features that became generally available: Prisma warns that they are no longer needed. */
    private const DEPRECATED_PREVIEW_FEATURES = [
        'interactiveTransactions', 'extendedWhereUnique', 'fieldReference', 'clientExtensions', 'filteredRelationCount',
        'orderByNulls', 'jsonProtocol', 'improvedQueryRaw', 'filterJson', 'omitApi', 'tracing', 'referentialIntegrity',
        'namedConstraints', 'selectRelationCount', 'orderByRelation', 'orderByAggregateGroup', 'napi', 'prismaSchemaFolder',
    ];
    private const RESERVED_MODEL_NAMES = ['PrismaClient', 'Prisma'];

    /** @var list<Diagnostic> */
    private array $diagnostics = [];
    private ?string $provider = null;
    private string $datasourceName = 'db';
    private string $relationMode = 'foreignKeys';
    /** @var list<string> */
    private array $previewFeatures = [];
    /** @var array<string, Block> top-level name => model / view / enum / type block */
    private array $names = [];
    /** @var array<string, Enum> */
    private array $enums = [];
    /** @var array<string, array<string, mixed>> model name => working info */
    private array $models = [];

    /** @return list<Diagnostic> every error and warning of the last validate() */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    /**
     * Validate and build the Document. Throws SchemaException when there are errors (with $previous
     * diagnostics, e.g. the parser's, included).
     *
     * @param list<Diagnostic> $previous
     */
    public function validate(SchemaAst $ast, string $source = '', string $file = 'schema.prisma', array $previous = []): Document
    {
        $document = $this->build($ast, $previous);
        if ($document === null) {
            throw new SchemaException($this->diagnostics, $source, $file);
        }

        return $document;
    }

    /** Validate and build; null when there are errors (see diagnostics()). @param list<Diagnostic> $previous */
    public function build(SchemaAst $ast, array $previous = []): ?Document
    {
        $this->diagnostics = $previous;
        $this->enums = $this->models = $this->names = [];
        $this->provider = null;
        $this->relationMode = 'foreignKeys';
        $this->previewFeatures = [];

        $generators = $this->generators($ast);
        $datasource = $this->datasource($ast);
        $this->registerNames($ast);
        foreach ($ast->blocks(Block::ENUM) as $block) {
            if (($this->names[$block->name] ?? null) === $block) {
                $this->enum($block);
            }
        }
        foreach ($ast->blocks(Block::TYPE) as $block) {
            $this->error(sprintf('Composite types are not supported on %s.', $this->providerLabel()), $block->nameSpan);
        }
        foreach ($ast->models() as $block) {
            if (($this->names[$block->name] ?? null) !== $block) {
                continue;
            }
            if ($block->kind === Block::VIEW && ! in_array('views', $this->previewFeatures, true)) {
                $this->error('View definitions are only available with the `views` preview feature.', $block->nameSpan);
            }
            $this->modelFields($block);
        }
        foreach (array_keys($this->models) as $name) {
            $this->modelAttributes($name);
        }
        $manyToMany = $this->relations();
        foreach (array_keys($this->models) as $name) {
            $this->uniqueCriteria($name);
        }
        $indexes = $this->indexes();
        $this->databaseNames($indexes);

        // in source order (stable for the same position)
        $order = array_keys($this->diagnostics);
        usort($order, function (int $a, int $b): int {
            $sa = $this->diagnostics[$a]->span;
            $sb = $this->diagnostics[$b]->span;

            return [$sa?->line ?? 0, $sa?->column ?? 0, $a] <=> [$sb?->line ?? 0, $sb?->column ?? 0, $b];
        });
        $this->diagnostics = array_map(fn (int $i) => $this->diagnostics[$i], $order);
        if (array_filter($this->diagnostics, fn (Diagnostic $d) => $d->isError()) !== []) {
            return null;
        }

        return new Document(
            $datasource,
            $generators,
            array_map(fn (string $name) => $this->buildModel($name), array_keys($this->models)),
            array_values($this->enums),
            $indexes,
            $manyToMany,
            array_values(array_map(fn (Diagnostic $d) => $d->message, array_filter($this->diagnostics, fn (Diagnostic $d) => ! $d->isError()))),
        );
    }

    // ---- datasource & generators ------------------------------------------------------------------

    private function datasource(SchemaAst $ast): ?Datasource
    {
        $blocks = $ast->blocks(Block::DATASOURCE);
        if ($blocks === []) {
            return null;
        }
        foreach (array_slice($blocks, 1) as $extra) {
            $this->error('You defined more than one datasource. This is not allowed yet because support for multiple databases has not been implemented yet.', $extra->nameSpan);
        }
        $block = $blocks[0];
        $this->datasourceName = $block->name;
        $values = $this->properties($block, ['provider', 'url', 'directUrl', 'shadowDatabaseUrl', 'relationMode', 'referentialIntegrity', 'extensions', 'schemas']);
        $providerProperty = $block->property('provider');
        $activeProvider = '';
        if ($providerProperty === null) {
            $this->error(sprintf('Argument "provider" is missing in data source block "%s".', $block->name), $block->nameSpan);
        } elseif (! $providerProperty->value instanceof StringValue) {
            $this->error('The datasource provider must be a string literal ("postgresql", "mysql" or "sqlite").', $providerProperty->value->span);
        } else {
            $activeProvider = $providerProperty->value->value;
            if (isset(self::PROVIDERS[$activeProvider])) {
                $this->provider = self::PROVIDERS[$activeProvider];
                if ($activeProvider === 'mariadb') {
                    $this->diagnostics[] = Diagnostic::warning('Nesto: provider "mariadb" runs on the "mysql" provider (Prisma has no separate MariaDB provider); migrations are written for MySQL/MariaDB.', $providerProperty->value->span);
                }
            } elseif (in_array($activeProvider, self::UNSUPPORTED_PROVIDERS, true)) {
                $this->error(sprintf('Nesto does not support the "%s" provider yet. Use "postgresql", "mysql" (MySQL / MariaDB) or "sqlite".', $activeProvider), $providerProperty->value->span);
            } else {
                $this->error(sprintf('Datasource provider not known: "%s".', $activeProvider), $providerProperty->value->span);
            }
        }
        $url = $this->envValue($block, 'url', $values);
        if ($url === null) {
            $this->error(sprintf('Argument "url" is missing in data source block "%s".', $block->name), $block->nameSpan);
            $url = new EnvValue(null);
        } elseif ($url->fromEnvVar === null && $url->value !== null && $this->provider !== null) {
            $this->checkUrl($url->value, $block->property('url')->value->span);
        }
        $mode = $block->property('relationMode') ?? $block->property('referentialIntegrity');
        if ($mode !== null) {
            if (! $mode->value instanceof StringValue || ! in_array($mode->value->value, ['foreignKeys', 'prisma'], true)) {
                $this->error('Invalid relation mode setting: "' . ($mode->value instanceof StringValue ? $mode->value->value : $mode->value->toSource()) . '". Supported values: "foreignKeys", "prisma"', $mode->value->span);
            } else {
                $this->relationMode = $mode->value->value;
            }
        }
        $schemas = [];
        if (($property = $block->property('schemas')) !== null) {
            $this->error('Nesto: the multiSchema feature (`schemas` / @@schema) is not supported yet.', $property->keySpan);
        }
        $extensions = [];
        if (($property = $block->property('extensions')) !== null) {
            $extensions = $this->extensions($property, $activeProvider);
        }

        return new Datasource(
            $block->name,
            $this->provider ?? 'postgresql',
            $activeProvider,
            $url,
            $this->envValue($block, 'directUrl', $values),
            $this->envValue($block, 'shadowDatabaseUrl', $values),
            $this->relationMode,
            $schemas,
            $extensions,
        );
    }

    /**
     * `extensions = [citext, pg_trgm(schema: "public"), uuidOssp(map: "uuid-ossp", version: "1.1")]`
     * (PostgreSQL, `postgresqlExtensions` preview feature).
     *
     * @return list<array{name: string, dbName: string, schema: ?string, version: ?string}>
     */
    private function extensions(Property $property, string $activeProvider): array
    {
        if ($this->provider !== null && $this->provider !== 'postgresql') {
            $this->error(sprintf('The `extensions` property is only supported by the postgresql provider (this datasource uses "%s").', $activeProvider), $property->keySpan);

            return [];
        }
        if (! in_array('postgresqlExtensions', $this->previewFeatures, true)) {
            $this->error('The `extensions` property is only available with the `postgresqlExtensions` preview feature.', $property->keySpan);

            return [];
        }
        if (! $property->value instanceof ArrayValue) {
            $this->error('Expected an array of extensions: `extensions = [citext, pg_trgm(schema: "public")]`.', $property->value->span);

            return [];
        }
        $extensions = [];
        $seen = [];
        foreach ($property->value->items as $item) {
            if ($item instanceof ConstantValue) {
                $name = $item->value;
                $arguments = [];
            } elseif ($item instanceof FunctionCall) {
                $name = $item->name;
                $arguments = $item->arguments->arguments;
            } else {
                $this->error('Expected an array of extensions: `extensions = [citext, pg_trgm(schema: "public")]`.', $item->span);
                continue;
            }
            $extension = ['name' => $name, 'dbName' => $name, 'schema' => null, 'version' => null];
            foreach ($arguments as $argument) {
                if (! in_array($argument->name, ['map', 'schema', 'version'], true)) {
                    $this->error(sprintf('Unknown argument `%s` of the extension `%s` (map, schema, version).', $argument->name ?? $argument->value->toSource(), $name), $argument->span);
                    continue;
                }
                if (! $argument->value instanceof StringValue) {
                    $this->error(sprintf('Expected a string value for `%s` of the extension `%s`.', $argument->name, $name), $argument->value->span);
                    continue;
                }
                $extension[$argument->name === 'map' ? 'dbName' : $argument->name] = $argument->value->value;
            }
            if (isset($seen[$extension['dbName']])) {
                $this->error(sprintf('The extension `%s` is defined more than once.', $extension['dbName']), $item->span);
                continue;
            }
            $seen[$extension['dbName']] = true;
            $extensions[] = $extension;
        }

        return $extensions;
    }

    private function checkUrl(string $url, Span $span): void
    {
        $ok = match ($this->provider) {
            'postgresql' => preg_match('#^postgres(ql)?://#i', $url) === 1,
            'mysql' => preg_match('#^(mysql|mariadb)://#i', $url) === 1,
            'sqlite' => str_starts_with($url, 'file:'),
            default => true,
        };
        if (! $ok) {
            $this->error(sprintf('Error validating datasource `%s`: %s', $this->datasourceName, match ($this->provider) {
                'postgresql' => 'the URL must start with the protocol `postgresql://` or `postgres://`.',
                'mysql' => 'the URL must start with the protocol `mysql://`.',
                default => 'the URL must start with the protocol `file:`.',
            }), $span);
        }
    }

    /** @return list<Generator> */
    private function generators(SchemaAst $ast): array
    {
        $generators = [];
        $seen = [];
        foreach ($ast->blocks(Block::GENERATOR) as $block) {
            if (isset($seen[$block->name])) {
                $this->error(sprintf('The generator "%s" cannot be defined because a generator with that name already exists.', $block->name), $block->nameSpan);
                continue;
            }
            $seen[$block->name] = true;
            $values = $this->properties($block, null);
            $provider = $this->envValue($block, 'provider', $values);
            if ($provider === null) {
                $this->error(sprintf('Argument "provider" is missing in generator block "%s".', $block->name), $block->nameSpan);
                $provider = new EnvValue(null);
            }
            $preview = [];
            if (($property = $block->property('previewFeatures')) !== null) {
                if (! $property->value instanceof ArrayValue) {
                    $this->error('Expected an array of strings for "previewFeatures".', $property->value->span);
                } else {
                    foreach ($property->value->items as $item) {
                        if (! $item instanceof StringValue) {
                            $this->error('Expected a string value, but received ' . $item->kind() . ' value `' . $item->toSource() . '`.', $item->span);
                            continue;
                        }
                        if (in_array($item->value, self::DEPRECATED_PREVIEW_FEATURES, true)) {
                            $this->diagnostics[] = Diagnostic::warning(sprintf('Preview feature "%s" is deprecated. The functionality can be used without specifying it as a preview feature.', $item->value), $item->span);
                        } elseif (! in_array($item->value, self::PREVIEW_FEATURES, true)) {
                            $this->diagnostics[] = Diagnostic::warning(sprintf('The preview feature "%s" is not known.', $item->value), $item->span);
                        }
                        $preview[] = $item->value;
                    }
                }
            }
            $targets = [];
            if (($property = $block->property('binaryTargets')) !== null && $property->value instanceof ArrayValue) {
                foreach ($property->value->items as $item) {
                    if ($item instanceof StringValue) {
                        $targets[] = $item->value;
                    }
                }
            }
            $config = [];
            foreach ($block->properties() as $property) {
                if (in_array($property->key, ['provider', 'output', 'previewFeatures', 'binaryTargets'], true)) {
                    continue;
                }
                $config[$property->key] = match (true) {
                    $property->value instanceof StringValue => $property->value->value,
                    $property->value instanceof ArrayValue => array_map(fn (Expression $e) => $e instanceof StringValue ? $e->value : $e->toSource(), $property->value->items),
                    default => $property->value->toSource(),
                };
            }
            $this->previewFeatures = array_values(array_unique([...$this->previewFeatures, ...$preview]));
            $generators[] = new Generator($block->name, $provider, $this->envValue($block, 'output', $values), $config, $preview, $targets);
        }

        return $generators;
    }

    /**
     * Check a datasource / generator block's keys (duplicates, unknown keys when $known is given).
     *
     * @param list<string>|null $known
     * @return array<string, Expression>
     */
    private function properties(Block $block, ?array $known): array
    {
        $values = [];
        foreach ($block->properties() as $property) {
            if (isset($values[$property->key])) {
                $this->error(sprintf('Key "%s" is already defined in %s "%s".', $property->key, $block->kind, $block->name), $property->keySpan);
                continue;
            }
            if ($known !== null && ! in_array($property->key, $known, true)) {
                $this->error(sprintf('Property not known: "%s".', $property->key), $property->keySpan);
                continue;
            }
            $values[$property->key] = $property->value;
        }

        return $values;
    }

    /** "literal" or env("VAR"). @param array<string, Expression> $values */
    private function envValue(Block $block, string $key, array $values): ?EnvValue
    {
        $value = $values[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if ($value instanceof StringValue) {
            return new EnvValue($value->value);
        }
        if ($value instanceof FunctionCall && $value->name === 'env') {
            $argument = $value->arguments->get('name', 0)?->value;
            if ($argument instanceof StringValue && count($value->arguments->arguments) === 1) {
                return new EnvValue(null, $argument->value);
            }
            $this->error('The env() function takes one string argument: env("DATABASE_URL").', $value->span);

            return null;
        }
        $this->error(sprintf('Expected a string value or env("...") for "%s", but received %s value `%s`.', $key, $value->kind(), $value->toSource()), $value->span);

        return null;
    }

    // ---- names ------------------------------------------------------------------------------------

    private function registerNames(SchemaAst $ast): void
    {
        foreach ($ast->blocks() as $block) {
            if (! in_array($block->kind, [Block::MODEL, Block::VIEW, Block::ENUM, Block::TYPE], true)) {
                continue;
            }
            $label = self::kindLabel($block->kind);
            if (isset($this->names[$block->name])) {
                $this->error(sprintf('The %s "%s" cannot be defined because a %s with that name already exists.', $label, $block->name, self::kindLabel($this->names[$block->name]->kind)), $block->nameSpan);
                continue;
            }
            if (in_array($block->name, NativeTypes::SCALARS, true) || $block->name === 'Unsupported') {
                $this->error(sprintf('The %s "%s" cannot be defined because it is a reserved type name.', $label, $block->name), $block->nameSpan);
                continue;
            }
            if (($block->kind === Block::MODEL || $block->kind === Block::VIEW) && in_array($block->name, self::RESERVED_MODEL_NAMES, true)) {
                $this->error(sprintf('The %s name `%s` is invalid. It is a reserved name. Please change it. Read more at https://pris.ly/d/naming-models', $label, $block->name), $block->nameSpan);
                continue;
            }
            $this->names[$block->name] = $block;
        }
    }

    private static function kindLabel(string $kind): string
    {
        return match ($kind) {
            Block::TYPE => 'composite type',
            default => $kind,
        };
    }

    // ---- enums ------------------------------------------------------------------------------------

    private function enum(Block $block): void
    {
        if ($this->provider === 'sqlite') {
            $this->error(sprintf('You defined the enum `%s`. But the current connector does not support enums.', $block->name), $block->nameSpan);
        }
        $values = [];
        $seen = [];
        foreach ($block->values() as $value) {
            if (isset($seen[$value->name])) {
                $this->error(sprintf('Value "%s" is already defined on enum "%s".', $value->name, $block->name), $value->span);
                continue;
            }
            $seen[$value->name] = true;
            $dbName = null;
            foreach ($value->attributes as $attribute) {
                if ($attribute->name === 'map') {
                    $dbName = $this->stringArgument($attribute, 'name');
                } else {
                    $this->error(sprintf('Attribute not known: "@%s".', $attribute->name), $attribute->nameSpan);
                }
            }
            $values[] = new EnumValue($value->name, $dbName, $value->documentation);
        }
        if ($values === []) {
            $this->error('An enum must have at least one value.', $block->nameSpan);
        }
        $dbName = null;
        foreach ($block->attributes() as $attribute) {
            match ($attribute->name) {
                'map' => $dbName = $this->stringArgument($attribute, 'name'),
                'schema' => $this->error('Nesto: the multiSchema feature (`schemas` / @@schema) is not supported yet.', $attribute->nameSpan),
                default => $this->error(sprintf('Attribute not known: "@@%s".', $attribute->name), $attribute->nameSpan),
            };
        }
        $this->enums[$block->name] = new Enum($block->name, $values, $dbName, $block->documentation);
    }

    // ---- model fields -----------------------------------------------------------------------------

    private function modelFields(Block $block): void
    {
        $info = ['block' => $block, 'name' => $block->name, 'isView' => $block->kind === Block::VIEW, 'fields' => [], 'dbName' => null,
            'isIgnored' => false, 'primaryKey' => null, 'compoundUniques' => [], 'indexes' => [], 'singleId' => null];
        foreach ($block->fields() as $astField) {
            if (isset($info['fields'][$astField->name])) {
                $this->error(sprintf('Field "%s" is already defined on %s "%s".', $astField->name, $block->kind, $block->name), $astField->nameSpan);
                continue;
            }
            $field = $this->field($block, $astField);
            if ($field !== null) {
                $info['fields'][$astField->name] = $field;
            }
        }
        $this->models[$block->name] = $info;
    }

    /** @return array<string, mixed>|null */
    private function field(Block $model, AstField $ast): ?array
    {
        $type = $ast->type;
        $kind = Field::SCALAR;
        $typeName = $type->name;
        if ($type->unsupported !== null) {
            $kind = Field::UNSUPPORTED;
            $typeName = 'Unsupported';
        } elseif (in_array($type->name, NativeTypes::SCALARS, true)) {
            $kind = Field::SCALAR;
        } elseif (isset($this->names[$type->name])) {
            $target = $this->names[$type->name];
            $kind = match ($target->kind) {
                Block::ENUM => Field::ENUM,
                Block::MODEL, Block::VIEW => Field::OBJECT,
                default => 'composite',
            };
            if ($kind === 'composite') {
                $this->error(sprintf('Composite types are not supported on %s.', $this->providerLabel()), $type->span);

                return null;
            }
        } else {
            $this->error(sprintf('Type "%s" is neither a built-in type, nor refers to another model, composite type, or enum.', $type->name), $type->span);

            return null;
        }
        $field = [
            'ast' => $ast, 'name' => $ast->name, 'kind' => $kind, 'type' => $typeName, 'unsupported' => $type->unsupported,
            'isList' => $type->list, 'isRequired' => $type->list || ! $type->optional,
            'optional' => $type->optional, 'isUnique' => false, 'isId' => false, 'hasDefault' => false, 'default' => null,
            'dbName' => null, 'nativeType' => null, 'isUpdatedAt' => false, 'isIgnored' => false, 'isReadOnly' => false,
            'relation' => null, 'idArgs' => null, 'uniqueArgs' => null, 'defaultMap' => null,
        ];
        if ($type->list && ($kind === Field::SCALAR || $kind === Field::ENUM) && $this->provider !== null && $this->provider !== 'postgresql') {
            $this->error(sprintf('Field "%s" in model "%s" can\'t be a list. The current connector does not support lists of primitive types.', $ast->name, $model->name), $type->span);
        }
        if ($type->list && $kind === Field::UNSUPPORTED && $this->provider !== 'postgresql') {
            $this->error(sprintf('Field "%s" in model "%s" can\'t be a list. The current connector does not support lists of primitive types.', $ast->name, $model->name), $type->span);
        }

        $seen = [];
        foreach ($ast->attributes as $attribute) {
            $name = $attribute->name;
            $isNative = str_contains($name, '.');
            $key = $isNative ? '@db' : $name;
            if (isset($seen[$key])) {
                $this->error(sprintf('Attribute "@%s" can only be defined once.', $isNative ? 'db' : $name), $attribute->nameSpan);
                continue;
            }
            $seen[$key] = true;
            if ($isNative) {
                $field['nativeType'] = $this->nativeType($model, $field, $attribute);
                continue;
            }
            if ($kind === Field::OBJECT && ! in_array($name, ['relation', 'ignore'], true)) {
                if (in_array($name, ['id', 'unique', 'default', 'map', 'updatedAt'], true)) {
                    $this->error($this->attributeMessage($name, match ($name) {
                        'unique' => sprintf('The field `%s` is a relation field and cannot be marked with `@unique`. Only scalar fields can be made unique.', $ast->name),
                        'id' => sprintf('The field `%s` is a relation field and cannot be marked with `@id`. Only scalar fields can be declared as id.', $ast->name),
                        'default' => 'Cannot set a default value on a relation field.',
                        'map' => sprintf('The attribute `@map` cannot be used on relation fields.'),
                        default => 'Fields that are marked with @updatedAt must be of type DateTime.',
                    }), $attribute->span);
                    continue;
                }
            }
            switch ($name) {
                case 'id':
                    $this->checkArguments($attribute, ['map', 'length', 'sort', 'clustered']);
                    if ($type->optional || $type->list) {
                        $this->error($this->attributeMessage('id', 'Fields that are marked as id must be required.'), $attribute->span);
                    }
                    $field['isId'] = true;
                    $field['idArgs'] = $attribute;
                    break;
                case 'unique':
                    $this->checkArguments($attribute, ['map', 'length', 'sort', 'clustered']);
                    $field['isUnique'] = true;
                    $field['uniqueArgs'] = $attribute;
                    break;
                case 'default':
                    $this->checkArguments($attribute, ['value', 'map'], 1);
                    [$field['hasDefault'], $field['default']] = $this->defaultValue($model, $field, $attribute);
                    $mapArg = $attribute->arguments->get('map');
                    if ($mapArg !== null) {
                        $field['defaultMap'] = $mapArg->value instanceof StringValue ? $mapArg->value->value : null;
                    }
                    break;
                case 'relation':
                    if ($kind !== Field::OBJECT) {
                        $this->error($this->attributeMessage('relation', sprintf('Invalid field type, not a relation: the field `%s` is of type `%s`.', $ast->name, $typeName)), $attribute->span);
                        break;
                    }
                    $field['relation'] = $this->relationAttribute($attribute);
                    break;
                case 'map':
                    $field['dbName'] = $this->stringArgument($attribute, 'name');
                    break;
                case 'updatedAt':
                    if ($typeName !== 'DateTime' || $type->list) {
                        $this->error($this->attributeMessage('updatedAt', 'Fields that are marked with @updatedAt must be of type DateTime.'), $attribute->span);
                    }
                    $field['isUpdatedAt'] = true;
                    break;
                case 'ignore':
                    $field['isIgnored'] = true;
                    break;
                default:
                    $this->error(sprintf('Attribute not known: "@%s".', $name), $attribute->nameSpan);
            }
        }
        if ($kind === Field::OBJECT && $field['relation'] === null) {
            $field['relation'] = ['name' => null, 'fields' => null, 'references' => null, 'onDelete' => null, 'onUpdate' => null, 'map' => null, 'attribute' => null];
        }

        return $field;
    }

    /** @param array<string, mixed> $field @return array{0: string, 1: list<string>}|null */
    private function nativeType(Block $model, array $field, Attribute $attribute): ?array
    {
        [$prefix, $name] = explode('.', $attribute->name, 2) + [1 => ''];
        if ($prefix !== $this->datasourceName) {
            $this->error(sprintf('Attribute not known: "@%s".', $attribute->name), $attribute->nameSpan);

            return null;
        }
        if ($this->provider === null) {
            return null;
        }
        if ($this->provider === 'sqlite') {
            $this->error(sprintf('Native types are not supported with %s connector', $this->provider), $attribute->span);

            return null;
        }
        $definition = NativeTypes::definition($this->provider, $name);
        if ($definition === null) {
            $this->error(sprintf('Native type %s is not supported for %s connector.', $name, $this->provider), $attribute->span);

            return null;
        }
        [$scalars, $min, $max] = $definition;
        if ($field['kind'] !== Field::SCALAR || ! in_array($field['type'], $scalars, true)) {
            $this->error(sprintf('Native type %s is not compatible with declared field type %s, expected field type %s.', $name, $field['type'], implode(' or ', $scalars)), $attribute->span);

            return null;
        }
        $args = [];
        foreach ($attribute->arguments->arguments as $argument) {
            if ($argument->value instanceof NumericValue && $argument->value->isInteger()) {
                $args[] = $argument->value->value;
            } elseif ($argument->value instanceof ConstantValue && strtolower($argument->value->value) === 'max') {
                $args[] = 'MAX';
            } else {
                $this->error(sprintf('Expected a numeric value, but received %s value `%s`.', $argument->value->kind(), $argument->value->toSource()), $argument->span);
            }
        }
        if (count($args) < $min || count($args) > $max) {
            $this->error($max === $min
                ? sprintf('Native type %s takes %d arguments, but received %d.', $name, $min, count($args))
                : sprintf('Native type %s takes %d optional arguments, but received %d.', $name, $max, count($args)), $attribute->span);

            return null;
        }

        return [$name, $args];
    }

    /** @param array<string, mixed> $field @return array{0: bool, 1: mixed} [hasDefault, DMMF default] */
    private function defaultValue(Block $model, array $field, Attribute $attribute): array
    {
        $argument = $attribute->arguments->get('value', 0);
        if ($argument === null) {
            $this->error($this->attributeMessage('default', 'Argument "value" is missing.'), $attribute->span);

            return [false, null];
        }
        $value = $argument->value;
        $type = $field['type'];
        $fail = function (string $message) use ($attribute): array {
            $this->error($this->attributeMessage('default', $message), $attribute->span);

            return [false, null];
        };
        if ($value instanceof FunctionCall) {
            $args = [];
            foreach ($value->arguments->arguments as $arg) {
                $args[] = match (true) {
                    $arg->value instanceof StringValue => $arg->value->value,
                    $arg->value instanceof NumericValue => $arg->value->toNumber(),
                    default => $arg->value->toSource(),
                };
            }
            $allowed = match ($value->name) {
                'autoincrement' => in_array($type, ['Int', 'BigInt'], true) && $field['kind'] === Field::SCALAR,
                'now' => $type === 'DateTime',
                'uuid', 'cuid', 'nanoid', 'ulid' => $type === 'String',
                'dbgenerated' => true,
                'sequence' => false,
                default => null,
            };
            if ($allowed === null) {
                return $fail(sprintf('The function `%s` is not a known function. You can read about the available functions here: https://pris.ly/d/attribute-functions.', $value->name));
            }
            if ($value->name === 'sequence') {
                return $fail('The function `sequence()` is only available on CockroachDB.');
            }
            if (! $allowed) {
                return $fail(sprintf('The function `%s()` cannot be used on fields of type `%s`.', $value->name, $field['kind'] === Field::UNSUPPORTED ? 'Unsupported' : $type));
            }
            if ($field['kind'] === Field::UNSUPPORTED && $value->name !== 'dbgenerated') {
                return $fail('Only @default(dbgenerated("...")) can be used for Unsupported types.');
            }
            $argCheck = match ($value->name) {
                'autoincrement', 'now', 'ulid' => $args === [] ? null : sprintf('The function `%s()` takes no arguments.', $value->name),
                'uuid' => $args === [] || ($args === [4] || $args === [7]) ? null : 'The uuid() function takes an optional version argument: 4 or 7.',
                'cuid' => $args === [] || $args === [1] || $args === [2] ? null : 'The cuid() function takes an optional version argument: 1 or 2.',
                'nanoid' => $args === [] || (count($args) === 1 && is_int($args[0]) && $args[0] >= 2 && $args[0] <= 255) ? null : 'The nanoid() function takes an optional length argument between 2 and 255.',
                'dbgenerated' => $args === [] || (count($args) === 1 && is_string($args[0])) ? null : 'The dbgenerated() function takes an optional string argument: dbgenerated("SQL expression").',
                default => null,
            };
            if ($argCheck !== null) {
                return $fail($argCheck);
            }
            if ($value->name === 'dbgenerated' && $args === ['']) {
                return $fail('dbgenerated() takes either no argument, or a single nonempty string argument.');
            }

            return [true, new FieldDefault($value->name, $args)];
        }
        if ($field['kind'] === Field::UNSUPPORTED) {
            return $fail('Only @default(dbgenerated("...")) can be used for Unsupported types.');
        }
        if ($field['isList']) {
            if (! $value instanceof ArrayValue) {
                return $fail(sprintf('Expected an array value, but received %s value `%s`.', $value->kind(), $value->toSource()));
            }
            $list = [];
            foreach ($value->items as $item) {
                [$ok, $literal] = $this->literal($field, $item);
                if ($ok === false) {
                    return $fail($literal);
                }
                $list[] = $literal;
            }

            return [true, $list];
        }
        [$ok, $literal] = $this->literal($field, $value);
        if ($ok === false) {
            return $fail($literal);
        }

        return [true, $literal];
    }

    /** @param array<string, mixed> $field @return array{0: bool, 1: mixed} [ok, value or error message] */
    private function literal(array $field, Expression $value): array
    {
        $type = $field['type'];
        $received = sprintf('%s value `%s`', $value->kind(), $value->toSource());
        if ($field['kind'] === Field::ENUM) {
            $enum = $this->enums[$type] ?? null;
            if (! $value instanceof ConstantValue) {
                return [false, sprintf('Expected an enum value, but received %s.', $received)];
            }
            if ($enum !== null && $enum->value($value->value) === null) {
                return [false, sprintf('The defined default value `%s` is not a valid value of the enum specified for the field.', $value->value)];
            }

            return [true, $value->value];
        }

        return match ($type) {
            'String' => $value instanceof StringValue ? [true, $value->value] : [false, sprintf('Expected a String value, but received %s.', $received)],
            'Int', 'BigInt' => $value instanceof NumericValue && $value->isInteger()
                ? [true, $value->toNumber()]
                : [false, sprintf('Expected an %s value, but received %s.', $type, $received)],
            'Float', 'Decimal' => $value instanceof NumericValue
                ? [true, $value->toNumber()]
                : ($type === 'Decimal' && $value instanceof StringValue && is_numeric($value->value)
                    ? [true, $value->value]
                    : [false, sprintf('Expected a numeric value, but received %s.', $received)]),
            'Boolean' => $value instanceof ConstantValue && in_array($value->value, ['true', 'false'], true)
                ? [true, $value->value === 'true']
                : [false, sprintf('Expected a boolean value, but received %s.', $received)],
            'DateTime' => $value instanceof StringValue
                ? (self::isDateTime($value->value) ? [true, $value->value] : [false, sprintf('Expected a datetime value, but failed while parsing "%s": input contains invalid characters.', $value->value)])
                : [false, sprintf('Expected a datetime value, but received %s.', $received)],
            'Json' => $value instanceof StringValue
                ? (json_decode($value->value) !== null || trim($value->value) === 'null' ? [true, $value->value] : [false, sprintf('Expected a JSON value, but failed while parsing "%s".', $value->value)])
                : [false, sprintf('Expected a JSON value, but received %s.', $received)],
            'Bytes' => $value instanceof StringValue
                ? (base64_decode($value->value, true) !== false ? [true, $value->value] : [false, sprintf('Expected a base64 encoded value, but failed while parsing "%s".', $value->value)])
                : [false, sprintf('Expected a bytes value (base64 string), but received %s.', $received)],
            default => [false, 'Unexpected default value.'],
        };
    }

    private static function isDateTime(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?)?$/', $value) === 1;
    }

    /** @return array<string, mixed> */
    private function relationAttribute(Attribute $attribute): array
    {
        $this->checkArguments($attribute, ['name', 'fields', 'references', 'onDelete', 'onUpdate', 'map'], 1);
        $relation = ['name' => null, 'fields' => null, 'references' => null, 'onDelete' => null, 'onUpdate' => null, 'map' => null, 'attribute' => $attribute];
        $name = $attribute->arguments->get('name', 0);
        if ($name !== null) {
            if ($name->value instanceof StringValue) {
                $relation['name'] = $name->value->value;
            } else {
                $this->error($this->attributeMessage('relation', sprintf('Expected a string value, but received %s value `%s`.', $name->value->kind(), $name->value->toSource())), $name->span);
            }
        }
        foreach (['fields', 'references'] as $key) {
            $argument = $attribute->arguments->get($key);
            if ($argument === null) {
                continue;
            }
            $names = $this->fieldList($argument->value, 'relation');
            $relation[$key] = $names === null ? [] : array_map(fn (array $f) => $f['name'], $names);
        }
        foreach (['onDelete', 'onUpdate'] as $key) {
            $argument = $attribute->arguments->get($key);
            if ($argument === null) {
                continue;
            }
            if (! $argument->value instanceof ConstantValue || ! in_array($argument->value->value, self::REFERENTIAL_ACTIONS, true)) {
                $this->error($this->attributeMessage('relation', sprintf('Invalid referential action: `%s`', $argument->value->toSource())), $argument->span);
                continue;
            }
            if ($argument->value->value === 'SetDefault' && $this->relationMode === 'prisma') {
                $this->error($this->attributeMessage('relation', 'Invalid referential action: `SetDefault`. Allowed values: (`Cascade`, `Restrict`, `NoAction`, `SetNull`). `SetDefault` is invalid when using `relationMode = "prisma"`.'), $argument->span);
                continue;
            }
            $relation[$key] = $argument->value->value;
        }
        $map = $attribute->arguments->get('map');
        if ($map !== null) {
            $relation['map'] = $map->value instanceof StringValue ? $map->value->value : null;
        }

        return $relation;
    }

    // ---- model attributes (@@id, @@unique, @@index, @@map, @@ignore) -----------------------------------

    private function modelAttributes(string $name): void
    {
        $info = &$this->models[$name];
        /** @var Block $block */
        $block = $info['block'];
        $seen = [];
        foreach ($block->attributes() as $attribute) {
            if (in_array($attribute->name, ['id', 'map', 'ignore', 'schema'], true) && isset($seen[$attribute->name])) {
                $this->error(sprintf('Attribute "@@%s" can only be defined once.', $attribute->name), $attribute->nameSpan);
                continue;
            }
            $seen[$attribute->name] = true;
            switch ($attribute->name) {
                case 'map':
                    $info['dbName'] = $this->stringArgument($attribute, 'name');
                    break;
                case 'ignore':
                    $info['isIgnored'] = true;
                    break;
                case 'schema':
                    $this->error('Nesto: the multiSchema feature (`schemas` / @@schema) is not supported yet.', $attribute->nameSpan);
                    break;
                case 'id':
                case 'unique':
                case 'index':
                    $this->checkArguments($attribute, $attribute->name === 'index'
                        ? ['fields', 'map', 'name', 'type', 'clustered']
                        : ['fields', 'name', 'map', 'clustered', 'length', 'sort'], 1);
                    $fields = $this->blockFields($name, $attribute);
                    if ($fields === null) {
                        break;
                    }
                    $entry = ['attribute' => $attribute, 'fields' => $fields, 'name' => null, 'map' => null, 'algorithm' => null, 'clustered' => null];
                    $nameArg = $attribute->arguments->get('name');
                    if ($nameArg !== null) {
                        if (! $nameArg->value instanceof StringValue) {
                            $this->error($this->attributeMessage('@' . $attribute->name, 'Expected a string value for `name`.'), $nameArg->span);
                        } elseif ($attribute->name === 'index') {
                            $entry['map'] = $nameArg->value->value; // @@index(name: ...) is the old spelling of map
                        } else {
                            $entry['name'] = $nameArg->value->value;
                            if (isset($info['fields'][$entry['name']])) {
                                $this->error(sprintf('Error validating model "%s": The custom name `%s` specified for the `@@%s` attribute is already used as a name for a field. Please choose a different name.', $name, $entry['name'], $attribute->name), $nameArg->span);
                            }
                        }
                    }
                    $mapArg = $attribute->arguments->get('map');
                    if ($mapArg !== null) {
                        $entry['map'] = $mapArg->value instanceof StringValue ? $mapArg->value->value : null;
                    }
                    if (($typeArg = $attribute->arguments->get('type')) !== null) {
                        $algorithms = ['BTree', 'Hash', 'Gist', 'Gin', 'SpGist', 'Brin'];
                        if ($this->provider !== 'postgresql') {
                            $this->error($this->attributeMessage('@index', 'The `type` argument is only supported on PostgreSQL.'), $typeArg->span);
                        } elseif (! $typeArg->value instanceof ConstantValue || ! in_array($typeArg->value->value, $algorithms, true)) {
                            $this->error($this->attributeMessage('@index', sprintf('Unknown index type: %s. Supported: %s.', $typeArg->value->toSource(), implode(', ', $algorithms))), $typeArg->span);
                        } else {
                            $entry['algorithm'] = $typeArg->value->value;
                        }
                    }
                    if ($attribute->name === 'id') {
                        if ($info['primaryKey'] !== null) {
                            break;
                        }
                        $info['primaryKey'] = $entry;
                    } elseif ($attribute->name === 'unique') {
                        $info['compoundUniques'][] = $entry;
                    } else {
                        $info['indexes'][] = $entry;
                    }
                    break;
                case 'fulltext':
                    $this->error('Nesto: @@fulltext indexes are not supported yet.', $attribute->nameSpan);
                    break;
                default:
                    $this->error(sprintf('Attribute not known: "@@%s".', $attribute->name), $attribute->nameSpan);
            }
        }
        // field-level ids
        $ids = array_values(array_filter($info['fields'], fn (array $f) => $f['isId']));
        if (count($ids) > 1) {
            foreach ($ids as $field) {
                $this->error(sprintf('Error validating model "%s": At most one field must be marked as the id field with the `@id` attribute.', $name), $field['idArgs']->span);
            }
        }
        if ($ids !== [] && $info['primaryKey'] !== null) {
            $this->error(sprintf('Error validating model "%s": Each model must have at most one id criteria. You can\'t have `@id` and `@@id` at the same time.', $name), $block->nameSpan);
        }
        if (count($ids) === 1) {
            $info['singleId'] = $ids[0]['name'];
        }
        if ($info['primaryKey'] !== null) {
            $optional = array_filter($info['primaryKey']['fields'], fn (array $f) => ! ($info['fields'][$f['name']]['isRequired'] ?? true));
            if ($optional !== []) {
                $this->error(sprintf('Error validating model "%s": The id definition refers to the optional fields: %s. ID definitions must reference only required fields.', $name, implode(', ', array_map(fn ($f) => $f['name'], $optional))), $info['primaryKey']['attribute']->span);
            }
        }
        // autoincrement() rules of MySQL / SQLite
        foreach ($info['fields'] as $field) {
            if (! ($field['default'] instanceof FieldDefault) || $field['default']->name !== 'autoincrement') {
                continue;
            }
            $attribute = $field['ast']->attribute('default');
            if ($this->provider === 'sqlite' && ! ($field['isId'] && $field['type'] === 'Int')) {
                $this->error($this->attributeMessage('default', 'The `autoincrement()` default value is used on a non-id field even though the datasource does not support this.'), $attribute->span);
            }
            if ($this->provider === 'mysql' && ! $field['isId'] && ! $field['isUnique'] && ! $this->leadsAnIndex($info, $field['name'])) {
                $this->error($this->attributeMessage('default', 'The `autoincrement()` default value is used on a non-indexed field even though the datasource does not support this.'), $attribute->span);
            }
        }
    }

    /** @param array<string, mixed> $info */
    private function leadsAnIndex(array $info, string $field): bool
    {
        foreach ([...$info['indexes'], ...$info['compoundUniques'], ...($info['primaryKey'] !== null ? [$info['primaryKey']] : [])] as $entry) {
            if (($entry['fields'][0]['name'] ?? null) === $field) {
                return true;
            }
        }

        return false;
    }

    /**
     * The `fields` of a block attribute, resolved against the model's scalar fields.
     *
     * @return list<array{name: string, sort: ?string, length: ?int, ops: ?string}>|null
     */
    private function blockFields(string $model, Attribute $attribute): ?array
    {
        $argument = $attribute->arguments->get('fields', 0);
        if ($argument === null) {
            $this->error($this->attributeMessage('@' . $attribute->name, 'Argument "fields" is missing.'), $attribute->span);

            return null;
        }
        $fields = $this->fieldList($argument->value, '@' . $attribute->name);
        if ($fields === null) {
            return null;
        }
        $what = match ($attribute->name) {
            'id' => 'multi field id declaration',
            'unique' => 'unique index definition',
            default => 'index definition',
        };
        $info = $this->models[$model];
        $unknown = array_filter($fields, fn (array $f) => ! isset($info['fields'][$f['name']]));
        if ($unknown !== []) {
            $this->error(sprintf('Error validating model "%s": The %s refers to the unknown fields: %s.', $model, $what, implode(', ', array_map(fn ($f) => $f['name'], $unknown))), $argument->span);

            return null;
        }
        $relations = array_filter($fields, fn (array $f) => $info['fields'][$f['name']]['kind'] === Field::OBJECT);
        if ($relations !== []) {
            $this->error(sprintf('Error validating model "%s": The %s refers to the relation fields: %s. %s definitions must reference only scalar fields.', $model, $what, implode(', ', array_map(fn ($f) => $f['name'], $relations)), $attribute->name === 'id' ? 'ID' : 'Index'), $argument->span);

            return null;
        }
        if ($fields === []) {
            $this->error($this->attributeMessage('@' . $attribute->name, 'The list of fields must not be empty.'), $argument->span);

            return null;
        }
        foreach ($fields as $field) {
            if ($field['length'] !== null && $this->provider !== 'mysql') {
                $this->error($this->attributeMessage('@' . $attribute->name, 'The length argument is not supported in an index definition with the current connector'), $argument->span);
            }
        }

        return $fields;
    }

    /**
     * `[a, b(sort: Desc, length: 10)]` → field references with options.
     *
     * @return list<array{name: string, sort: ?string, length: ?int, ops: ?string}>|null
     */
    private function fieldList(Expression $value, string $attribute): ?array
    {
        $items = $value instanceof ArrayValue ? $value->items : [$value];
        $fields = [];
        foreach ($items as $item) {
            if ($item instanceof ConstantValue) {
                $fields[] = ['name' => $item->value, 'sort' => null, 'length' => null, 'ops' => null];
                continue;
            }
            if ($item instanceof FunctionCall) {
                $entry = ['name' => $item->name, 'sort' => null, 'length' => null, 'ops' => null];
                foreach ($item->arguments->arguments as $argument) {
                    if ($argument->name === 'sort' && $argument->value instanceof ConstantValue && in_array($argument->value->value, ['Asc', 'Desc'], true)) {
                        $entry['sort'] = $argument->value->value;
                    } elseif ($argument->name === 'length' && $argument->value instanceof NumericValue && $argument->value->isInteger()) {
                        $entry['length'] = (int) $argument->value->value;
                    } elseif ($argument->name === 'ops') {
                        $entry['ops'] = $argument->value->toSource();
                    } else {
                        $this->error($this->attributeMessage($attribute, sprintf('Invalid argument `%s` for the field `%s`: expected sort: Asc | Desc, length: <number> or ops.', $argument->toSource(), $item->name)), $argument->span);
                    }
                }
                $fields[] = $entry;
                continue;
            }
            $this->error($this->attributeMessage($attribute, sprintf('Expected a constant value, but received %s value `%s`.', $item->kind(), $item->toSource())), $item->span);

            return null;
        }

        return $fields;
    }

    // ---- relations --------------------------------------------------------------------------------

    /** Pair relation fields, validate them and fill the DMMF relation data. @return list<ManyToMany> */
    private function relations(): array
    {
        // ambiguity: several fields of one model to the same model with the same (or no) relation name
        foreach ($this->models as $modelName => $info) {
            $groups = [];
            foreach ($info['fields'] as $field) {
                if ($field['kind'] !== Field::OBJECT) {
                    continue;
                }
                $groups[$field['type'] . "\0" . ($field['relation']['name'] ?? "\0")][] = $field;
            }
            foreach ($groups as $fields) {
                $self = $fields[0]['type'] === $modelName;
                $named = $fields[0]['relation']['name'] !== null;
                $list = implode(' and ', array_map(fn ($f) => '`' . $f['name'] . '`', $fields));
                $message = match (true) {
                    count($fields) < 2 => null,
                    $self && ! $named && count($fields) === 2 => sprintf('Ambiguous self relation detected. The fields %s in model `%s` both refer to `%s`. If they are part of the same relation add the same relation name for them with `@relation(<name>)`.', $list, $modelName, $modelName),
                    $self && ! $named => sprintf('Unnamed self relation detected. The fields %s in model `%s` have no relation name. Please provide a relation name for one of them by adding `@relation(<name>).', $list, $modelName),
                    $self && count($fields) === 2 => null,
                    $self => sprintf('Wrongly named self relation detected. The fields %s in model `%s` have the same relation name. At most two relation fields can belong to the same relation and therefore have the same name. Please assign a different relation name to one of them.', $list, $modelName),
                    ! $named => sprintf('Ambiguous relation detected. The fields %s in model `%s` both refer to `%s`. Please provide different relation names for them by adding `@relation(<name>).', $list, $modelName, $fields[0]['type']),
                    default => sprintf('Wrongly named relation detected. The fields %s in model `%s` both use the same relation name. Please provide different relation names for them through `@relation(<name>).', $list, $modelName),
                };
                if ($message !== null) {
                    foreach ($fields as $field) {
                        $this->error(sprintf('Error validating model "%s": %s', $modelName, $message), $field['ast']->span);
                    }
                    foreach ($fields as $field) {
                        $this->models[$modelName]['fields'][$field['name']]['ambiguous'] = true;
                    }
                }
            }
        }

        $done = [];
        $manyToMany = [];
        foreach ($this->models as $modelName => $info) {
            foreach ($info['fields'] as $fieldName => $field) {
                if ($field['kind'] !== Field::OBJECT || isset($done[$modelName . '.' . $fieldName]) || ($field['ambiguous'] ?? false)) {
                    continue;
                }
                $target = $field['type'];
                if (! isset($this->models[$target])) {
                    continue;
                }
                $name = $field['relation']['name'];
                $opposite = null;
                foreach ($this->models[$target]['fields'] ?? [] as $candidate) {
                    if ($candidate['kind'] === Field::OBJECT && $candidate['type'] === $modelName && $candidate['relation']['name'] === $name
                        && ! ($target === $modelName && $candidate['name'] === $fieldName) && ! ($candidate['ambiguous'] ?? false)) {
                        $opposite = $candidate;
                        break;
                    }
                }
                $done[$modelName . '.' . $fieldName] = true;
                if ($opposite === null) {
                    $this->error(sprintf(
                        'Error validating field `%s` in model `%s`: The relation field `%s` on model `%s` is missing an opposite relation field on the model `%s`. Either run `prisma format` or add it manually.',
                        $fieldName, $modelName, $fieldName, $modelName, $target,
                    ), $field['ast']->span);
                    continue;
                }
                $done[$target . '.' . $opposite['name']] = true;
                $relationName = $name ?? NamingConventions::relationName($modelName, $target);
                $this->models[$modelName]['fields'][$fieldName]['relationName'] = $relationName;
                $this->models[$target]['fields'][$opposite['name']]['relationName'] = $relationName;
                $m2m = $this->relation($modelName, $field, $target, $opposite, $relationName);
                if ($m2m !== null) {
                    $manyToMany[] = $m2m;
                }
            }
        }

        return $manyToMany;
    }

    /** Validate one relation (both sides known). @param array<string, mixed> $a @param array<string, mixed> $b */
    private function relation(string $modelA, array $a, string $modelB, array $b, string $relationName): ?ManyToMany
    {
        $hasFields = fn (array $f) => $f['relation']['fields'] !== null || $f['relation']['references'] !== null;
        $attributeSpan = fn (array $f) => ($f['relation']['attribute'] ?? null)?->span ?? $f['ast']->span;

        if ($a['isList'] && $b['isList']) {
            foreach ([[$modelA, $a], [$modelB, $b]] as [$model, $side]) {
                if ($hasFields($side)) {
                    $this->error($this->attributeMessage('relation', sprintf('The relation field `%s` on Model `%s` is part of an implicit many-to-many relation and must not specify the `fields` or `references` argument in the @relation attribute.', $side['name'], $model)), $attributeSpan($side));
                }
            }
            foreach ([[$modelA, $a, $modelB], [$modelB, $b, $modelA]] as [$model, $side, $other]) {
                $otherInfo = $this->models[$other];
                if ($otherInfo['singleId'] === null) {
                    $this->error(sprintf(
                        'Error validating field `%s` in model `%s`: The relation field `%s` on Model `%s` references `%s` which does not have an `@id` field. Models without `@id` cannot be part of a many to many relation. Use an explicit intermediate Model to represent this relationship.',
                        $side['name'], $model, $side['name'], $model, $other,
                    ), $side['ast']->span);
                }
            }
            $names = [$modelA, $modelB];
            sort($names, SORT_STRING);
            [$first, $firstField, $second, $secondField] = $modelA === $names[0] && ($modelA !== $modelB || strcmp($a['name'], $b['name']) <= 0)
                ? [$modelA, $a['name'], $modelB, $b['name']]
                : [$modelB, $b['name'], $modelA, $a['name']];

            return new ManyToMany($relationName, NamingConventions::joinTableName($relationName), $first, $firstField, $second, $secondField);
        }

        // the side declaring fields / references
        if ($a['isList'] || $b['isList']) {
            [$listModel, $list, $fkModel, $fk] = $a['isList'] ? [$modelA, $a, $modelB, $b] : [$modelB, $b, $modelA, $a];
            if ($hasFields($list)) {
                $this->error($this->attributeMessage('relation', sprintf(
                    'The relation field `%s` on Model `%s` must not specify the `fields` or `references` argument in the @relation attribute. You must only specify it on the opposite field `%s` on model `%s`.',
                    $list['name'], $listModel, $fk['name'], $fkModel,
                )), $attributeSpan($list));
            }
            if ($fk['relation']['fields'] === null) {
                $this->error($this->attributeMessage('relation', sprintf('The relation field `%s` on Model `%s` must specify the `fields` argument in the @relation attribute. You can run `prisma format` to fix this automatically.', $fk['name'], $fkModel)), $attributeSpan($fk));
            }
            if ($fk['relation']['references'] === null) {
                $this->error($this->attributeMessage('relation', sprintf('The relation field `%s` on Model `%s` must specify the `references` argument in the @relation attribute.', $fk['name'], $fkModel)), $attributeSpan($fk));
            }
            $this->checkActionsOnlyOnFkSide($listModel, $list, $fkModel, $fk);
            if ($fk['relation']['fields'] !== null && $fk['relation']['references'] !== null) {
                $this->foreignKeySide($fkModel, $fk, $listModel, false);
            }

            return null;
        }

        // one-to-one
        $aFields = $hasFields($a);
        $bFields = $hasFields($b);
        if ($aFields && $bFields) {
            $this->error($this->attributeMessage('relation', sprintf(
                'The relation fields `%s` on Model `%s` and `%s` on Model `%s` both provide the `references` argument in the @relation attribute. You have to provide it only on one of the two fields.',
                $a['name'], $modelA, $b['name'], $modelB,
            )), $attributeSpan($a));

            return null;
        }
        if (! $aFields && ! $bFields) {
            $this->error($this->attributeMessage('relation', sprintf(
                'The relation fields `%s` on Model `%s` and `%s` on Model `%s` do not provide the `fields` argument in the @relation attribute. You have to provide it on one of the two fields.',
                $a['name'], $modelA, $b['name'], $modelB,
            )), $a['ast']->span);

            return null;
        }
        [$fkModel, $fk, $backModel, $back] = $aFields ? [$modelA, $a, $modelB, $b] : [$modelB, $b, $modelA, $a];
        if ($fk['relation']['fields'] === null || $fk['relation']['references'] === null) {
            $this->error($this->attributeMessage('relation', sprintf('The relation field `%s` on Model `%s` must specify the `%s` argument in the @relation attribute.', $fk['name'], $fkModel, $fk['relation']['fields'] === null ? 'fields' : 'references')), $attributeSpan($fk));

            return null;
        }
        if ($back['isRequired']) {
            $this->error($this->attributeMessage('relation', sprintf(
                'The relation field `%s` on Model `%s` is required. This is not valid because it\'s not possible to enforce this constraint on the database level. Please change the field type from `%s` to `%s?` to fix this.',
                $back['name'], $backModel, $fkModel, $fkModel,
            )), $back['ast']->span);
        }
        $this->checkActionsOnlyOnFkSide($backModel, $back, $fkModel, $fk);
        $this->foreignKeySide($fkModel, $fk, $backModel, true);

        return null;
    }

    /** @param array<string, mixed> $back @param array<string, mixed> $fk */
    private function checkActionsOnlyOnFkSide(string $backModel, array $back, string $fkModel, array $fk): void
    {
        if ($back['relation']['onDelete'] !== null || $back['relation']['onUpdate'] !== null) {
            $this->error($this->attributeMessage('relation', sprintf(
                'The relation field `%s` on Model `%s` must not specify the `onDelete` or `onUpdate` argument in the @relation attribute. You must only specify it on the opposite field `%s` on model `%s`, or in case of a many to many relation, in an explicit join table.',
                $back['name'], $backModel, $fk['name'], $fkModel,
            )), $back['relation']['attribute']?->span ?? $back['ast']->span);
        }
    }

    /** Validate fields / references of the side owning the foreign key. @param array<string, mixed> $fk */
    private function foreignKeySide(string $model, array $fk, string $target, bool $oneToOne): void
    {
        $info = $this->models[$model];
        $targetInfo = $this->models[$target];
        $span = $fk['relation']['attribute']?->span ?? $fk['ast']->span;
        $fields = $fk['relation']['fields'];
        $references = $fk['relation']['references'];
        $unknown = array_values(array_filter($fields, fn (string $f) => ! isset($info['fields'][$f])));
        if ($unknown !== []) {
            $this->error(sprintf('Error validating: The argument fields must refer only to existing fields. The following fields do not exist in this model: %s', implode(', ', $unknown)), $span);

            return;
        }
        $relationFields = array_values(array_filter($fields, fn (string $f) => $info['fields'][$f]['kind'] === Field::OBJECT || $info['fields'][$f]['isList']));
        if ($relationFields !== []) {
            $this->error(sprintf('Error validating: The argument fields must refer only to scalar fields. But it is referencing the following relation fields: %s', implode(', ', $relationFields)), $span);

            return;
        }
        $unknown = array_values(array_filter($references, fn (string $f) => ! isset($targetInfo['fields'][$f]) || $targetInfo['fields'][$f]['kind'] === Field::OBJECT));
        if ($unknown !== []) {
            $this->error(sprintf('Error validating: The argument `references` must refer only to existing fields in the related model `%s`. The following fields do not exist in the related model: %s', $target, implode(', ', $unknown)), $span);

            return;
        }
        if (count($fields) !== count($references)) {
            $this->error($this->attributeMessage('relation', 'You must specify the same number of fields in `fields` and `references`.'), $span);

            return;
        }
        if ($fields === []) {
            $this->error($this->attributeMessage('relation', 'The `fields` and `references` arguments must not be empty.'), $span);

            return;
        }
        foreach ($fields as $i => $fieldName) {
            $field = $info['fields'][$fieldName];
            $referenced = $targetInfo['fields'][$references[$i]];
            if ($field['type'] !== $referenced['type'] || $field['kind'] !== $referenced['kind']) {
                $this->error($this->attributeMessage('relation', sprintf(
                    'The type of the field `%s` in the model `%s` is not matching the type of the referenced field `%s` in model `%s`.',
                    $fieldName, $model, $references[$i], $target,
                )), $span);
            }
        }
        if (! $this->isUniqueCriterion($target, $references)) {
            $this->error($this->attributeMessage('relation', count($references) === 1
                ? sprintf('The argument `references` must refer to a unique criterion in the related model. Consider adding an `@unique` attribute to the field `%s` in the model `%s`.', $references[0], $target)
                : sprintf('The argument `references` must refer to a unique criterion in the related model. Consider adding an `@@unique([%s])` attribute to the model `%s`.', implode(', ', $references), $target)), $span);
        }
        if ($oneToOne && ! $this->isUniqueCriterion($model, $fields)) {
            $this->error($this->attributeMessage('relation', count($fields) === 1
                ? sprintf('A one-to-one relation must use unique fields on the defining side. Either add an `@unique` attribute to the field `%s`, or change the relation to one-to-many.', $fields[0])
                : sprintf('A one-to-one relation must use unique fields on the defining side. Either add an `@@unique([%s])` attribute to the model, or change the relation to one-to-many.', implode(', ', $fields))), $span);
        }
        $required = array_filter($fields, fn (string $f) => $info['fields'][$f]['isRequired']);
        $list = implode(', ', $fields);
        if (! $fk['isRequired'] && count($required) === count($fields)) {
            $this->error($this->attributeMessage('relation', sprintf('The relation field `%s` uses the scalar fields %s. At least one of those fields is required. Hence the relation field must be required as well.', $fk['name'], $list)), $fk['ast']->span);
        }
        if ($fk['isRequired'] && count($required) < count($fields)) {
            $this->error($this->attributeMessage('relation', sprintf('The relation field `%s` uses the scalar fields %s. At least one of those fields is optional. Hence the relation field must be optional as well.', $fk['name'], $list)), $fk['ast']->span);
        }
        if ($fk['relation']['onDelete'] === 'SetNull' && $required !== [] || $fk['relation']['onUpdate'] === 'SetNull' && $required !== []) {
            $this->error($this->attributeMessage('relation', sprintf(
                'The `%s` referential action of a relation must not be set to `SetNull` when a referenced field is required. Either choose another referential action, or make the referenced fields optional.',
                $fk['relation']['onDelete'] === 'SetNull' ? 'onDelete' : 'onUpdate',
            )), $span);
        }
        foreach ($fields as $fieldName) {
            $this->models[$model]['fields'][$fieldName]['isReadOnly'] = true;
        }
    }

    /** Are $fields exactly the id, a single @unique field, a @@unique or the @@id of $model? @param list<string> $fields */
    private function isUniqueCriterion(string $model, array $fields): bool
    {
        $info = $this->models[$model];
        $wanted = $fields;
        sort($wanted);
        $criteria = [];
        if ($info['singleId'] !== null) {
            $criteria[] = [$info['singleId']];
        }
        if ($info['primaryKey'] !== null) {
            $criteria[] = array_map(fn ($f) => $f['name'], $info['primaryKey']['fields']);
        }
        foreach ($info['fields'] as $field) {
            if ($field['isUnique']) {
                $criteria[] = [$field['name']];
            }
        }
        foreach ($info['compoundUniques'] as $unique) {
            $criteria[] = array_map(fn ($f) => $f['name'], $unique['fields']);
        }
        foreach ($criteria as $criterion) {
            sort($criterion);
            if ($criterion === $wanted) {
                return true;
            }
        }

        return false;
    }

    private function uniqueCriteria(string $name): void
    {
        $info = $this->models[$name];
        if ($info['isView'] || $info['isIgnored']) {
            return;
        }
        if ($info['singleId'] !== null || $info['primaryKey'] !== null || array_filter($info['fields'], fn (array $f) => $f['isId']) !== []
            || $info['block']->attributes('id') !== [] || $info['block']->attributes('unique') !== []) {
            return;
        }
        foreach ($info['fields'] as $field) {
            if ($field['isUnique'] && $field['isRequired'] && ! $field['isList'] && $field['kind'] !== Field::UNSUPPORTED) {
                return;
            }
        }
        foreach ($info['compoundUniques'] as $unique) {
            $required = array_filter($unique['fields'], fn ($f) => $info['fields'][$f['name']]['isRequired'] ?? false);
            if (count($required) === count($unique['fields'])) {
                return;
            }
        }
        $this->error(sprintf('Error validating model "%s": Each model must have at least one unique criteria that has only required fields. Either mark a single field with `@id`, `@unique` or add a multi field criterion with `@@id([])` or `@@unique([])` to the model.', $name), $info['block']->nameSpan);
    }

    // ---- indexes and database names -----------------------------------------------------------------

    /** DMMF index entries with their database names. @return list<Index> */
    private function indexes(): array
    {
        $provider = $this->provider ?? 'postgresql';
        $indexes = [];
        foreach ($this->models as $modelName => $info) {
            $table = $info['dbName'] ?? $modelName;
            $column = fn (string $field) => $info['fields'][$field]['dbName'] ?? $field;
            $indexFields = fn (array $fields) => array_map(fn (array $f) => new IndexField($f['name'], $f['sort'], $f['length'], $f['ops']), $fields);
            // primary key
            if ($info['singleId'] !== null) {
                $field = $info['fields'][$info['singleId']];
                [$map, $sort, $length] = $this->fieldIndexArgs($field['idArgs']);
                $indexes[] = new Index($modelName, Index::ID, true, [new IndexField($field['name'], $sort, $length)],
                    $provider === 'postgresql' ? ($map ?? NamingConventions::primaryKeyName($table, $provider)) : null, null, null, null, $map !== null);
            } elseif ($info['primaryKey'] !== null) {
                $pk = $info['primaryKey'];
                $indexes[] = new Index($modelName, Index::ID, false, $indexFields($pk['fields']),
                    $provider === 'postgresql' ? ($pk['map'] ?? NamingConventions::primaryKeyName($table, $provider)) : null, $pk['name'], null, null, $pk['map'] !== null);
            }
            // field uniques, compound uniques, indexes
            foreach ($info['fields'] as $field) {
                if (! $field['isUnique']) {
                    continue;
                }
                [$map, $sort, $length] = $this->fieldIndexArgs($field['uniqueArgs']);
                $indexes[] = new Index($modelName, Index::UNIQUE, true, [new IndexField($field['name'], $sort, $length)],
                    $map ?? NamingConventions::uniqueIndexName($table, [$column($field['name'])], $provider), null, null, null, $map !== null);
            }
            foreach ($info['compoundUniques'] as $unique) {
                $indexes[] = new Index($modelName, Index::UNIQUE, false, $indexFields($unique['fields']),
                    $unique['map'] ?? NamingConventions::uniqueIndexName($table, array_map(fn ($f) => $column($f['name']), $unique['fields']), $provider),
                    $unique['name'], null, null, $unique['map'] !== null);
            }
            foreach ($info['indexes'] as $index) {
                $indexes[] = new Index($modelName, Index::NORMAL, false, $indexFields($index['fields']),
                    $index['map'] ?? NamingConventions::normalIndexName($table, array_map(fn ($f) => $column($f['name']), $index['fields']), $provider),
                    null, $index['algorithm'], null, $index['map'] !== null);
            }
        }

        return $indexes;
    }

    /** map / sort / length of a field-level @id or @unique. @return array{0: ?string, 1: ?string, 2: ?int} */
    private function fieldIndexArgs(?Attribute $attribute): array
    {
        if ($attribute === null) {
            return [null, null, null];
        }
        $map = $attribute->arguments->get('map');
        $sort = $attribute->arguments->get('sort');
        $length = $attribute->arguments->get('length');
        if ($length !== null && $this->provider !== 'mysql') {
            $this->error($this->attributeMessage($attribute->name, 'The length argument is not supported in an index definition with the current connector'), $length->span);
        }

        return [
            $map !== null && $map->value instanceof StringValue ? $map->value->value : null,
            $sort !== null && $sort->value instanceof ConstantValue && in_array($sort->value->value, ['Asc', 'Desc'], true) ? $sort->value->value : null,
            $length !== null && $length->value instanceof NumericValue ? (int) $length->value->value : null,
        ];
    }

    /** Table / column / constraint name collisions. @param list<Index> $indexes */
    private function databaseNames(array $indexes): void
    {
        $provider = $this->provider ?? 'postgresql';
        $tables = [];
        $duplicates = [];
        foreach ($this->models as $modelName => $info) {
            $table = $info['dbName'] ?? $modelName;
            if (isset($tables[strtolower($table)])) {
                $duplicates[$modelName] = true;
                $this->error(sprintf('The model with database name "%s" could not be defined because another model or view with this name exists: "%s"', $table, $tables[strtolower($table)]), $info['block']->nameSpan);
            } else {
                $tables[strtolower($table)] = $modelName;
            }
            $columns = [];
            foreach ($info['fields'] as $field) {
                if ($field['kind'] === Field::OBJECT) {
                    continue;
                }
                $columnName = $field['dbName'] ?? $field['name'];
                if (isset($columns[$columnName])) {
                    $this->error(sprintf('Error validating model "%s": Field "%s" and field "%s" both map to a database column with the name "%s".', $modelName, $columns[$columnName], $field['name'], $columnName), $field['ast']->span);
                } else {
                    $columns[$columnName] = $field['name'];
                }
            }
        }
        // index / primary key names: one namespace per schema on PostgreSQL / SQLite, per table on MySQL
        $seen = [];
        foreach ($indexes as $index) {
            if ($index->dbName === null || isset($duplicates[$index->model])) {
                continue;
            }
            $scope = $provider === 'mysql' ? $index->model : '';
            $key = $scope . "\0" . $index->dbName;
            if (isset($seen[$key])) {
                $info = $this->models[$index->model];
                $this->error(sprintf(
                    'The given constraint name `%s` has to be unique in the following namespace: %s. Please provide a different name using the `map` argument.',
                    $index->dbName,
                    $provider === 'mysql' ? sprintf('on model `%s` for indexes and unique constraints', $index->model) : 'global for primary key, indexes and unique constraints',
                ), $info['block']->nameSpan);
            }
            $seen[$key] = true;
        }
        // foreign keys: global on MySQL, per table elsewhere
        $seen = [];
        foreach ($this->models as $modelName => $info) {
            $table = $info['dbName'] ?? $modelName;
            foreach ($info['fields'] as $field) {
                if ($field['kind'] !== Field::OBJECT || ($field['relation']['fields'] ?? null) === null || $field['relation']['fields'] === []) {
                    continue;
                }
                $columns = array_map(fn (string $f) => $info['fields'][$f]['dbName'] ?? $f, $field['relation']['fields']);
                $name = $field['relation']['map'] ?? NamingConventions::foreignKeyName($table, $columns, $provider);
                $key = ($provider === 'mysql' ? '' : $modelName) . "\0" . $name;
                if (isset($seen[$key])) {
                    $this->error(sprintf(
                        'The given constraint name `%s` has to be unique in the following namespace: %s. Please provide a different name using the `map` argument.',
                        $name,
                        $provider === 'mysql' ? 'global for foreign keys' : sprintf('on model `%s` for foreign key', $modelName),
                    ), $field['ast']->span);
                }
                $seen[$key] = true;
            }
        }
    }

    // ---- DMMF -------------------------------------------------------------------------------------

    private function buildModel(string $name): Model
    {
        $info = $this->models[$name];
        $fields = [];
        foreach ($info['fields'] as $field) {
            $isRelation = $field['kind'] === Field::OBJECT;
            $relation = $field['relation'] ?? [];
            $fields[] = new Field(
                name: $field['name'],
                kind: $field['kind'],
                type: $field['kind'] === Field::UNSUPPORTED ? (string) $field['unsupported'] : $field['type'],
                isList: $field['isList'],
                isRequired: $field['isRequired'],
                isUnique: $field['isUnique'],
                isId: $field['isId'],
                isReadOnly: $field['isReadOnly'],
                hasDefaultValue: $field['hasDefault'],
                default: $field['hasDefault'] ? $field['default'] : null,
                dbName: $field['dbName'],
                nativeType: $field['nativeType'],
                relationName: $isRelation ? ($field['relationName'] ?? null) : null,
                relationFromFields: $isRelation ? ($relation['fields'] ?? []) : [],
                relationToFields: $isRelation ? ($relation['references'] ?? []) : [],
                relationOnDelete: $isRelation ? ($relation['onDelete'] ?? null) : null,
                relationOnUpdate: $isRelation ? ($relation['onUpdate'] ?? null) : null,
                relationFkName: $isRelation ? ($relation['map'] ?? null) : null,
                isUpdatedAt: $field['isUpdatedAt'],
                documentation: $field['ast']->documentation,
                isIgnored: $field['isIgnored'],
                defaultMap: $field['defaultMap'],
            );
        }
        $primaryKey = $info['primaryKey'] !== null
            ? ['name' => $info['primaryKey']['name'], 'fields' => array_map(fn ($f) => $f['name'], $info['primaryKey']['fields'])]
            : null;
        $uniqueFields = [];
        $uniqueIndexes = [];
        foreach ($info['compoundUniques'] as $unique) {
            $names = array_map(fn ($f) => $f['name'], $unique['fields']);
            $uniqueFields[] = $names;
            $uniqueIndexes[] = ['name' => $unique['name'], 'fields' => $names];
        }

        return new Model($name, $fields, $info['dbName'], $primaryKey, $uniqueFields, $uniqueIndexes, $info['block']->documentation, $info['isView'], $info['isIgnored']);
    }

    // ---- helpers ----------------------------------------------------------------------------------

    /** @param list<string> $allowed */
    private function checkArguments(Attribute $attribute, array $allowed, int $positional = 1): void
    {
        $unnamed = 0;
        $seen = [];
        foreach ($attribute->arguments->arguments as $argument) {
            if ($argument->name === null) {
                if (++$unnamed > $positional) {
                    $this->error($this->attributeMessage(($attribute->block ? '@' : '') . $attribute->name, 'Unexpected positional argument `' . $argument->value->toSource() . '`.'), $argument->span);
                }
                continue;
            }
            if (isset($seen[$argument->name])) {
                $this->error($this->attributeMessage(($attribute->block ? '@' : '') . $attribute->name, sprintf('The argument `%s` is specified multiple times.', $argument->name)), $argument->span);
            }
            $seen[$argument->name] = true;
            if (! in_array($argument->name, $allowed, true)) {
                $this->error($this->attributeMessage(($attribute->block ? '@' : '') . $attribute->name, sprintf('No such argument: `%s`.', $argument->name)), $argument->span);
            }
        }
    }

    private function stringArgument(Attribute $attribute, string $name): ?string
    {
        $argument = $attribute->arguments->get($name, 0);
        if ($argument === null) {
            $this->error($this->attributeMessage(($attribute->block ? '@' : '') . $attribute->name, sprintf('Argument "%s" is missing.', $name)), $attribute->span);

            return null;
        }
        if (! $argument->value instanceof StringValue) {
            $this->error($this->attributeMessage(($attribute->block ? '@' : '') . $attribute->name, sprintf('Expected a string value, but received %s value `%s`.', $argument->value->kind(), $argument->value->toSource())), $argument->span);

            return null;
        }
        if ($argument->value->value === '') {
            $this->error($this->attributeMessage(($attribute->block ? '@' : '') . $attribute->name, 'The name must not be empty.'), $argument->span);

            return null;
        }

        return $argument->value->value;
    }

    /** `Error parsing attribute "@name": message` ($name with or without "@"; block attributes pass "@index"). */
    private function attributeMessage(string $name, string $message): string
    {
        return sprintf('Error parsing attribute "@%s": %s', $name, $message);
    }

    private function providerLabel(): string
    {
        return match ($this->provider) {
            'postgresql' => 'Postgres',
            'mysql' => 'MySQL',
            'sqlite' => 'SQLite',
            default => 'the current connector',
        };
    }

    private function error(string $message, ?Span $span): void
    {
        $this->diagnostics[] = new Diagnostic($message, $span);
    }
}
