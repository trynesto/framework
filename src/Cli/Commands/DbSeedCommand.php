<?php

declare(strict_types=1);

namespace Nesto\Cli\Commands;

use Nesto\Cli\Command;
use Nesto\Common\Attributes\Inject;
use Nesto\Common\Attributes\Optional;
use Nesto\Core\NotFoundException;
use Nesto\Core\NestoApplication;
use Nesto\Orm\Attributes\InjectRepository;
use Nesto\Orm\DataSource;

/**
 * `php nesto db:seed [DatabaseSeeder]` instantiates the seeder through the container and calls run().
 * Seeders live in the configured "seeders" directory (default database/seeders) and may declare
 * typed constructor / run() parameters and #[Inject] properties which are injected like app.get() does,
 * from any module: DataSource, services, `#[InjectRepository(User::class)] Repository $users`.
 */
final class DbSeedCommand extends Command
{
    use InteractsWithPackages;

    protected string $signature = 'db:seed {class? : Seeder class name (default DatabaseSeeder)}';
    protected string $description = 'Seed the database with records';

    public function needsApp(): bool
    {
        return true;
    }

    public function handle(): int
    {
        $app = $this->bootApp();
        if ($app === null) {
            return self::FAILURE;
        }
        $class = (string) ($this->argument('class') ?? 'DatabaseSeeder');
        $class = ltrim($class, '\\');
        if (! class_exists($class)) {
            $dir = $this->context()->path((string) $this->context()->config('seeders'));
            $short = str_contains($class, '\\') ? substr($class, strrpos($class, '\\') + 1) : $class;
            $file = "$dir/$short.php";
            if (is_file($file)) {
                require_once $file;
            }
            if (! class_exists($class)) {
                foreach (['Database\\Seeders\\' . $short, 'App\\Seeders\\' . $short, $short] as $candidate) {
                    if (class_exists($candidate)) {
                        $class = $candidate;
                        break;
                    }
                }
            }
        }
        if (! class_exists($class)) {
            $this->error("Seeder class \"$class\" not found (looked in " . $this->context()->config('seeders') . ').');

            return self::FAILURE;
        }
        if (! method_exists($class, 'run')) {
            $this->error("$class has no run() method.");

            return self::FAILURE;
        }
        try {
            $ctor = (new \ReflectionClass($class))->getConstructor();
            $seeder = new $class(...array_map(fn (\ReflectionParameter $p) => $this->dependency($app, $p), $ctor?->getParameters() ?? []));
            $this->injectProperties($app, $seeder);
            $seeder->run(...array_map(fn (\ReflectionParameter $p) => $this->dependency($app, $p), (new \ReflectionMethod($seeder, 'run'))->getParameters()));
        } catch (\Throwable $e) {
            $this->error('Seeding failed: ' . $e->getMessage());

            return self::FAILURE;
        }
        $this->success("Seeded: $class");

        return self::SUCCESS;
    }

    /**
     * A seeder parameter, resolved like app.get() — from ANY module, so providers and repositories of feature
     * modules that the root module does not import/export are available. #[InjectRepository(Entity::class)]
     * falls back to the DataSource's repository when no module registered one for the entity.
     */
    private function dependency(NestoApplication $app, \ReflectionParameter $param): mixed
    {
        $inject = ($param->getAttributes(Inject::class, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null)?->newInstance();
        $type = $param->getType();
        $token = $inject?->token ?? ($type instanceof \ReflectionNamedType && ! $type->isBuiltin() ? $type->getName() : null);
        if ($token === null) {
            if ($param->isDefaultValueAvailable()) {
                return $param->getDefaultValue();
            }
            throw new \RuntimeException("can't resolve parameter \${$param->getName()}: it has no class type and no #[Inject] token.");
        }
        try {
            return $this->resolveToken($app, $token, $inject);
        } catch (NotFoundException $e) {
            if ($param->isDefaultValueAvailable()) {
                return $param->getDefaultValue();
            }
            if ($param->getAttributes(Optional::class) !== []) {
                return null;
            }
            throw $e;
        }
    }

    private function injectProperties(NestoApplication $app, object $seeder): void
    {
        foreach ((new \ReflectionObject($seeder))->getProperties() as $property) {
            $inject = ($property->getAttributes(Inject::class, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null)?->newInstance();
            if ($inject === null || $property->isPromoted() || $property->isInitialized($seeder)) {
                continue;
            }
            try {
                $property->setValue($seeder, $this->resolveToken($app, $inject->token, $inject));
            } catch (NotFoundException $e) {
                if ($property->getAttributes(Optional::class) === []) {
                    throw $e;
                }
            }
        }
    }

    private function resolveToken(NestoApplication $app, string $token, ?Inject $inject): mixed
    {
        try {
            return $app->get($token);
        } catch (NotFoundException $e) {
            if ($inject instanceof InjectRepository) {
                return $app->get(DataSource::class)->getRepository($inject->entity);
            }
            throw $e;
        }
    }
}
