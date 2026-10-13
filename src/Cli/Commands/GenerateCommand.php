<?php

declare(strict_types=1);

namespace Nesto\Cli\Commands;

use Nesto\Cli\Command;
use Nesto\Cli\Generator\Generator;

/**
 * `php nesto g controller cats`, `php nesto generate resource admin/users --orm`, `php nesto g mo cats --flat`.
 */
final class GenerateCommand extends Command
{
    protected string $signature = 'g|generate {schematic : module|controller|service|guard|pipe|interceptor|filter|middleware|class|dto|entity|resource} {name : Name, may contain folders (admin/cats)} {--flat : Do not create a folder for the element} {--no-spec : Skip the test file} {--path= : Extra folder under the source root} {--orm : Resource only - repository-backed service and ORM entity} {--force : Overwrite existing files}';
    protected string $description = 'Generate a Nesto element from a schematic';
    protected string $help = "Schematics and aliases:\n  module (mo), controller (co), service (s), guard (gu), pipe (pi), interceptor (itc),\n  filter (f), middleware (mi), class (cl), dto, entity (e), resource (res)\n\nExamples:\n  {bin} g controller cats          -> src/Cats/CatsController.php, registered in the nearest module (src/Cats/CatsModule.php, a parent folder's module, else the root module)\n  {bin} g resource cats --orm      -> module + controller + service + DTOs + entity (CRUD)\n  {bin} g service admin/users      -> src/Admin/Users/UsersService.php";

    public function handle(): int
    {
        $context = $this->context();
        $generator = new Generator($context);
        $schematic = Generator::resolveSchematic((string) $this->argument('schematic'));
        $result = $generator->generate($schematic, (string) $this->argument('name'), [
            'flat' => (bool) $this->option('flat'),
            'path' => $this->option('path'),
            'spec' => ! $this->option('no-spec'),
            'orm' => (bool) $this->option('orm'),
            'force' => (bool) $this->option('force'),
        ]);

        foreach ($result['created'] as $file) {
            $this->line($this->output->color('CREATE', 'green') . " $file");
        }
        foreach ($result['registered'] as $entry) {
            $this->line($this->output->color('UPDATE', 'cyan') . " {$entry['file']} ({$entry['key']}: {$entry['class']})");
        }
        foreach ($result['skipped'] as $file) {
            $this->line($this->output->color('SKIP  ', 'yellow') . " $file (already exists, use --force to overwrite)");
        }
        foreach ($result['warnings'] as $warning) {
            $this->warn($warning);
        }
        if ($result['created'] === [] && $result['updated'] === []) {
            $this->warn('Nothing generated.');

            return self::FAILURE;
        }
        if ($schematic === 'resource') {
            foreach (self::databaseHints($context, (bool) $this->option('orm')) as $hint) {
                $this->comment($hint);
            }
        }

        return self::SUCCESS;
    }

    /**
     * What a generated resource still needs from the project: an ORM connection for --orm resources, or the Prisma
     * flow when ORM_DRIVER=prisma.
     *
     * @return list<string>
     */
    public static function databaseHints(\Nesto\Cli\ProjectContext $context, bool $orm): array
    {
        $driver = strtolower(trim((string) (self::projectEnv($context, 'ORM_DRIVER') ?? 'orm')));
        $rootFile = $context->rootModuleFile();
        $root = $rootFile !== null && is_file($rootFile) ? (string) file_get_contents($rootFile) : '';
        $hasDatabaseModule = str_contains($root, 'DatabaseModule');
        $hasOrmRoot = preg_match('/new\s+OrmModule\s*\(|OrmModule::forRoot(Async)?\s*\(|\[\s*OrmModule::class\s*,\s*[\'"]forRoot/', $root) === 1;
        $hints = [];
        if ($orm) {
            if (! $hasDatabaseModule && ! $hasOrmRoot) {
                $hints[] = 'Hint: no ORM connection is configured yet. Add `new DatabaseModule()` (use Nesto\\Database\\DatabaseModule) to the imports of '
                    . $context->rootModule() . ': it connects with DB_CONNECTION / DB_DATABASE… from .env (ORM_DRIVER=orm). Or import OrmModule::forRoot([...]) yourself.';
            } elseif ($hasDatabaseModule && ! $hasOrmRoot && $driver !== 'orm') {
                $hints[] = "Hint: ORM_DRIVER is \"$driver\" in .env, but this resource uses the ORM: set ORM_DRIVER=orm (or regenerate without --orm).";
            }
        } elseif ($driver === 'prisma') {
            $hints[] = 'Hint: ORM_DRIVER=prisma: add the model to prisma/schema.prisma, run `php nesto prisma:generate`, then inject PrismaService in the service (`$this->prisma->cat->findMany()`).';
        }

        return $hints;
    }

    /** A variable of the real environment, else of the project's .env. */
    private static function projectEnv(\Nesto\Cli\ProjectContext $context, string $key): ?string
    {
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }
        $file = $context->path('.env');
        if (! is_file($file)) {
            return null;
        }

        return \Nesto\Config\Env::parse((string) file_get_contents($file))[$key] ?? null;
    }
}
