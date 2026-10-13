<?php

declare(strict_types=1);

namespace Nesto\Swagger;

use Nesto\Common\Attributes\ParamAttribute;
use Nesto\Common\Exceptions\HttpException;
use Nesto\Core\Reflector;
use Nesto\Core\Routing\Route;
use Nesto\Swagger\Attributes\ApiBody;
use Nesto\Swagger\Attributes\ApiConsumes;
use Nesto\Swagger\Attributes\ApiExcludeController;
use Nesto\Swagger\Attributes\ApiExcludeEndpoint;
use Nesto\Swagger\Attributes\ApiExtraModels;
use Nesto\Swagger\Attributes\ApiHeader;
use Nesto\Swagger\Attributes\ApiOperation;
use Nesto\Swagger\Attributes\ApiParam;
use Nesto\Swagger\Attributes\ApiProduces;
use Nesto\Swagger\Attributes\ApiProperty;
use Nesto\Swagger\Attributes\ApiQuery;
use Nesto\Swagger\Attributes\ApiResponse;
use Nesto\Swagger\Attributes\ApiSecurity;
use Nesto\Swagger\Attributes\ApiTags;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Builds the `paths` / `components.schemas` part of an OpenAPI 3.0.3 document from compiled routes.
 * Used by SwaggerModule::createDocument(); usable standalone with any list of Route objects.
 *
 *   $doc = (new DocumentGenerator)->generate($app->router()->routes(), (new DocumentBuilder)->setTitle('API')->build());
 *
 * Options: include (list of module classes to document), extraModels (DTO classes), operationIdFactory
 * (callable(string $controllerKey, string $methodKey): string), ignoreGlobalPrefix (prefix to strip from paths, or
 * true for the app's prefix passed as globalPrefix).
 *
 * With ignoreGlobalPrefix the prefix moves into `servers` (`/api`, or each configured server + `/api`) so "Try it out"
 * still calls the real URL; routes served without the prefix (setGlobalPrefix exclude) get path-level servers without it.
 */
final class DocumentGenerator
{
    private const SUCCESS_DESCRIPTIONS = [200 => 'OK', 201 => 'Created', 202 => 'Accepted', 204 => 'No Content'];

    private Reflector $reflector;
    private SchemaFactory $schemas;

    /** @param array<string, mixed> $options */
    public function __construct(private readonly array $options = [], ?Reflector $reflector = null, ?SchemaFactory $schemas = null)
    {
        $this->reflector = $reflector ?? new Reflector;
        $this->schemas = $schemas ?? new SchemaFactory;
    }

    public function schemaFactory(): SchemaFactory
    {
        return $this->schemas;
    }

    /**
     * @param list<Route>          $routes
     * @param array<string, mixed> $config from DocumentBuilder::build()
     * @return array<string, mixed>
     */
    public function generate(array $routes, array $config): array
    {
        $document = $config;
        $document['openapi'] ??= '3.0.3';
        $document['info'] ??= ['title' => '', 'version' => '1.0.0'];
        $paths = (array) ($document['paths'] ?? []);
        $include = $this->options['include'] ?? null;
        $prefix = $this->ignoredPrefix();
        $prefixed = false;
        $unprefixed = [];

        foreach ($this->options['extraModels'] ?? [] as $model) {
            $this->schemas->register($model);
        }

        foreach ($routes as $route) {
            if ($include !== null && ! in_array($route->moduleClass, (array) $include, true)) {
                continue;
            }
            $class = $route->classReflection();
            $method = $route->methodReflection();
            if ($class->getAttributes(ApiExcludeController::class) !== [] || $method->getAttributes(ApiExcludeEndpoint::class) !== []) {
                continue;
            }
            foreach ($this->reflector->getAllAndMerge(ApiExtraModels::class, [$class, $method]) as $extra) {
                foreach ($extra->models as $model) {
                    $this->schemas->register($model);
                }
            }

            $path = $this->openApiPath($route);
            if ($prefix !== '') {
                $this->hasPrefix($route, $prefix) ? $prefixed = true : $unprefixed[$path] = true;
            }
            $operation = $this->operation($route, $class, $method);
            $verbs = $route->method === '*' ? ['get', 'post', 'put', 'patch', 'delete'] : [strtolower($route->method)];
            foreach ($verbs as $verb) {
                $paths[$path][$verb] ??= $operation;
            }
        }

        if ($prefixed) {
            [$document['servers'], $rootServers] = $this->prefixServers((array) ($document['servers'] ?? []), $prefix);
            foreach (array_keys($unprefixed) as $path) {
                $paths[$path]['servers'] ??= $rootServers;
            }
        }
        $document['paths'] = $paths === [] ? new \stdClass : self::emptySchemasAsObjects($paths);
        $components = (array) ($document['components'] ?? []);
        $schemas = self::emptySchemasAsObjects($this->schemas->schemas());
        if ($schemas !== []) {
            $components['schemas'] = array_replace((array) ($components['schemas'] ?? []), $schemas);
        }
        if ($components !== []) {
            $document['components'] = $components;
        } else {
            unset($document['components']);
        }

        return $document;
    }

    // ---- paths -----------------------------------------------------------------

    /** `/users/{id:\d+}` and `/users/:id` → `/users/{id}`; optional global prefix stripped. */
    public function openApiPath(Route $route): string
    {
        $path = $route->path;
        $prefix = $this->ignoredPrefix();
        if ($prefix !== '' && $this->hasPrefix($route, $prefix)) {
            $path = substr($path, strlen($prefix) + 1) ?: '/';
        }
        $path = (string) preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*):[^}]+\}/', '{$1}', $path);
        $path = (string) preg_replace('/:([a-zA-Z_][a-zA-Z0-9_]*)/', '{$1}', $path);

        return str_replace('*', '{wildcard}', $path);
    }

    private function ignoredPrefix(): string
    {
        $option = $this->options['ignoreGlobalPrefix'] ?? '';
        $prefix = $option === true ? ($this->options['globalPrefix'] ?? '') : ($option === false ? '' : $option);

        return trim((string) $prefix, '/');
    }

    /** Whether the route is served under the prefix (not excluded from it, whole path segment). */
    private function hasPrefix(Route $route, string $prefix): bool
    {
        return ! ($route->meta['prefixExcluded'] ?? false)
            && ($route->path === '/' . $prefix || str_starts_with($route->path, '/' . $prefix . '/'));
    }

    /**
     * Servers for prefixed paths (each server + `/prefix`; `/prefix` when none is configured) and for paths served
     * without the prefix (the servers as they are, with a `/prefix` someone already appended removed).
     *
     * @param list<array<string, mixed>> $servers
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function prefixServers(array $servers, string $prefix): array
    {
        $prefixed = [];
        $root = [];
        foreach ($servers ?: [['url' => '/']] as $server) {
            $url = rtrim((string) ($server['url'] ?? ''), '/');
            $base = str_ends_with($url, '/' . $prefix) ? substr($url, 0, -strlen($prefix) - 1) : $url;
            $prefixed[] = ['url' => $base . '/' . $prefix] + $server;
            $root[] = ['url' => $base === '' ? '/' : $base] + $server;
        }

        return [$prefixed, $root];
    }

    /** @return array<string, mixed> */
    private function operation(Route $route, ReflectionClass $class, ReflectionMethod $method): array
    {
        $op = [];

        $tags = $this->reflector->getAllAndOverride(ApiTags::class, [$method, $class]);
        $op['tags'] = $tags?->tags ?: [self::controllerTag($class)];

        $info = $this->reflector->get(ApiOperation::class, $method);
        if ($info?->summary !== null) {
            $op['summary'] = $info->summary;
        }
        if ($info?->description !== null) {
            $op['description'] = $info->description;
        } elseif (($summary = SchemaFactory::docSummary($method->getDocComment() ?: null)) !== null) {
            $op['description'] = $summary;
        }
        $op['operationId'] = $info?->operationId ?? $this->operationId($class, $method);
        if ($info?->deprecated || $this->isDeprecated($method)) {
            $op['deprecated'] = true;
        }

        [$parameters, $inlineBody] = $this->parameters($route, $class, $method);
        if ($parameters !== []) {
            $op['parameters'] = $parameters;
        }

        $body = $this->requestBody($class, $method, $inlineBody);
        if ($body !== null) {
            $op['requestBody'] = $body;
        }

        $op['responses'] = $this->responses($route, $class, $method);

        $security = [];
        foreach ($this->reflector->getAllAndMerge(ApiSecurity::class, [$class, $method]) as $sec) {
            $security[] = [$sec->name => $sec->scopes];
        }
        if ($security !== []) {
            $op['security'] = array_values(array_unique($security, SORT_REGULAR));
        }

        return $op;
    }

    // ---- parameters ------------------------------------------------------------

    /**
     * @return array{0: list<array<string, mixed>>, 1: array<string, array{schema: array, required: bool}>} parameters + inline body props
     */
    private function parameters(Route $route, ReflectionClass $class, ReflectionMethod $method): array
    {
        /** @var array<string, array<string, mixed>> $params keyed "in:name" */
        $params = [];
        $inlineBody = [];

        foreach ($route->paramNames as $name) {
            $params["path:$name"] = ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']];
        }

        foreach ($method->getParameters() as $parameter) {
            $attr = $parameter->getAttributes(ParamAttribute::class, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
            if ($attr === null) {
                continue;
            }
            /** @var ParamAttribute $pa */
            $pa = $attr->newInstance();
            $type = $parameter->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;
            $isClass = $typeName !== null && ! $type->isBuiltin() && class_exists($typeName) && ! enum_exists($typeName)
                && ! is_a($typeName, \DateTimeInterface::class, true);

            switch ($pa->type()) {
                case 'param':
                    if ($pa->key === null) {
                        break;
                    }
                    $schema = $this->paramSchema($parameter);
                    $params["path:{$pa->key}"] = array_replace($params["path:{$pa->key}"] ?? ['name' => $pa->key, 'in' => 'path', 'required' => true], ['schema' => $schema]);
                    $this->describeFromDoc($params["path:{$pa->key}"], $parameter);
                    break;

                case 'query':
                    if ($pa->key !== null) {
                        $params["query:{$pa->key}"] = [
                            'name' => $pa->key,
                            'in' => 'query',
                            'required' => ! $parameter->isDefaultValueAvailable() && ! ($type?->allowsNull() ?? true),
                            'schema' => $this->paramSchema($parameter),
                        ];
                        $this->describeFromDoc($params["query:{$pa->key}"], $parameter);
                    } elseif ($isClass) {
                        foreach ($this->dtoProperties($typeName) as [$schema, $required, $name]) {
                            $description = $schema['description'] ?? null;
                            unset($schema['description']);
                            $params["query:$name"] = ['name' => $name, 'in' => 'query', 'required' => $required, 'schema' => $schema];
                            if ($description !== null) {
                                $params["query:$name"]['description'] = $description;
                            }
                        }
                    }
                    break;

                case 'headers':
                    if ($pa->key !== null) {
                        $params["header:{$pa->key}"] = [
                            'name' => $pa->key,
                            'in' => 'header',
                            'required' => ! $parameter->isDefaultValueAvailable() && ! ($type?->allowsNull() ?? true),
                            'schema' => $this->paramSchema($parameter),
                        ];
                    }
                    break;

                case 'file':
                case 'files':
                    $binary = ['type' => 'string', 'format' => 'binary'];
                    // PHP keeps only the last of repeated names: lists must be sent as photos[] (Swagger UI uses this name)
                    $inlineBody[($pa->key ?? ($pa->type() === 'files' ? 'files' : 'file')) . ($pa->type() === 'files' ? '[]' : '')] = [
                        'schema' => $pa->type() === 'files' ? ['type' => 'array', 'items' => $binary] : $binary,
                        'required' => ! $parameter->isDefaultValueAvailable() && ! ($type?->allowsNull() ?? true),
                        'file' => true,
                    ];
                    break;

                case 'body':
                    if ($pa->key !== null) {
                        $inlineBody[$pa->key] = [
                            'schema' => $this->paramSchema($parameter),
                            'required' => ! $parameter->isDefaultValueAvailable() && ! ($type?->allowsNull() ?? true),
                        ];
                    } elseif ($isClass) {
                        $inlineBody['__ref'] = ['schema' => $this->schemas->ref($typeName), 'required' => true, 'multipart' => $this->schemas->hasFiles($typeName), 'class' => $typeName];
                    } else {
                        $inlineBody['__any'] = ['schema' => ['type' => 'object'], 'required' => true];
                    }
                    break;
            }
        }

        // explicit attributes override / add
        foreach ($this->reflector->getAllAndMerge(ApiParam::class, [$class, $method]) as $p) {
            $params["path:{$p->name}"] = $this->explicitParam('path', $p->name, $p->description, $p->required, $p->type, $p->enum, $p->example, $p->format, $p->schema, false, $params["path:{$p->name}"] ?? null);
        }
        foreach ($this->reflector->getAllAndMerge(ApiQuery::class, [$class, $method]) as $q) {
            $params["query:{$q->name}"] = $this->explicitParam('query', $q->name, $q->description, $q->required, $q->type, $q->enum, $q->example, $q->format, $q->schema, $q->isArray, $params["query:{$q->name}"] ?? null);
        }
        foreach ($this->reflector->getAllAndMerge(ApiHeader::class, [$class, $method]) as $h) {
            $params["header:{$h->name}"] = $this->explicitParam('header', $h->name, $h->description, $h->required, $h->type, $h->enum, $h->example, null, $h->schema, false, $params["header:{$h->name}"] ?? null);
        }

        $order = ['path' => 0, 'query' => 1, 'header' => 2, 'cookie' => 3];
        $list = array_values($params);
        usort($list, fn ($a, $b) => $order[$a['in']] <=> $order[$b['in']]);

        return [$list, $inlineBody];
    }

    /** @param list<mixed>|string|null $enum @return array<string, mixed> */
    private function explicitParam(string $in, string $name, ?string $description, bool $required, ?string $type, array|string|null $enum, mixed $example, ?string $format, ?array $schema, bool $isArray, ?array $existing): array
    {
        $param = $existing ?? ['name' => $name, 'in' => $in, 'schema' => ['type' => 'string']];
        $param['required'] = $in === 'path' ? true : $required;
        if ($description !== null) {
            $param['description'] = $description;
        }
        if ($schema !== null) {
            $param['schema'] = $this->schemas->resolveRefs($schema);
        } elseif ($type !== null) {
            $param['schema'] = $this->schemas->fromTypeString($type, $isArray);
        } elseif ($isArray && ($param['schema']['type'] ?? null) !== 'array') {
            $param['schema'] = ['type' => 'array', 'items' => $param['schema']];
        }
        if ($enum !== null) {
            $param['schema'] = array_replace($param['schema'], is_string($enum) && enum_exists($enum) ? $this->schemas->fromEnum($enum) : ['enum' => array_values((array) $enum)]);
        }
        if ($format !== null) {
            $param['schema']['format'] = $format;
        }
        if ($example !== null) {
            $param['example'] = $example;
        }

        return $param;
    }

    /** @return array<string, mixed> */
    private function paramSchema(ReflectionParameter $parameter): array
    {
        $schema = $this->schemas->fromReflectionType($parameter->getType(), null, $parameter->getDeclaringClass()?->getName());
        if ($schema === [] || (! isset($schema['type']) && ! isset($schema['$ref']) && ! isset($schema['oneOf']))) {
            $schema = ['type' => 'string'] + $schema;
        }
        if ($parameter->isDefaultValueAvailable() && $parameter->getDefaultValue() !== null) {
            $schema['default'] = SchemaFactory::plain($parameter->getDefaultValue());
        }
        $api = $parameter->getAttributes(ApiProperty::class, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
        if ($api !== null) {
            [$schema] = $this->schemas->applyApiProperty($api->newInstance(), $schema, true, $parameter->getName());
        }

        return $schema;
    }

    /** @param array<string, mixed> $param */
    private function describeFromDoc(array &$param, ReflectionParameter $parameter): void
    {
        $doc = $parameter->getDeclaringFunction()->getDocComment();
        if ($doc && preg_match('/@param\s+\S+\s+\$' . preg_quote($parameter->getName(), '/') . '\s+([^\n*]+)/', $doc, $m)) {
            $param['description'] = trim($m[1]);
        }
    }

    /**
     * The fields a DTO accepts (query strings, multipart forms): its documented properties minus the readOnly ones
     * (#[Expose]d methods, #[Exclude(toClassOnly: true)]), which are never hydrated.
     *
     * @return list<array{0: array<string, mixed>, 1: bool, 2: string}>
     */
    private function dtoProperties(string $class): array
    {
        return array_values(array_filter(
            $this->schemas->properties($class),
            static fn (array $entry) => ! ($entry[0]['readOnly'] ?? false),
        ));
    }

    // ---- request body ------------------------------------------------------------

    /**
     * A multipart form from a DTO (plus #[UploadedFile] parameters): its fields inline.
     *
     * @param array<string, array{schema: array, required: bool}> $files
     * @return array<string, mixed>
     */
    private function multipartSchema(string $class, array $files): array
    {
        $properties = [];
        $required = [];
        foreach ($this->dtoProperties($class) as [$schema, $isRequired, $name]) {
            $properties[$name] = $schema;
            if ($isRequired) {
                $required[] = $name;
            }
        }
        foreach ($files as $name => $info) {
            $properties[$name] = $info['schema'];
            if ($info['required']) {
                $required[] = $name;
            }
        }
        $schema = ['type' => 'object', 'properties' => $properties];
        if ($required !== []) {
            $schema['required'] = array_values(array_unique($required));
        }

        return $schema;
    }

    /**
     * Lists in a multipart form are named `tags[]`, because PHP keeps only the last of repeated `tags` fields and
     * Swagger UI sends the property name as is; text lists also get `style: form, explode: true` so Swagger UI sends
     * one `tags[]` part per item instead of a single "php,orm" value.
     *
     * @param array<string, mixed> $schema
     * @return array{0: array<string, mixed>, 1: array<string, array<string, mixed>>} [schema, encoding]
     */
    private static function multipartLists(array $schema): array
    {
        $properties = [];
        $renamed = [];
        $encoding = [];
        foreach ((array) ($schema['properties'] ?? []) as $name => $property) {
            if (is_array($property) && ($property['type'] ?? null) === 'array') {
                $binary = is_array($property['items'] ?? null) && ($property['items']['format'] ?? null) === 'binary';
                $field = str_ends_with((string) $name, '[]') ? (string) $name : $name . '[]';
                if ($binary && $field !== $name) {
                    $property['default'] ??= []; // otherwise Swagger UI pre-fills one item and sends the text "string"
                } elseif (! $binary) {
                    $encoding[$field] = ['style' => 'form', 'explode' => true];
                }
                $renamed[$name] = $field;
                $name = $field;
            }
            $properties[$name] = $property;
        }
        if ($properties !== []) {
            $schema['properties'] = $properties;
        }
        if (isset($schema['required'])) {
            $schema['required'] = array_map(static fn ($n) => $renamed[$n] ?? $n, $schema['required']);
        }

        return [$schema, $encoding];
    }

    /** @param array<string, array{schema: array, required: bool}> $inline @return array<string, mixed>|null */
    private function requestBody(ReflectionClass $class, ReflectionMethod $method, array $inline): ?array
    {
        $consumes = $this->reflector->getAllAndOverride(ApiConsumes::class, [$method, $class])?->types ?: null;
        $apiBody = $this->reflector->get(ApiBody::class, $method);

        if ($apiBody !== null) {
            $schema = $apiBody->schema !== null ? $this->schemas->resolveRefs($apiBody->schema) : $this->schemas->fromTypeString($apiBody->type, $apiBody->isArray);
            $content = [];
            foreach ($consumes ?? [$apiBody->contentType] as $type) {
                $content[$type] = ['schema' => $schema === [] ? ['type' => 'object'] : $schema];
                if ($apiBody->examples !== null) {
                    $content[$type]['examples'] = $apiBody->examples;
                }
            }
            $body = ['required' => $apiBody->required, 'content' => $content];
            if ($apiBody->description !== null) {
                $body['description'] = $apiBody->description;
            }

            return $body;
        }

        if ($inline === []) {
            return null;
        }
        $files = array_filter($inline, static fn (array $info) => $info['file'] ?? false);
        if (isset($inline['__ref']) && ($files !== [] || ($inline['__ref']['multipart'] ?? false))) {
            $schema = $this->multipartSchema($inline['__ref']['class'], $files);
        } elseif (isset($inline['__ref'])) {
            $schema = $inline['__ref']['schema'];
        } elseif (isset($inline['__any']) && count($inline) === 1) {
            $schema = ['type' => 'object'];
        } else {
            $schema = ['type' => 'object', 'properties' => []];
            $required = [];
            foreach ($inline as $name => $info) {
                if (str_starts_with($name, '__')) {
                    continue;
                }
                $schema['properties'][$name] = $info['schema'];
                if ($info['required']) {
                    $required[] = $name;
                }
            }
            if ($required !== []) {
                $schema['required'] = $required;
            }
        }
        $content = [];
        $multipart = $files !== [] || ($inline['__ref']['multipart'] ?? false);
        foreach ($consumes ?? [$multipart ? 'multipart/form-data' : 'application/json'] as $type) {
            $content[$type] = ['schema' => $schema];
            if ($type === 'multipart/form-data') {
                $form = isset($schema['$ref'], $inline['__ref']) ? $this->multipartSchema($inline['__ref']['class'], $files) : $schema;
                [$content[$type]['schema'], $encoding] = self::multipartLists($form);
                if ($encoding !== []) {
                    $content[$type]['encoding'] = $encoding;
                }
            }
        }

        return ['required' => true, 'content' => $content];
    }

    // ---- responses -----------------------------------------------------------------

    /** @return array<string, array<string, mixed>> */
    private function responses(Route $route, ReflectionClass $class, ReflectionMethod $method): array
    {
        $produces = $this->reflector->getAllAndOverride(ApiProduces::class, [$method, $class])?->types ?: ['application/json'];
        $responses = [];

        // class-level first so method-level entries override the same status
        foreach ([...$this->reflector->all(ApiResponse::class, $class), ...$this->reflector->all(ApiResponse::class, $method)] as $r) {
            /** @var ApiResponse $r */
            $status = (string) $r->status;
            $entry = ['description' => $r->description ?? self::describeStatus($status)];
            $schema = $r->schema !== null ? $this->schemas->resolveRefs($r->schema) : ($r->type !== null ? $this->schemas->fromTypeString($r->type, $r->isArray) : null);
            if ($schema === null && ctype_digit($status) && (int) $status >= 200 && (int) $status < 300 && $status !== '204') {
                $schema = $this->returnSchema($method); // a description-only success response still documents the body
            }
            if ($schema !== null) {
                foreach ($produces === ['application/json'] ? [$r->contentType] : $produces as $type) {
                    $entry['content'][$type] = ['schema' => $schema];
                    if ($r->example !== null) {
                        $entry['content'][$type]['example'] = $r->example;
                    }
                }
            }
            if ($r->headers !== null) {
                $entry['headers'] = $r->headers;
            }
            $responses[$status] = $entry;
        }

        $hasSuccess = array_filter(array_keys($responses), fn ($s) => $s === 'default' || ((int) $s >= 200 && (int) $s < 300)) !== [];
        if (! $hasSuccess) {
            $status = (string) ($route->meta['httpCode'] ?? ($route->method === 'POST' ? 201 : 200));
            if (isset($route->meta['redirect'])) {
                $status = (string) ($route->meta['redirect']['status'] ?? 302);
            }
            $entry = ['description' => self::describeStatus($status)];
            $schema = $this->returnSchema($method);
            if ($schema !== null && $status !== '204') {
                foreach ($produces as $type) {
                    $entry['content'][$type] = ['schema' => $schema];
                }
            }
            $responses = [$status => $entry] + $responses;
        }
        ksort($responses, SORT_NATURAL);

        return $responses;
    }

    /** Infer a response schema from the handler's return type (classes → $ref, scalars → type). */
    private function returnSchema(ReflectionMethod $method): ?array
    {
        $type = $method->getReturnType();
        if (! $type instanceof ReflectionNamedType) {
            return null;
        }
        $name = $type->getName();
        if (in_array($name, ['void', 'null', 'never', 'mixed', 'array', 'iterable', 'object', 'static', 'self'], true)) {
            return $name === 'array' ? $this->schemas->fromReflectionType($type, $method->getDocComment() ?: null, $method->getDeclaringClass()->getName()) : null;
        }
        if (! $type->isBuiltin() && (is_a($name, \Psr\Http\Message\ResponseInterface::class, true) || is_a($name, \Nesto\Http\Response::class, true))) {
            return null;
        }
        $schema = $this->schemas->fromReflectionType($type, null, $method->getDeclaringClass()->getName());

        return $schema === [] ? null : $schema;
    }

    // ---- helpers ---------------------------------------------------------------------

    /**
     * An "any value" schema is [] in PHP but must be {} in JSON (`"items": {}`): converts the empty arrays found
     * where a schema is expected (items, additionalProperties, property / content schemas), leaving examples alone.
     *
     * @param array<mixed> $node
     * @return array<mixed>
     */
    private static function emptySchemasAsObjects(array $node): array
    {
        foreach ($node as $key => $value) {
            if (in_array($key, ['example', 'examples', 'default', 'enum', 'required', 'security'], true) || ! is_array($value)) {
                continue;
            }
            $node[$key] = $value === [] && in_array($key, ['items', 'additionalProperties', 'not', 'schema'], true)
                ? new \stdClass
                : ($key === 'properties'
                    ? array_map(static fn ($p) => $p === [] ? new \stdClass : (is_array($p) ? self::emptySchemasAsObjects($p) : $p), $value)
                    : self::emptySchemasAsObjects($value));
        }

        return $node;
    }

    public static function describeStatus(string $status): string
    {
        if ($status === 'default') {
            return 'Default response';
        }
        $code = (int) $status;

        return self::SUCCESS_DESCRIPTIONS[$code] ?? HttpException::reasonPhrase($code);
    }

    public static function controllerTag(ReflectionClass $class): string
    {
        $short = $class->getShortName();

        return str_ends_with($short, 'Controller') ? substr($short, 0, -10) : $short;
    }

    private function operationId(ReflectionClass $class, ReflectionMethod $method): string
    {
        $factory = $this->options['operationIdFactory'] ?? null;
        if (is_callable($factory)) {
            return (string) $factory($class->getShortName(), $method->getName());
        }

        return $class->getShortName() . '_' . $method->getName();
    }

    private function isDeprecated(ReflectionMethod $method): bool
    {
        return (bool) preg_match('/@deprecated\b/', $method->getDocComment() ?: '');
    }
}
