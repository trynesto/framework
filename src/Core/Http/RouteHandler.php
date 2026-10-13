<?php

declare(strict_types=1);

namespace Nesto\Core\Http;

use Nesto\Common\ArgumentMetadata;
use Nesto\Common\Attributes\Body;
use Nesto\Common\Attributes\Catches;
use Nesto\Common\Attributes\Ip;
use Nesto\Common\Attributes\ParamAttribute;
use Nesto\Common\Attributes\Req;
use Nesto\Common\Attributes\Res;
use Nesto\Common\Attributes\UploadedFile;
use Nesto\Common\Attributes\UploadedFiles;
use Nesto\Common\Attributes\UseFilters;
use Nesto\Common\Attributes\UseGuards;
use Nesto\Common\Attributes\UseInterceptors;
use Nesto\Common\Attributes\UsePipes;
use Nesto\Common\Contracts\CallHandler;
use Nesto\Common\Contracts\ExceptionFilter;
use Nesto\Common\Contracts\Guard;
use Nesto\Common\Contracts\Interceptor;
use Nesto\Common\Contracts\ParamResolver;
use Nesto\Common\Contracts\Pipe;
use Nesto\Common\Exceptions\ForbiddenException;
use Nesto\Common\Exceptions\InternalServerErrorException;
use Nesto\Common\Exceptions\PayloadTooLargeException;
use Nesto\Common\Exceptions\ValidationException;
use Nesto\Core\ArgumentsHost;
use Nesto\Core\Container;
use Nesto\Core\ExecutionContext;
use Nesto\Core\ModuleRef;
use Nesto\Core\Reflector;
use Nesto\Core\Routing\Route;
use Nesto\Http\File;
use Nesto\Http\Request;
use Nesto\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Executes one matched route exactly like Nest's request lifecycle:
 *   guards → interceptors(before) → pipes → handler → interceptors(after) → serialize
 * and routes any exception through the matching exception filters.
 */
final class RouteHandler
{
    /** @var list<Guard> */
    private array $globalGuards = [];
    /** @var list<Pipe> */
    private array $globalPipes = [];
    /** @var list<Interceptor> */
    private array $globalInterceptors = [];
    /** @var list<ExceptionFilter> */
    private array $globalFilters = [];
    private ExceptionFilter $fallbackFilter;

    public function __construct(
        private readonly Container $container,
        private readonly Reflector $reflector,
        ?bool $debug = false,
    ) {
        $this->fallbackFilter = new BaseExceptionFilter($debug);
    }

    public function setFallbackFilter(ExceptionFilter $filter): void
    {
        $this->fallbackFilter = $filter;
    }

    public function addGlobalGuards(Guard ...$g): void
    {
        array_push($this->globalGuards, ...$g);
    }

    public function addGlobalPipes(Pipe ...$p): void
    {
        array_push($this->globalPipes, ...$p);
    }

    public function addGlobalInterceptors(Interceptor ...$i): void
    {
        array_push($this->globalInterceptors, ...$i);
    }

    public function addGlobalFilters(ExceptionFilter ...$f): void
    {
        array_push($this->globalFilters, ...$f);
    }

    // ---- entry points ------------------------------------------------------------

    public function handle(Route $route, Request $request, ModuleRef $module): ResponseInterface
    {
        $response = new Response;
        try {
            $controller = $this->container->resolve($route->controller, $module);
        } catch (\Throwable $e) {
            return $this->handleException($e, new ArgumentsHost($request, $response), $module, $route);
        }
        $ctx = new ExecutionContext($request, $response, $route, $controller);

        try {
            return $this->execute($ctx, $controller, $module);
        } catch (\Throwable $e) {
            return $this->handleException($e, $ctx, $module, $route);
        }
    }

    /** Also used for errors raised outside a route handler (404, 405, middleware): no $route = global filters only. */
    public function handleException(\Throwable $e, ArgumentsHost $host, ?ModuleRef $module = null, ?Route $route = null): ResponseInterface
    {
        if ($e instanceof \Nesto\Common\Debug\DumpAndDie) { // console(): no exception filter may turn it into JSON
            return $e->toResponse($host->switchToHttp()->getRequest());
        }
        $filters = [];
        if ($route !== null) {
            $filters = [
                ...$this->enhancers(UseFilters::class, 'filters', $route, $module, methodFirst: true),
            ];
        }
        $builder = $host->switchToHttp()->getResponse();
        foreach ([...$filters, ...array_reverse($this->globalFilters)] as $filter) {
            if ($this->filterCatches($filter, $e)) {
                return $builder->decorate($filter->catch($e, $host));
            }
        }

        return $builder->decorate($this->fallbackFilter->catch($e, $host));
    }

    // ---- lifecycle --------------------------------------------------------------------

    private function execute(ExecutionContext $ctx, object $controller, ModuleRef $module): ResponseInterface
    {
        $route = $ctx->getRoute();

        // 1. guards: global → controller → handler
        $guards = [...$this->globalGuards, ...$this->enhancers(UseGuards::class, 'guards', $route, $module)];
        foreach ($guards as $guard) {
            if (! $guard->canActivate($ctx)) {
                throw new ForbiddenException('Forbidden resource');
            }
        }

        // 2. interceptors wrap (pipes + handler)
        $interceptors = [...$this->globalInterceptors, ...$this->enhancers(UseInterceptors::class, 'interceptors', $route, $module)];
        $pipes = [...$this->globalPipes, ...$this->enhancers(UsePipes::class, 'pipes', $route, $module)];

        $core = function () use ($ctx, $controller, $module, $pipes) {
            $args = $this->resolveArguments($ctx, $module, $pipes);

            return $controller->{$ctx->getHandlerName()}(...$args);
        };
        $chain = $core;
        foreach (array_reverse($interceptors) as $interceptor) {
            $next = $chain;
            $chain = fn () => $interceptor->intercept($ctx, new class($next) implements CallHandler {
                public function __construct(private readonly \Closure $next) {}

                public function handle(): mixed
                {
                    return ($this->next)();
                }
            });
        }

        $result = $chain();

        return $this->serialize($result, $ctx);
    }

    // ---- arguments ---------------------------------------------------------------------

    /** @param list<Pipe> $pipes */
    private function resolveArguments(ExecutionContext $ctx, ModuleRef $module, array $pipes): array
    {
        $request = $ctx->switchToHttp()->getRequest();
        $args = [];
        foreach ($ctx->getHandler()->getParameters() as $param) {
            $args[] = $this->resolveArgument($param, $ctx, $request, $module, $pipes);
        }

        return $args;
    }

    /** @param list<Pipe> $pipes */
    private function resolveArgument(ReflectionParameter $param, ExecutionContext $ctx, Request $request, ModuleRef $module, array $pipes): mixed
    {
        $type = $param->getType();
        $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;
        $builtin = $type instanceof ReflectionNamedType ? $type->isBuiltin() : false;

        // framework objects
        foreach ($param->getAttributes() as $attr) {
            $name = $attr->getName();
            if ($name === Req::class) {
                return $typeName === ServerRequestInterface::class ? $request->psr() : $request;
            }
            if ($name === Res::class) {
                return $ctx->switchToHttp()->getResponse();
            }
            if ($name === Ip::class) {
                return $request->ip();
            }
        }

        // #[Param] / #[Body] / #[Query] / #[Headers]
        $paramAttr = $param->getAttributes(ParamAttribute::class, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
        if ($paramAttr !== null) {
            /** @var ParamAttribute $pa */
            $pa = $paramAttr->newInstance();
            $value = $this->extract($pa, $request);
            if (($pa instanceof UploadedFile || $pa instanceof UploadedFiles) && ! $pa->rules->isEmpty()) {
                $this->checkFileRules($pa, $value, $param);
            }
            $metadata = new ArgumentMetadata($pa->type(), $typeName, $pa->key);
            $paramPipes = array_map(fn ($p) => $this->instance($p, $module, Pipe::class), $pa->pipes);
            foreach ([...$pipes, ...$paramPipes] as $pipe) {
                $value = $pipe->transform($value, $metadata);
            }

            return $this->coerce($value, $param, $typeName, $builtin, $pa);
        }

        // custom param decorators (createParamDecorator equivalent)
        foreach ($param->getAttributes(ParamResolver::class, \ReflectionAttribute::IS_INSTANCEOF) as $attr) {
            $value = $attr->newInstance()->resolve($ctx);
            $metadata = new ArgumentMetadata('custom', $typeName, null);
            foreach ($pipes as $pipe) {
                $value = $pipe->transform($value, $metadata);
            }

            return $value;
        }

        // by type
        if ($typeName !== null && ! $builtin) {
            return match (true) {
                $typeName === Request::class => $request,
                $typeName === ServerRequestInterface::class => $request->psr(),
                $typeName === Response::class => $ctx->switchToHttp()->getResponse(),
                $typeName === ExecutionContext::class, $typeName === ArgumentsHost::class => $ctx,
                default => $this->container->resolve($typeName, $module, strict: false),
            };
        }
        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }
        if ($type?->allowsNull()) {
            return null;
        }

        throw new \LogicException(sprintf(
            'Cannot resolve parameter $%s of %s::%s(): add #[Param], #[Body], #[Query] or a class type.',
            $param->getName(), $ctx->getClassName(), $ctx->getHandlerName()
        ));
    }

    private function extract(ParamAttribute $pa, Request $request): mixed
    {
        return match ($pa->type()) {
            'body' => $pa->key === null ? $this->bodyWithFiles($request) : (data_get($request->body(), $pa->key) ?? $this->fileFields($request)[$pa->key] ?? null),
            'query' => $pa->key === null ? $request->query() : data_get($request->query(), $pa->key),
            'param' => $pa->key === null ? $request->params() : ($request->params()[$pa->key] ?? null),
            'headers' => $pa->key === null ? $request->headers() : $request->header($pa->key),
            'file' => $this->uploads($request, $pa->key)[0] ?? null,
            'files' => $this->uploads($request, $pa->key),
            default => null,
        };
    }

    /**
     * The body of a multipart request with its uploaded files as File objects (`image` => File, `photos` => [File, …]),
     * so a DTO declares files like any other field: #[IsImage(maxSize: '5MB')] public File $image.
     *
     * @return array<string, mixed>
     */
    private function bodyWithFiles(Request $request): array
    {
        $body = $request->body();
        if (! str_starts_with(strtolower($request->header('Content-Type', '')), 'multipart/form-data')) {
            return $body;
        }
        // An empty file list arrives as `files[]=` from Swagger UI and many form libraries: treat it as not sent.
        foreach ($body as $key => $value) {
            if (is_array($value) && $value !== [] && array_filter($value, static fn ($v) => $v !== '' && $v !== null) === []) {
                unset($body[$key]);
            }
        }
        $files = $this->fileFields($request);

        return $files === [] ? $body : array_replace($body, $files);
    }

    /** @return array<string, File|list<File>> */
    private function fileFields(Request $request): array
    {
        $fields = [];
        foreach ($request->files() as $field => $upload) {
            $files = $this->uploads($request, (string) $field);
            if ($files !== []) {
                $fields[(string) $field] = is_array($upload) ? $files : $files[0];
            }
        }

        return $fields;
    }

    /** Rules given on #[UploadedFile('x', maxSize: …, types: …)]; a missing optional file is left to coerce(). */
    private function checkFileRules(UploadedFile|UploadedFiles $pa, mixed $value, ReflectionParameter $param): void
    {
        $field = $pa->key ?? $pa->type();
        if ($value === null || $value === []) {
            if ($param->isDefaultValueAvailable() || $param->allowsNull()) {
                return;
            }
            throw ValidationException::field($field, "$field is required");
        }
        $errors = $pa->rules->check($value, $field);
        if ($errors !== []) {
            throw ValidationException::field($field, $errors);
        }
    }

    /**
     * Uploaded files of a field; PHP's upload errors become HTTP errors instead of a silently missing file.
     *
     * @return list<File>
     */
    private function uploads(Request $request, ?string $field): array
    {
        $files = $request->fileList($field);
        if ($files === []) {
            // Over post_max_size PHP drops the whole body, so the file just looks "missing".
            $length = (int) $request->header('Content-Length', '0');
            $limit = self::iniBytes((string) ini_get('post_max_size'));
            if ($limit > 0 && $length > $limit) {
                throw new PayloadTooLargeException(sprintf('Request body is larger than the server limit (post_max_size=%s)', ini_get('post_max_size')));
            }
        }
        foreach ($files as $file) {
            $name = $file->fieldName ?: 'file';
            match ($file->error()) {
                UPLOAD_ERR_OK => null,
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => throw ValidationException::field($name, sprintf('%s must not be larger than %s (upload_max_filesize)', $name, ini_get('upload_max_filesize')), 413),
                UPLOAD_ERR_PARTIAL => throw ValidationException::field($name, "$name was only partially uploaded"),
                default => throw new InternalServerErrorException(sprintf('"%s" could not be saved by the server (upload error %d)', $name, $file->error())),
            };
        }

        return $files;
    }

    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number << 30,
            'm' => $number << 20,
            'k' => $number << 10,
            default => $number,
        };
    }

    /** Implicit conversion so `#[Param('id')] int $id` and `#[Body] CreateCatDto $dto` work without explicit pipes. */
    private function coerce(mixed $value, ReflectionParameter $param, ?string $typeName, bool $builtin, ParamAttribute $pa): mixed
    {
        if ($value === null) {
            if ($param->isDefaultValueAvailable()) {
                return $param->getDefaultValue();
            }
            if ($param->getType()?->allowsNull() || $typeName === null) {
                return null;
            }
            $label = $pa->key ?? $pa->type();
            throw ValidationException::field($label, "$label is required");
        }
        if ($typeName === null || $typeName === 'mixed') {
            return $value;
        }
        if ($builtin) {
            return match ($typeName) {
                'int' => is_int($value) ? $value : ((is_string($value) && preg_match('/^-?\d+$/', trim($value))) ? (int) $value : throw ValidationException::field($field = $pa->key ?? $pa->type(), "$field must be an integer")),
                'float' => is_numeric($value) ? (float) $value : throw ValidationException::field($field = $pa->key ?? $pa->type(), "$field must be a number"),
                'bool' => is_bool($value) ? $value : (filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? throw ValidationException::field($field = $pa->key ?? $pa->type(), "$field must be a boolean value")),
                'string' => is_scalar($value) ? (string) $value : throw ValidationException::field($field = $pa->key ?? $pa->type(), "$field must be a string"),
                'array' => is_array($value) ? $value : [$value],
                default => $value,
            };
        }
        if ($value instanceof $typeName) {
            return $value;
        }
        if (is_subclass_of($typeName, \BackedEnum::class) && is_scalar($value)) {
            return $typeName::tryFrom($value) ?? throw ValidationException::field($field = $pa->key ?? $pa->type(), "$field must be one of the following values: " . implode(', ', array_map(static fn (\BackedEnum $c) => $c->value, $typeName::cases())));
        }
        if (is_array($value) && class_exists($typeName)) {
            // Plain array → DTO. ValidationPipe normally does this; this is the no-pipe fallback.
            if (class_exists(\Nesto\Transformer\ClassTransformer::class)) {
                return \Nesto\Transformer\ClassTransformer::plainToInstance($typeName, $value);
            }
            $obj = new $typeName;
            foreach ($value as $k => $v) {
                if (property_exists($obj, $k)) {
                    $obj->{$k} = $v;
                }
            }

            return $obj;
        }

        return $value;
    }

    // ---- enhancers ---------------------------------------------------------------------

    /**
     * Collect guard/pipe/interceptor/filter instances declared on the controller class and the handler method.
     * @return list<object>
     */
    private function enhancers(string $attribute, string $property, Route $route, ?ModuleRef $module, bool $methodFirst = false): array
    {
        $targets = [$route->classReflection(), $route->methodReflection()];
        if ($methodFirst) {
            $targets = array_reverse($targets);
        }
        $out = [];
        foreach ($targets as $target) {
            foreach ($this->reflector->all($attribute, $target) as $attr) {
                foreach ($attr->{$property} as $item) {
                    $out[] = $this->instance($item, $module, null);
                }
            }
        }

        return $out;
    }

    /** Class name → resolved through the container (DI works inside guards/pipes); object → as is. */
    public function instance(string|object $item, ?ModuleRef $module, ?string $expected): object
    {
        $instance = is_object($item) ? $item : $this->container->resolve($item, $module, strict: false);
        if ($expected !== null && ! $instance instanceof $expected) {
            throw new \LogicException(sprintf('%s must implement %s', $instance::class, $expected));
        }

        return $instance;
    }

    private function filterCatches(ExceptionFilter $filter, \Throwable $e): bool
    {
        $catches = $this->reflector->get(Catches::class, $filter::class);
        if ($catches === null || $catches->exceptions === []) {
            return true;
        }
        foreach ($catches->exceptions as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }

        return false;
    }

    // ---- serialization -----------------------------------------------------------------

    private function serialize(mixed $result, ExecutionContext $ctx): ResponseInterface
    {
        $route = $ctx->getRoute();
        $builder = $ctx->switchToHttp()->getResponse();

        if ($result instanceof ResponseInterface) {
            return $builder->decorate($result);
        }
        if ($builder->sent() !== null) {
            return $builder->sent();
        }

        $status = $route->meta['httpCode'] ?? ($route->method === 'POST' ? 201 : 200);
        $headers = $route->meta['headers'] ?? [];

        if (isset($route->meta['redirect'])) {
            $target = is_array($result) && isset($result['url']) ? $result : $route->meta['redirect'];
            $response = Response::redirect($target['url'], $target['statusCode'] ?? $target['status'] ?? 302);
        } elseif ($result === null) {
            $response = new \Nyholm\Psr7\Response($status === 201 ? 201 : $status, $headers, '');
        } elseif (is_string($result)) {
            $response = Response::text($result, $status, $headers);
        } else {
            $response = Response::json($this->toPlain($result), $status, $headers);
        }

        return $builder->decorate($response);
    }

    public static function toPlain(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::toPlain(...), $value);
        }
        if ($value instanceof \JsonSerializable || is_scalar($value) || $value === null) {
            return $value;
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        if ($value instanceof \Traversable) {
            return array_map(self::toPlain(...), iterator_to_array($value));
        }
        if (is_object($value)) {
            if (class_exists(\Nesto\Transformer\ClassTransformer::class)) {
                return \Nesto\Transformer\ClassTransformer::instanceToPlain($value);
            }

            return array_map(self::toPlain(...), get_object_vars($value));
        }

        return $value;
    }
}
