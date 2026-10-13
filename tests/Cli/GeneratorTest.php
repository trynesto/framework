<?php

declare(strict_types=1);

namespace Nesto\Tests\Cli;

use Nesto\Cli\Application;
use Nesto\Cli\Commands\BuiltIn;
use Nesto\Cli\Generator\Generator;
use Nesto\Cli\Generator\Stub;
use Nesto\Cli\Output;
use Nesto\Cli\ProjectContext;
use PHPUnit\Framework\TestCase;

final class GeneratorTest extends TestCase
{
    private string $dir;
    private Application $app;
    private Output $out;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nesto-gen-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/src', 0777, true);
        file_put_contents($this->dir . '/composer.json', '{}');
        file_put_contents($this->dir . '/src/AppModule.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\nuse Nesto\\Common\\Attributes\\Module;\n\n#[Module(imports: [])]\nfinal class AppModule\n{\n}\n");
        $this->out = Output::memory();
        $this->app = (new Application())->setOutput($this->out)->setContext(new ProjectContext($this->dir))->add(...BuiltIn::commands());
    }

    protected function tearDown(): void
    {
        self::rrmdir($this->dir);
    }

    public static function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }

    public static function assertLints(string $file): void
    {
        self::assertFileExists($file);
        exec(sprintf('%s -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($file)), $output, $code);
        self::assertSame(0, $code, "php -l failed for $file:\n" . implode("\n", $output));
    }

    public function testResourceGeneratesEverythingAndRegistersModule(): void
    {
        self::assertSame(0, $this->app->run(['g', 'resource', 'cats', '--no-ansi']));

        foreach ([
            'src/Cats/CatsModule.php', 'src/Cats/CatsController.php', 'src/Cats/CatsService.php',
            'src/Cats/Dto/CreateCatDto.php', 'src/Cats/Dto/UpdateCatDto.php', 'src/Cats/Entities/Cat.php',
            'tests/Cats/CatsControllerTest.php',
        ] as $file) {
            self::assertLints($this->dir . '/' . $file);
        }

        $module = (string) file_get_contents($this->dir . '/src/Cats/CatsModule.php');
        self::assertStringContainsString('controllers: [CatsController::class]', $module);
        self::assertStringContainsString('providers: [CatsService::class]', $module);
        self::assertStringContainsString('namespace App\\Cats;', $module);

        $root = (string) file_get_contents($this->dir . '/src/AppModule.php');
        self::assertStringContainsString('use App\\Cats\\CatsModule;', $root);
        self::assertStringContainsString('imports: [CatsModule::class]', $root);

        $controller = (string) file_get_contents($this->dir . '/src/Cats/CatsController.php');
        self::assertStringContainsString("#[Controller('cats')]", $controller);
        self::assertStringContainsString('CreateCatDto $dto', $controller);

        self::assertStringContainsString('CREATE src/Cats/CatsModule.php', $this->out->fetch());
        self::assertStringContainsString('UPDATE src/AppModule.php', $this->out->fetch());
    }

    public function testResourceWithOrmFlag(): void
    {
        self::assertSame(0, $this->app->run(['g', 'res', 'user-profiles', '--orm', '--no-spec', '--no-ansi']));
        self::assertLints($this->dir . '/src/UserProfiles/UserProfilesService.php');
        $service = (string) file_get_contents($this->dir . '/src/UserProfiles/UserProfilesService.php');
        self::assertStringContainsString('#[InjectRepository(UserProfile::class)]', $service);
        $entity = (string) file_get_contents($this->dir . '/src/UserProfiles/Entities/UserProfile.php');
        self::assertStringContainsString("#[Entity('user_profiles')]", $entity);
        $module = (string) file_get_contents($this->dir . '/src/UserProfiles/UserProfilesModule.php');
        self::assertStringContainsString("[OrmModule::class, 'forFeature', [[UserProfile::class]]]", $module);
        self::assertFileDoesNotExist($this->dir . '/tests/UserProfiles/UserProfilesControllerTest.php');
    }

    public function testResourceAliasesEntityWhoseNameClashesWithAnAttribute(): void
    {
        // `posts` → entity `Post`, which is also the #[Post] route attribute the controller imports.
        self::assertSame(0, $this->app->run(['g', 'resource', 'posts', '--orm', '--no-spec', '--no-ansi']));

        foreach (['PostsController', 'PostsService', 'PostsModule'] as $class) {
            $file = $this->dir . "/src/Posts/$class.php";
            self::assertLints($file);
            self::assertStringContainsString('use App\\Posts\\Entities\\Post as PostEntity;', (string) file_get_contents($file));
        }
        $controller = (string) file_get_contents($this->dir . '/src/Posts/PostsController.php');
        self::assertStringContainsString('#[Post]', $controller);
        self::assertStringContainsString('CreatePostDto $dto): PostEntity', $controller);
        self::assertStringContainsString('"Post #$id not found"', (string) file_get_contents($this->dir . '/src/Posts/PostsService.php'));
    }

    public function testControllerAndServiceAutoRegisterIntoExistingModule(): void
    {
        self::assertSame(0, $this->app->run(['g', 'module', 'dogs']));
        self::assertSame(0, $this->app->run(['g', 'controller', 'dogs']));
        self::assertSame(0, $this->app->run(['g', 'service', 'dogs']));
        // running again is idempotent
        self::assertSame(1, $this->app->run(['g', 'co', 'dogs']));

        $module = (string) file_get_contents($this->dir . '/src/Dogs/DogsModule.php');
        self::assertSame(1, substr_count($module, 'DogsController::class'));
        self::assertStringContainsString('controllers: [DogsController::class]', $module);
        self::assertStringContainsString('providers: [DogsService::class]', $module);
        self::assertStringContainsString('DogsModule::class', (string) file_get_contents($this->dir . '/src/AppModule.php'));

        self::assertLints($this->dir . '/src/Dogs/DogsController.php');
        self::assertLints($this->dir . '/src/Dogs/DogsService.php');
        self::assertLints($this->dir . '/tests/Dogs/DogsControllerTest.php');
        self::assertLints($this->dir . '/tests/Dogs/DogsServiceTest.php');
        self::assertStringContainsString('namespace Tests\\Dogs;', (string) file_get_contents($this->dir . '/tests/Dogs/DogsServiceTest.php'));
    }

    public function testControllerAndServiceWithoutALocalModuleGoIntoTheNearestModuleUpTheTree(): void
    {
        // no module in src/Reports: like `nest g co reports`, the root module (src/AppModule.php) gets it
        self::assertSame(0, $this->app->run(['g', 'controller', 'reports', '--no-spec', '--no-ansi']));
        self::assertSame(0, $this->app->run(['g', 'service', 'reports', '--no-spec', '--no-ansi']));
        $root = (string) file_get_contents($this->dir . '/src/AppModule.php');
        self::assertStringContainsString('controllers: [ReportsController::class]', $root);
        self::assertStringContainsString('providers: [ReportsService::class]', $root);
        self::assertStringContainsString('use App\\Reports\\ReportsController;', $root);
        self::assertLints($this->dir . '/src/AppModule.php');
        $output = $this->out->fetch();
        self::assertStringContainsString('UPDATE src/AppModule.php (controllers: ReportsController)', $output);
        self::assertStringContainsString('UPDATE src/AppModule.php (providers: ReportsService)', $output);

        // a module in a parent folder is nearer than the root module
        self::assertSame(0, $this->app->run(['g', 'module', 'admin', '--no-ansi']));
        self::assertSame(0, $this->app->run(['g', 'controller', 'admin/stats', '--no-spec', '--no-ansi']));
        self::assertStringContainsString('controllers: [StatsController::class]', (string) file_get_contents($this->dir . '/src/Admin/AdminModule.php'));
        self::assertStringNotContainsString('StatsController', (string) file_get_contents($this->dir . '/src/AppModule.php'));
    }

    public function testWarnsWhenNoModuleCouldBeUpdated(): void
    {
        unlink($this->dir . '/src/AppModule.php');
        // a root module class that does not resolve to any file
        $context = new ProjectContext($this->dir, ['rootModule' => 'Gen\\Missing\\AppModule']);
        $app = (new Application())->setOutput($this->out)->setContext($context)->add(...BuiltIn::commands());
        self::assertSame(0, $app->run(['g', 'controller', 'orphans', '--no-spec', '--no-ansi']));
        self::assertFileExists($this->dir . '/src/Orphans/OrphansController.php');
        self::assertStringContainsString('OrphansController was not added to any module', $this->out->fetch());
    }

    public function testNestedNameFlatAndPathOptions(): void
    {
        self::assertSame(0, $this->app->run(['g', 'service', 'admin/users', '--no-spec']));
        self::assertLints($this->dir . '/src/Admin/Users/UsersService.php');
        self::assertStringContainsString('namespace App\\Admin\\Users;', (string) file_get_contents($this->dir . '/src/Admin/Users/UsersService.php'));

        self::assertSame(0, $this->app->run(['g', 'controller', 'health', '--flat', '--no-spec']));
        self::assertLints($this->dir . '/src/HealthController.php');
        self::assertStringContainsString('namespace App;', (string) file_get_contents($this->dir . '/src/HealthController.php'));
        // flat + a *Module.php in src/ → registered in AppModule
        self::assertStringContainsString('controllers: [HealthController::class]', (string) file_get_contents($this->dir . '/src/AppModule.php'));

        self::assertSame(0, $this->app->run(['g', 'guard', 'roles', '--path=Core', '--no-spec']));
        self::assertLints($this->dir . '/src/Core/Roles/RolesGuard.php');
        self::assertStringContainsString('namespace App\\Core\\Roles;', (string) file_get_contents($this->dir . '/src/Core/Roles/RolesGuard.php'));
    }

    public function testOtherSchematics(): void
    {
        foreach (['pipe' => 'ParsePipe', 'interceptor' => 'LoggingInterceptor', 'filter' => 'HttpFilter', 'middleware' => 'CorsMiddleware', 'class' => 'Helper'] as $schematic => $class) {
            $name = strtolower(preg_replace('/(Pipe|Interceptor|Filter|Middleware)$/', '', $class) ?? $class);
            self::assertSame(0, $this->app->run(['g', $schematic, $name, '--no-spec']), $schematic);
            $folder = ucfirst($name);
            self::assertLints($this->dir . "/src/$folder/$class.php");
        }
        self::assertSame(0, $this->app->run(['g', 'dto', 'cats/create-cat']));
        self::assertLints($this->dir . '/src/Cats/Dto/CreateCatDto.php');
        self::assertSame(0, $this->app->run(['g', 'entity', 'cat']));
        self::assertLints($this->dir . '/src/Cats/Entities/Cat.php');
        self::assertSame(0, $this->app->run(['g', 'cl', 'money', '--no-spec']));
        self::assertLints($this->dir . '/src/Money/Money.php');
    }

    public function testUnknownSchematicFails(): void
    {
        self::assertSame(1, $this->app->run(['g', 'widget', 'x']));
        self::assertStringContainsString('Unknown schematic', $this->out->fetch());
        $this->expectException(\Nesto\Cli\CliException::class);
        Generator::resolveSchematic('widget');
    }

    public function testMakeCommandAndMigrateGenerate(): void
    {
        self::assertSame(0, $this->app->run(['make:command', 'send-emails']));
        self::assertLints($this->dir . '/src/Commands/SendEmailsCommand.php');
        $output = $this->out->fetch();
        self::assertStringContainsString("'commands' => [\\App\\Commands\\SendEmailsCommand::class]", $output);
        self::assertStringContainsString('then run: php nesto send-emails', $output);
        self::assertDoesNotMatchRegularExpression('/(?<!php )nesto send-emails/', $output, 'project commands run through php nesto');

        self::assertSame(0, $this->app->run(['migrate:generate', 'create_cats_table']));
        $files = glob($this->dir . '/database/migrations/*_create_cats_table.php');
        self::assertCount(1, $files);
        self::assertLints($files[0]);
        self::assertStringContainsString('function up(Schema $schema)', (string) file_get_contents($files[0]));
    }

    public function testEveryStubRendersWithoutLeftoverPlaceholders(): void
    {
        self::assertNotEmpty(Stub::all());
        foreach (Stub::all() as $stub) {
            $template = (string) file_get_contents(Stub::path($stub));
            preg_match_all('/\{\{\s*(\w+)\s*\}\}/', $template, $m);
            $vars = array_fill_keys(array_unique($m[1]), 'X');
            self::assertStringNotContainsString('{{', Stub::render($stub, $vars), $stub);
        }
    }
}
