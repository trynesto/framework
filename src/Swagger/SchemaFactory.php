<?php

declare(strict_types=1);

namespace Nesto\Swagger;

use Nesto\Swagger\Attributes\ApiHideProperty;
use Nesto\Swagger\Attributes\ApiProperty;
use Nesto\Transformer\Attributes\Exclude;
use Nesto\Transformer\Attributes\Expose;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;

/**
 * Turns PHP classes (DTOs) into OpenAPI schemas and collects them under components.schemas.
 *
 *   $factory = new SchemaFactory;
 *   $ref = $factory->ref(CreateUserDto::class);   // ['$ref' => '#/components/schemas/CreateUserDto']
 *   $factory->schemas();                           // ['CreateUserDto' => ['type' => 'object', 'properties' => [...], 'required' => [...]]]
 *
 * Type mapping: int→integer, float→number, bool→boolean, string→string, array→array (items from `@var Foo[]`,
 * `list<Foo>`, `array<string, Foo>`, `array{a: int}`; short class names resolved through the file's `use` imports),
 * ?T→nullable, BackedEnum→enum, DateTimeInterface→string/date-time, Pagination→{data, meta}, other classes→$ref
 * (recursive). The schema follows the serializer: #[Exclude]d properties are left out (one-way ones are
 * readOnly / writeOnly), #[Expose(name:)] renames, #[Expose(groups:)] makes optional, and #[Expose]d methods are
 * readOnly properties. #[ApiProperty] values override the inferred ones, and validation attributes (matched by short
 * class name — IsEmail, Min, MaxLength, IsOptional, ... — so no dependency on Nesto\Validation) contribute
 * formats, bounds (on the items with `each: true`) and required-ness.
 */
final class SchemaFactory
{
    /** @var array<string, array<string, mixed>> name => schema */
    private array $schemas = [];
    /** @var array<class-string, string> */
    private array $names = [];

    private const PRIMITIVES = [
        'int' => 'integer', 'integer' => 'integer',
        'float' => 'number', 'double' => 'number', 'number' => 'number',
        'bool' => 'boolean', 'boolean' => 'boolean',
        'string' => 'string',
        'array' => 'array', 'iterable' => 'array', 'list' => 'array',
        'object' => 'object', 'stdclass' => 'object',
        'mixed' => null, 'null' => null, 'void' => null,
    ];

    /** PHPDoc pseudo types. */
    private const PSEUDO = [
        'positive-int' => ['type' => 'integer', 'minimum' => 1],
        'negative-int' => ['type' => 'integer', 'maximum' => -1],
        'non-negative-int' => ['type' => 'integer', 'minimum' => 0],
        'non-positive-int' => ['type' => 'integer', 'maximum' => 0],
        'non-empty-string' => ['type' => 'string', 'minLength' => 1],
        'numeric-string' => ['type' => 'string'],
        'lowercase-string' => ['type' => 'string'],
        'class-string' => ['type' => 'string'],
        'literal-string' => ['type' => 'string'],
        'true' => ['type' => 'boolean'],
        'false' => ['type' => 'boolean'],
        'numeric' => ['type' => 'number'],
        'scalar' => [],
        'array-key' => [],
    ];

    /** Validation attribute short names → schema effects. */
    private const VALIDATION = [
        'IsEmail' => ['format' => 'email'],
        'IsUrl' => ['format' => 'uri'],
        'IsUuid' => ['format' => 'uuid'],
        'IsUUID' => ['format' => 'uuid'],
        'IsIp' => ['format' => 'ipv4'],
        'IsIP' => ['format' => 'ipv4'],
        'IsDate' => ['type' => 'string', 'format' => 'date-time'],
        'IsDateString' => ['type' => 'string', 'format' => 'date-time'],
        'IsISO8601' => ['type' => 'string', 'format' => 'date-time'],
        'IsInt' => ['type' => 'integer'],
        'IsInteger' => ['type' => 'integer'],
        'IsNumber' => ['type' => 'number'],
        'IsNumeric' => ['type' => 'number'],
        'IsBoolean' => ['type' => 'boolean'],
        'IsBool' => ['type' => 'boolean'],
        'IsString' => ['type' => 'string'],
        'IsArray' => ['type' => 'array'],
        'IsObject' => ['type' => 'object'],
        'IsPositive' => ['minimum' => 0, 'exclusiveMinimum' => true],
        'IsNegative' => ['maximum' => 0, 'exclusiveMaximum' => true],
        'ArrayNotEmpty' => ['minItems' => 1],
        'IsPort' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 65535],
        'IsLatitude' => ['type' => 'number', 'minimum' => -90, 'maximum' => 90],
        'IsLongitude' => ['type' => 'number', 'minimum' => -180, 'maximum' => 180],
        'IsNumberString' => ['type' => 'string', 'pattern' => '^-?\\d+(\\.\\d+)?$'],
        'IsJSON' => ['type' => 'string', 'format' => 'json'],
        'IsPhoneNumber' => ['type' => 'string', 'format' => 'phone'],
    ];

    // ---- public API ---------------------------------------------------------------

    /** Register a class and return a $ref to it. */
    public function ref(string $class): array
    {
        return ['$ref' => '#/components/schemas/' . $this->register($class)];
    }

    /** Register a class under components.schemas (idempotent) and return its schema name. */
    public function register(string $class): string
    {
        $class = ltrim($class, '\\');
        if (isset($this->names[$class])) {
            return $this->names[$class];
        }
        if (! class_exists($class)) {
            throw new \InvalidArgumentException("Cannot build an OpenAPI schema for unknown class \"$class\".");
        }

        $name = $this->uniqueName((new ReflectionClass($class))->getShortName());
        $this->names[$class] = $name;
        $this->schemas[$name] = ['type' => 'object']; // placeholder breaks recursion
        $this->schemas[$name] = $this->build($class);

        return $name;
    }

    /** @return array<string, array<string, mixed>> */
    public function schemas(): array
    {
        return $this->schemas;
    }

    /**
     * Schema for a type given as a string: 'int', 'string', 'integer', a class name, 'Foo[]', 'date-time'...
     *
     * @return array<string, mixed>
     */
    public function fromTypeString(?string $type, bool $isArray = false): array
    {
        if ($type === null || $type === '') {
            $schema = [];
        } elseif (str_ends_with($type, '[]')) {
            return ['type' => 'array', 'items' => $this->fromTypeString(substr($type, 0, -2))];
        } elseif (array_key_exists(strtolower($type), self::PRIMITIVES)) {
            $mapped = self::PRIMITIVES[strtolower($type)];
            $schema = $mapped === null ? [] : ['type' => $mapped];
        } elseif (in_array(strtolower($type), ['date', 'datetime', 'date-time'], true)) {
            $schema = ['type' => 'string', 'format' => strtolower($type) === 'date' ? 'date' : 'date-time'];
        } elseif (class_exists($type) || interface_exists($type)) {
            $schema = $this->fromClass(ltrim($type, '\\'));
        } else {
            $schema = ['type' => $type];
        }

        return $isArray ? ['type' => 'array', 'items' => $schema] : $schema;
    }

    /**
     * Schema for a reflected type (parameter, property or return type), e.g. `?int` → ['type' => 'integer', 'nullable' => true].
     * For `array`, `iterable`, `mixed` and untyped declarations the docblock type (`@var` / `@return`) is used; class
     * names in it are resolved like PHP does, through the `use` imports of $context's file.
     *
     * @param string|null $context the declaring class (or just its namespace)
     * @return array<string, mixed>
     */
    public function fromReflectionType(?ReflectionType $type, ?string $docComment = null, ?string $context = null): array
    {
        if ($type === null) {
            return $this->fromDocComment($docComment, $context) ?? [];
        }
        if ($type instanceof ReflectionUnionType) {
            $members = [];
            $nullable = false;
            foreach ($type->getTypes() as $member) {
                if ($member instanceof ReflectionNamedType && $member->getName() === 'null') {
                    $nullable = true;
                    continue;
                }
                $members[] = $this->fromReflectionType($member, $docComment, $context);
            }
            $schema = count($members) === 1 ? $members[0] : ['oneOf' => $members];

            return $nullable ? $schema + ['nullable' => true] : $schema;
        }
        if (! $type instanceof ReflectionNamedType) {
            return []; // intersection types → any
        }

        $name = $type->getName();
        if ($type->isBuiltin()) {
            $schema = $this->fromTypeString($name);
            if (in_array($name, ['array', 'iterable', 'mixed'], true)) {
                $fromDoc = $this->fromDocComment($docComment, $context);
                if ($fromDoc !== null && $fromDoc !== []) {
                    $schema = array_diff_key($fromDoc, ['nullable' => 1]);
                }
            }
        } elseif ($name === 'self' || $name === 'static') {
            $schema = $context !== null && class_exists($context) ? $this->fromClass($context) : ['type' => 'object'];
        } else {
            $schema = $this->fromClass($name);
        }
        if ($type->allowsNull() && $name !== 'mixed' && $name !== 'null') {
            $schema['nullable'] = true;
        }

        return $schema;
    }

    /**
     * Schema + required flag + name for one DTO property: its type and docblock, #[Exclude] / #[Expose] (the key and
     * direction the serializer uses), validation attributes, then #[ApiProperty]. Null when the property is never
     * serialized nor hydrated (#[ApiHideProperty], #[Exclude], static).
     *
     * @return array{0: array<string, mixed>, 1: bool, 2: string}|null [schema, required, name]
     */
    public function property(ReflectionProperty $property): ?array
    {
        if ($property->isStatic() || $property->getAttributes(ApiHideProperty::class) !== []) {
            return null;
        }
        $exclude = ($property->getAttributes(Exclude::class)[0] ?? null)?->newInstance();
        if ($exclude !== null && ! $exclude->toPlainOnly && ! $exclude->toClassOnly) {
            return null;
        }
        $expose = ($property->getAttributes(Expose::class)[0] ?? null)?->newInstance();

        $declaring = $property->getDeclaringClass();
        $schema = $this->fromReflectionType($property->getType(), $this->propertyDoc($property), $declaring->getName());

        $hasDefault = $property->hasDefaultValue();
        $allowsNull = $property->getType()?->allowsNull() ?? true;
        $required = ! $hasDefault && ! $allowsNull;
        if ($hasDefault && $property->getDefaultValue() !== null) {
            $schema['default'] = self::plain($property->getDefaultValue());
        } elseif ($property->isPromoted()) {
            $param = self::promotedParameter($property);
            if ($param?->isDefaultValueAvailable()) {
                $required = false;
                if ($param->getDefaultValue() !== null) {
                    $schema['default'] = self::plain($param->getDefaultValue());
                }
            }
        }
        // serialized only (readOnly) / hydrated only (writeOnly); grouped properties are only in some responses
        if ($exclude?->toClassOnly || $expose?->toPlainOnly) {
            $schema['readOnly'] = true;
        } elseif ($exclude?->toPlainOnly || $expose?->toClassOnly) {
            $schema['writeOnly'] = true;
        }
        if ($expose !== null && $expose->groups !== []) {
            $required = false;
        }
        $name = $expose?->name ?? $property->getName();

        [$schema, $required] = $this->applyValidation($property->getAttributes(), $schema, $required);

        // #[ApiProperty] / #[ApiPropertyOptional] win over everything inferred
        $api = $property->getAttributes(ApiProperty::class, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
        if ($api !== null) {
            /** @var ApiProperty $meta */
            $meta = $api->newInstance();
            [$schema, $required, $name] = $this->applyApiProperty($meta, $schema, $required, $name);
        }

        return [$schema, $required, $name];
    }

    /**
     * Schema + required flag + name for a computed value: a public method marked #[Expose] (serialized only, so
     * readOnly). The key is the one the serializer uses: Expose `name`, else the method name without `get`.
     *
     * @return array{0: array<string, mixed>, 1: bool, 2: string}|null
     */
    public function exposedMethod(ReflectionMethod $method): ?array
    {
        $attr = $method->getAttributes(Expose::class)[0] ?? null;
        if ($attr === null || ! $method->isPublic() || $method->isStatic() || $method->getNumberOfRequiredParameters() > 0
            || $method->getAttributes(ApiHideProperty::class) !== []) {
            return null;
        }
        /** @var Expose $expose */
        $expose = $attr->newInstance();
        if ($expose->toClassOnly) {
            return null;
        }
        $name = $expose->name ?? \Nesto\Transformer\ClassMetadata::computedKey($method->getName());
        $type = $method->getReturnType();
        $schema = $this->fromReflectionType($type, $method->getDocComment() ?: null, $method->getDeclaringClass()->getName());
        $schema['readOnly'] = true;
        $required = $type !== null && ! $type->allowsNull() && $expose->groups === [];

        $api = $method->getAttributes(ApiProperty::class, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
        if ($api !== null) {
            [$schema, $required, $name] = $this->applyApiProperty($api->newInstance(), $schema, $required, $name);
        }

        return [$schema, $required, $name];
    }

    /**
     * Every documented member of a class, in declaration order: public properties, then #[Expose]d methods.
     *
     * @return list<array{0: array<string, mixed>, 1: bool, 2: string}>
     */
    public function properties(string $class): array
    {
        $ref = new ReflectionClass($class);
        $out = [];
        foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if (($entry = $this->property($property)) !== null) {
                $out[] = $entry;
            }
        }
        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (($entry = $this->exposedMethod($method)) !== null) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * Validation attributes (matched by short name; no dependency on the Validation package). `each: true` applies
     * to the items of an array, and #[IsOptional] wins over #[IsNotEmpty] / #[IsDefined] whatever their order.
     *
     * @param list<\ReflectionAttribute<object>> $attributes
     * @param array<string, mixed> $schema
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function applyValidation(array $attributes, array $schema, bool $required): array
    {
        $optional = false;
        foreach ($attributes as $attr) {
            $short = self::shortName($attr->getName());
            $args = $attr->getArguments();
            $first = $args === [] ? null : ($args[0] ?? reset($args));
            switch ($short) {
                case 'IsOptional':
                    $optional = true;
                    continue 2;
                case 'IsNotEmpty':
                case 'IsDefined':
                    $required = true;
                    continue 2;
                case 'IsFile':
                case 'IsImage':
                    $binary = ['type' => 'string', 'format' => 'binary'];
                    $schema = ($schema['type'] ?? null) === 'array' || ($args['each'] ?? false) === true ? ['type' => 'array', 'items' => $binary] : $binary;
                    $rules = array_filter([
                        $short === 'IsImage' ? 'image' : null,
                        isset($args['types']) ? implode(', ', (array) $args['types']) : null,
                        isset($args['maxSize']) ? 'max ' . $args['maxSize'] : (isset($args[0]) ? 'max ' . $args[0] : null),
                        isset($args['maxFiles']) ? "up to {$args['maxFiles']} files" : null,
                    ]);
                    if ($rules !== []) {
                        $schema['description'] = implode('; ', $rules);
                    }
                    if (($args['minFiles'] ?? 0) > 0) {
                        $required = true;
                    }
                    continue 2;
                case 'ArrayMinSize':
                    $schema['minItems'] = $first;
                    continue 2;
                case 'ArrayMaxSize':
                    $schema['maxItems'] = $first;
                    continue 2;
            }
            if (($args['each'] ?? false) === true) {
                // the rule applies to every element: describe the items
                if (($schema['type'] ?? null) !== 'array') {
                    $schema = ['type' => 'array'] + array_intersect_key($schema, ['nullable' => 1, 'default' => 1]);
                }
                $schema['items'] = $this->applyRule($short, $args, $first, (array) ($schema['items'] ?? []));
            } else {
                $schema = $this->applyRule($short, $args, $first, $schema);
            }
        }

        return [$schema, $required && ! $optional];
    }

    /**
     * @param array<int|string, mixed> $args
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function applyRule(string $short, array $args, mixed $first, array $schema): array
    {
        if (isset(self::VALIDATION[$short])) {
            return array_replace($schema, self::VALIDATION[$short]);
        }
        switch ($short) {
            case 'Min':
                $schema['minimum'] = $first;
                break;
            case 'Max':
                $schema['maximum'] = $first;
                break;
            case 'MinLength':
                $schema['minLength'] = $first;
                break;
            case 'MaxLength':
                $schema['maxLength'] = $first;
                break;
            case 'Length':
                $schema['minLength'] = $first;
                if (isset($args[1]) || isset($args['max'])) {
                    $schema['maxLength'] = $args[1] ?? $args['max'];
                }
                break;
            case 'Matches':
                $schema['pattern'] = is_string($first) ? trim($first, '/') : $first;
                break;
            case 'IsIn':
                $schema['enum'] = is_array($first) ? array_values($first) : $first;
                break;
            case 'IsEnum':
                if (is_string($first) && enum_exists($first)) {
                    $schema = array_replace($schema, $this->fromEnum($first));
                }
                break;
        }

        return $schema;
    }

    /** The property's docblock, or `@var T` built from the constructor's `@param T $name` for a promoted property. */
    private function propertyDoc(ReflectionProperty $property): ?string
    {
        $doc = $property->getDocComment() ?: null;
        if (($doc === null || ! str_contains($doc, '@var')) && $property->isPromoted()) {
            $ctor = $property->getDeclaringClass()->getConstructor()?->getDocComment() ?: '';
            $type = self::docTag($ctor, 'param', $property->getName());
            if ($type !== null) {
                return '/** @var ' . $type . ' */';
            }
        }

        return $doc;
    }

    private static function promotedParameter(ReflectionProperty $property): ?\ReflectionParameter
    {
        foreach ($property->getDeclaringClass()->getConstructor()?->getParameters() ?? [] as $param) {
            if ($param->getName() === $property->getName()) {
                return $param;
            }
        }

        return null;
    }

    /**
     * Merge an #[ApiProperty] into a schema.
     *
     * @param array<string, mixed> $schema
     * @return array{0: array<string, mixed>, 1: bool, 2: string}
     */
    public function applyApiProperty(ApiProperty $meta, array $schema, bool $required, string $name): array
    {
        $outer = ['nullable' => 1, 'readOnly' => 1, 'writeOnly' => 1]; // flags of the property, not of its items
        if ($meta->type !== null) {
            $schema = $this->fromTypeString($meta->type) + array_intersect_key($schema, $outer);
        }
        if ($meta->isArray === true && ($schema['type'] ?? null) !== 'array') {
            $schema = ['type' => 'array', 'items' => array_diff_key($schema, $outer)] + array_intersect_key($schema, $outer);
        }
        if ($meta->items !== null) {
            $schema['type'] = 'array';
            $schema['items'] = $this->resolveRefs($meta->items);
        }
        if ($meta->enum !== null) {
            if (is_string($meta->enum) && enum_exists($meta->enum)) {
                $schema = array_replace($schema, $this->fromEnum($meta->enum));
            } else {
                $schema['enum'] = array_values((array) $meta->enum);
            }
        }
        foreach ([
            'description' => $meta->description, 'example' => $meta->example, 'format' => $meta->format,
            'minimum' => $meta->minimum, 'maximum' => $meta->maximum, 'default' => $meta->default,
            'minLength' => $meta->minLength, 'maxLength' => $meta->maxLength, 'pattern' => $meta->pattern,
            'minItems' => $meta->minItems, 'maxItems' => $meta->maxItems, 'title' => $meta->title,
            'nullable' => $meta->nullable,
        ] as $key => $value) {
            if ($value !== null) {
                $schema[$key] = $value;
            }
        }
        foreach (['readOnly', 'writeOnly', 'deprecated'] as $flag) {
            if ($meta->{$flag}) {
                $schema[$flag] = true;
            }
        }
        if ($meta->required !== null) {
            $required = $meta->required;
        }
        if ($meta->name !== null) {
            $name = $meta->name;
        }

        return [$schema, $required, $name];
    }

    /** True when a DTO carries uploaded files (File properties or #[IsFile] / #[IsImage]), so it is sent as multipart. */
    public function hasFiles(string $class): bool
    {
        if (! class_exists($class)) {
            return false;
        }
        foreach ((new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $type = $property->getType();
            if ($type instanceof \ReflectionNamedType && ! $type->isBuiltin() && self::isFileClass($type->getName())) {
                return true;
            }
            foreach ($property->getAttributes() as $attr) {
                if (in_array(self::shortName($attr->getName()), ['IsFile', 'IsImage'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function isFileClass(string $class): bool
    {
        return is_a($class, 'Nesto\\Http\\File', true) || is_a($class, \Psr\Http\Message\UploadedFileInterface::class, true);
    }

    /** Schema for a backed enum class. @return array<string, mixed> */
    public function fromEnum(string $enum): array
    {
        $cases = $enum::cases();
        $values = array_map(fn ($c) => $c instanceof \BackedEnum ? $c->value : $c->name, $cases);
        $type = $values !== [] && is_int($values[0]) ? 'integer' : 'string';

        return ['type' => $type, 'enum' => array_values($values)];
    }

    // ---- internals ----------------------------------------------------------------

    /** @return array<string, mixed> */
    private function fromClass(string $class): array
    {
        if (enum_exists($class)) {
            return $this->fromEnum($class);
        }
        if (is_a($class, \DateTimeInterface::class, true)) {
            return ['type' => 'string', 'format' => 'date-time'];
        }
        if (self::isFileClass($class)) {
            return ['type' => 'string', 'format' => 'binary'];
        }
        if ($class === \stdClass::class || ! class_exists($class)) {
            return ['type' => 'object']; // interfaces, unknown names
        }

        return $this->ref($class);
    }

    /** @return array<string, mixed> */
    private function build(string $class): array
    {
        if ($class === 'Nesto\\Orm\\Pagination') {
            return self::paginationSchema();
        }
        $ref = new ReflectionClass($class);
        $properties = [];
        $required = [];

        foreach ($this->properties($class) as [$schema, $isRequired, $name]) {
            $properties[$name] = $schema === [] ? new \stdClass : $schema;
            if ($isRequired) {
                $required[] = $name;
            }
        }

        $schema = ['type' => 'object'];
        if ($properties !== []) {
            $schema['properties'] = $properties;
        }
        if ($required !== []) {
            $schema['required'] = array_values(array_unique($required));
        }
        $summary = self::docSummary($ref->getDocComment() ?: null);
        if ($summary !== null) {
            $schema['description'] = $summary;
        }

        return $schema;
    }

    /** Pagination serializes to {data, meta} (JsonSerializable), not to its properties. */
    private static function paginationSchema(): array
    {
        $int = ['type' => 'integer'];

        return [
            'type' => 'object',
            'properties' => [
                'data' => ['type' => 'array', 'items' => []],
                'meta' => [
                    'type' => 'object',
                    'properties' => ['total' => $int, 'page' => $int, 'perPage' => $int, 'lastPage' => $int],
                    'required' => ['total', 'page', 'perPage', 'lastPage'],
                ],
            ],
            'required' => ['data', 'meta'],
            'description' => 'One page of results: the items in data, the page numbers in meta.',
        ];
    }

    /**
     * Raw schemas (#[ApiResponse(schema:)], #[ApiBody(schema:)], ...) may use a class name as `$ref`, PHP's stand-in
     * for Nest's getSchemaPath(): `['$ref' => Post::class]` becomes `#/components/schemas/Post` (and registers Post).
     *
     * @param array<mixed> $schema
     * @return array<mixed>
     */
    public function resolveRefs(array $schema): array
    {
        foreach ($schema as $key => $value) {
            if ($key === '$ref' && is_string($value) && ! str_starts_with($value, '#') && ! str_contains($value, '/') && class_exists($value)) {
                $schema[$key] = '#/components/schemas/' . $this->register($value);
            } elseif (is_array($value) && ! in_array($key, ['example', 'examples', 'default', 'enum'], true)) {
                $schema[$key] = $this->resolveRefs($value);
            }
        }

        return $schema;
    }

    /** `@var` / `@return` type of a docblock → schema, or null when there is none or it cannot be resolved. */
    private function fromDocComment(?string $doc, ?string $context): ?array
    {
        if ($doc === null) {
            return null;
        }
        $type = self::docTag($doc, 'var') ?? self::docTag($doc, 'return');

        return $type === null ? null : $this->fromDocType($type, $context);
    }

    /**
     * The type expression after `@tag` (for `@param`, the one followed by `$name`), generics and shapes included.
     */
    private static function docTag(string $doc, string $tag, ?string $param = null): ?string
    {
        $offset = 0;
        while (preg_match('/@' . $tag . '\s+/', $doc, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $pos = $m[0][1] + strlen($m[0][0]);
            $offset = $pos;
            $depth = 0;
            $type = '';
            for ($len = strlen($doc); $pos < $len; $pos++) {
                $c = $doc[$pos];
                if (strpbrk($c, '<{([') !== false) {
                    $depth++;
                } elseif (strpbrk($c, '>})]') !== false) {
                    $depth--;
                } elseif ($depth <= 0 && ctype_space($c)) {
                    break;
                } elseif ($c === '*' && ($doc[$pos + 1] ?? '') === '/') {
                    break;
                }
                $type .= $depth > 0 && ctype_space($c) ? ' ' : $c;
            }
            if ($type === '' || str_starts_with($type, '$')) {
                continue;
            }
            if ($param !== null && ! preg_match('/^\s*(?:\.\.\.)?\$' . preg_quote($param, '/') . '\b/', substr($doc, $pos))) {
                continue;
            }

            return $type;
        }

        return null;
    }

    /**
     * A PHPDoc type → schema: `T[]`, `list<T>`, `array<T>`, `array<string, T>`, `non-empty-list<T>`, `?T`, `T|null`,
     * `array{a: int, b?: string}`, scalars and their pseudo types, classes (resolved through use imports).
     * Null when a part cannot be resolved.
     */
    private function fromDocType(string $type, ?string $context): ?array
    {
        $type = trim($type);
        if (str_starts_with($type, '?')) {
            $schema = $this->fromDocType(substr($type, 1), $context);

            return $schema === null ? null : $schema + ['nullable' => true];
        }
        $parts = self::splitTop($type, '|');
        if (count($parts) > 1) {
            $nullable = false;
            $members = [];
            foreach ($parts as $part) {
                if (strtolower($part) === 'null') {
                    $nullable = true;
                    continue;
                }
                $member = $this->fromDocType($part, $context);
                if ($member === null) {
                    return null;
                }
                $members[] = $member;
            }
            $members = array_values(array_unique($members, SORT_REGULAR));
            if ($members === []) {
                return null;
            }
            $schema = count($members) === 1 ? $members[0] : ['oneOf' => $members];

            return $nullable ? $schema + ['nullable' => true] : $schema;
        }
        if (str_ends_with($type, '[]')) {
            $items = $this->fromDocType(substr($type, 0, -2), $context);

            return $items === null ? null : ['type' => 'array', 'items' => $items];
        }
        if (preg_match('/^([\w\\-]+)\s*\{(.*)\}$/s', $type, $m)) {
            return $this->fromShape($m[2], $context);
        }
        if (preg_match('/^([\w\\-]+)\s*<(.*)>$/s', $type, $m)) {
            $base = strtolower($m[1]);
            $args = self::splitTop($m[2], ',');
            if (in_array($base, ['int', 'integer'], true)) {
                return ['type' => 'integer']; // int<0, max>
            }
            if (in_array($base, ['class-string', 'key-of', 'value-of'], true)) {
                return $base === 'class-string' ? ['type' => 'string'] : [];
            }
            if (! in_array($base, ['list', 'non-empty-list', 'array', 'non-empty-array', 'iterable'], true)) {
                return $this->fromDocType($m[1], $context); // Collection<Foo> → Collection
            }
            $value = $this->fromDocType(end($args), $context);
            if ($value === null) {
                return null;
            }
            $key = count($args) > 1 ? strtolower(trim($args[0])) : 'int';

            return in_array($key, ['int', 'integer', 'positive-int', 'non-negative-int'], true)
                ? ['type' => 'array', 'items' => $value]
                : ['type' => 'object', 'additionalProperties' => $value];
        }

        $lower = strtolower($type);
        if (isset(self::PSEUDO[$lower])) {
            return self::PSEUDO[$lower];
        }
        if (preg_match('/^-?\d+$/', $type)) {
            return ['type' => 'integer', 'enum' => [(int) $type]];
        }
        if (preg_match('/^([\'"])(.*)\1$/', $type, $m)) {
            return ['type' => 'string', 'enum' => [$m[2]]];
        }
        if (array_key_exists($lower, self::PRIMITIVES)) {
            return $this->fromTypeString($lower);
        }
        if (in_array($lower, ['self', 'static', '$this'], true)) {
            $type = $context ?? $type;
        }
        $class = TypeNameResolver::resolve($type, $context);
        if (class_exists($class) || interface_exists($class) || enum_exists($class)) {
            return $this->fromClass($class);
        }

        return null;
    }

    /** `name: string, count?: int` (array shape) → object schema; a shape without keys → array. */
    private function fromShape(string $body, ?string $context): ?array
    {
        $properties = [];
        $required = [];
        $list = [];
        foreach (self::splitTop($body, ',') as $entry) {
            if ($entry === '' || $entry === '...') {
                continue;
            }
            if (! preg_match('/^([\w-]+|\'[^\']*\'|"[^"]*")(\?)?\s*:\s*(.+)$/s', $entry, $m)) {
                $list[] = $entry;
                continue;
            }
            $schema = $this->fromDocType($m[3], $context);
            if ($schema === null) {
                return null;
            }
            $key = trim($m[1], '\'"');
            $properties[$key] = $schema;
            if ($m[2] === '') {
                $required[] = $key;
            }
        }
        if ($properties === [] && $list !== []) {
            $items = $this->fromDocType(implode('|', $list), $context);

            return $items === null ? null : ['type' => 'array', 'items' => $items];
        }
        $schema = ['type' => 'object', 'properties' => $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /** @return list<string> $type split on $separator outside <>, {}, () and []. */
    private static function splitTop(string $type, string $separator): array
    {
        $parts = [];
        $depth = 0;
        $current = '';
        for ($i = 0, $len = strlen($type); $i < $len; $i++) {
            $c = $type[$i];
            if (strpbrk($c, '<{([') !== false) {
                $depth++;
            } elseif (strpbrk($c, '>})]') !== false) {
                $depth--;
            }
            if ($c === $separator && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $c;
        }
        $parts[] = trim($current);

        return $parts;
    }

    /** First sentence line of a docblock (used as schema description). */
    public static function docSummary(?string $doc): ?string
    {
        if ($doc === null) {
            return null;
        }
        foreach (preg_split('/\R/', $doc) ?: [] as $line) {
            $line = trim($line, " \t/*");
            if ($line === '' || str_starts_with($line, '@')) {
                continue;
            }
            // drop an inline tag such as "Summary. @deprecated use X"
            $line = trim((string) preg_replace('/\s@[a-z][\w-]*\b.*$/i', '', $line));

            return $line === '' ? null : $line;
        }

        return null;
    }

    public static function shortName(string $class): string
    {
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }

    private function uniqueName(string $short): string
    {
        $name = $short;
        $i = 2;
        while (isset($this->schemas[$name])) {
            $name = $short . $i++;
        }

        return $name;
    }

    /** Default values / examples → JSON-friendly. */
    public static function plain(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value instanceof \UnitEnum) {
            return $value->name;
        }
        if (is_array($value)) {
            return array_map(self::plain(...), $value);
        }

        return $value;
    }
}
