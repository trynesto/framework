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

        return self::SUCCESS;
    }
}
