<?php

declare(strict_types=1);

namespace Nesto\Prisma;

use Nesto\Common\Contracts\DynamicModuleFactory;
use Nesto\Common\DynamicModule;
use Nesto\Common\Provider;
use Nesto\Core\Paths;
use Nesto\Prisma\Client\PrismaClient;
use Nesto\Prisma\Dmmf\DmmfCache;
use Nesto\Prisma\Generator\ClientGenerator;

/**
 * The Prisma front-end as a module (the alternative to OrmModule): provides PrismaService — the generated
 * `<namespace>\PrismaService` when `prisma:generate` wrote one, else Nesto\Prisma\PrismaService — also
 * injectable as Nesto\Prisma\Client\PrismaClient. Global by default, like NestJS' recipe module.
 *
 *   #[Module(imports: [new PrismaModule()])]                                      // prisma/schema.prisma, DATABASE_URL
 *   #[Module(imports: [new PrismaModule(schema: 'db/schema.prisma', isGlobal: false, log: ['query'])])]
 *   PrismaModule::forRoot(['service' => PrismaService::class, 'datasourceUrl' => '...'])
 *   PrismaModule::forRootAsync(['useFactory' => fn (ConfigService $c) => ['datasourceUrl' => $c->get('DATABASE_URL')], 'inject' => [ConfigService::class]])
 *
 *   final class UsersService
 *   {
 *       public function __construct(private readonly PrismaService $prisma) {}   // the generated one: typed $prisma->user
 *   }
 *
 * The service is created once and connects in onModuleInit (FrankenPHP workers keep it, with its connection,
 * for every request: the client holds no request state). The schema is read through Dmmf\DmmfCache
 * (storage/cache/prisma-dmmf.php); pass `checkSchema: false` in production to trust the cache file.
 */
final class PrismaModule implements DynamicModuleFactory
{
    public const OPTIONS = 'NestoPrisma:OPTIONS';

    /** @var array<string, mixed> */
    private array $options;

    /**
     * @param ?string                    $schema        schema.prisma path (default: nesto.config.php prisma.schema, composer.json extra.prisma.schema, prisma/schema.prisma)
     * @param ?class-string<PrismaClient> $service       the provider class (default: the generated PrismaService, else Nesto\Prisma\PrismaService)
     * @param ?string                    $datasourceUrl overrides the datasource URL of the schema
     * @param list<mixed>                $log           ['query', 'info', 'warn', 'error'] or [['emit' => 'event', 'level' => 'query']]
     * @param array<string, mixed>       $transactionOptions defaults for transaction(): isolationLevel, timeout, maxWait
     */
    public function __construct(
        ?string $schema = null,
        bool $isGlobal = true,
        ?string $service = null,
        ?string $datasourceUrl = null,
        array $log = [],
        array $transactionOptions = [],
        bool $checkSchema = true,
    ) {
        $this->options = array_filter([
            'schema' => $schema,
            'isGlobal' => $isGlobal,
            'service' => $service,
            'datasourceUrl' => $datasourceUrl,
            'log' => $log,
            'transactionOptions' => $transactionOptions,
            'checkSchema' => $checkSchema,
        ], fn ($v) => $v !== null && $v !== []);
    }

    public function toDynamicModule(): DynamicModule
    {
        return self::forRoot($this->options);
    }

    /**
     * @param array{schema?: string, isGlobal?: bool, service?: class-string<PrismaClient>, datasourceUrl?: string,
     *              log?: list<mixed>, transactionOptions?: array<string, mixed>, checkSchema?: bool, clientOptions?: array<string, mixed>} $options
     */
    public static function forRoot(array $options = []): DynamicModule
    {
        $service = $options['service'] ?? self::defaultService($options['schema'] ?? null);

        return self::build((new Provider(self::OPTIONS))->useValue($options), $service, [], (bool) ($options['isGlobal'] ?? true));
    }

    /**
     * The client options from a factory (`useFactory` + `inject`, `imports`); `service`, `schema` and `isGlobal`
     * are read from the top level because the provider token must be known when the module is built.
     *
     * @param array{useFactory: \Closure|array|string, inject?: list<string>, imports?: list<mixed>, service?: class-string<PrismaClient>, schema?: string, isGlobal?: bool} $options
     */
    public static function forRootAsync(array $options): DynamicModule
    {
        $factory = $options['useFactory'] ?? throw new PrismaException('PrismaModule::forRootAsync() needs a "useFactory" option.');
        $schema = $options['schema'] ?? null;
        $service = $options['service'] ?? self::defaultService($schema);
        $provider = (new Provider(self::OPTIONS))->useFactory($factory, $options['inject'] ?? []);

        return self::build($provider, $service, $options['imports'] ?? [], (bool) ($options['isGlobal'] ?? true), $schema);
    }

    /** @param class-string<PrismaClient> $service */
    private static function build(Provider $optionsProvider, string $service, array $imports, bool $global, ?string $schema = null): DynamicModule
    {
        $providers = [
            $optionsProvider,
            (new Provider($service))->useFactory(fn (mixed $options) => self::createClient($service, (is_array($options) ? $options : []) + array_filter(['schema' => $schema])), [self::OPTIONS]),
        ];
        $exports = [$service];
        if ($service !== PrismaClient::class) {
            $providers[] = (new Provider(PrismaClient::class))->useExisting($service);
            $exports[] = PrismaClient::class;
        }

        return new DynamicModule(module: self::class, imports: $imports, providers: $providers, exports: $exports, global: $global);
    }

    /**
     * @param class-string<PrismaClient> $service
     * @param array<string, mixed>       $options
     */
    public static function createClient(string $service, array $options): PrismaClient
    {
        $project = self::project($options['schema'] ?? null);
        $clientOptions = array_intersect_key($options, array_flip(['datasourceUrl', 'datasources', 'log', 'logStream', 'transactionOptions', 'connection']))
            + (array) ($options['clientOptions'] ?? []);
        $generated = (new \ReflectionMethod($service, 'embeddedDocument'))->getDeclaringClass()->getName() !== PrismaClient::class;
        if (! $generated) {
            // the runtime client: the schema from the compiled cache (parsed once, then opcache)
            $clientOptions += [
                'document' => DmmfCache::load($project->schemaPath, $project->dmmfCacheFile(), (bool) ($options['checkSchema'] ?? true)),
                'schemaDir' => $project->schemaDir(),
            ];
        }
        $project->loadEnv();

        return new $service($clientOptions);
    }

    /** The generated PrismaService of the schema when it exists, else the runtime one. @return class-string<PrismaClient> */
    private static function defaultService(?string $schema): string
    {
        try {
            $project = self::project($schema);
            if (! $project->exists()) {
                return PrismaService::class;
            }
            $document = DmmfCache::load($project->schemaPath, $project->dmmfCacheFile());
            $generator = new ClientGenerator($document, $project->schemaPath, $project->rootDir);
            foreach ($generator->generators() as $block) {
                $class = $generator->namespace($block) . '\\PrismaService';
                if (! class_exists($class) && is_file($loader = $generator->outputDir($block) . '/autoload.php')) {
                    require_once $loader; // namespace not mapped in composer.json: use the generated PSR-4 loader
                }
                if (class_exists($class) && is_subclass_of($class, PrismaClient::class)) {
                    return $class;
                }
            }
        } catch (\Throwable) {
            // no readable schema: the runtime service reports the problem when it is created
        }

        return PrismaService::class;
    }

    private static function project(?string $schema): PrismaProject
    {
        $root = Paths::base();
        $config = [];
        if (is_file($root . '/nesto.config.php')) {
            $loaded = (static fn (string $file) => require $file)($root . '/nesto.config.php');
            $config = is_array($loaded) && is_array($loaded['prisma'] ?? null) ? $loaded['prisma'] : [];
        }

        return PrismaProject::resolve($root, $schema !== null ? Paths::resolve($schema) : null, $config);
    }
}
