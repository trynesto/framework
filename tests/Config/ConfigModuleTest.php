<?php

declare(strict_types=1);

namespace Nesto\Tests\Config;

use Nesto\Common\Attributes\Module;
use Nesto\Config\ConfigModule;
use Nesto\Config\ConfigService;
use Nesto\Config\ConfigValidationException;
use Nesto\Config\SchemaValidator;
use Nesto\Core\NestoFactory;
use PHPUnit\Framework\TestCase;

final class ConfigModuleTest extends TestCase
{
    private static string $envFile;

    public static function setUpBeforeClass(): void
    {
        self::$envFile = tempnam(sys_get_temp_dir(), 'nestoenv');
        file_put_contents(self::$envFile, "NESTO_CM_PORT=3000\nNESTO_CM_ENV=dev\nNESTO_CM_URL=https://example.com\n");
    }

    public static function tearDownAfterClass(): void
    {
        unlink(self::$envFile);
    }

    protected function tearDown(): void
    {
        foreach (['NESTO_CM_PORT', 'NESTO_CM_ENV', 'NESTO_CM_URL', 'NESTO_CM_DEBUG', 'NESTO_CM_REQUIRED'] as $k) {
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
        }
    }

    public static function loadApp(): array
    {
        return ['app' => ['name' => 'Nesto', 'port' => (int) env('NESTO_CM_PORT', 80)]];
    }

    public function testModuleLoadsEnvRunsFactoriesAndValidates(): void
    {
        $app = NestoFactory::create(new \Nesto\Common\DynamicModule(
            module: ConfigModuleTestRoot::class,
            imports: [ConfigModule::forRoot([
                'isGlobal' => true,
                'envFilePath' => self::$envFile,
                'load' => [[self::class, 'loadApp'], 'database' => fn () => ['host' => 'db']],
                'validationSchema' => [
                    'NESTO_CM_PORT' => 'required|int|min:1|max:65535',
                    'NESTO_CM_ENV' => 'in:dev,prod',
                    'NESTO_CM_URL' => 'url',
                    'NESTO_CM_DEBUG' => 'bool|default:false',
                ],
            ])],
        ));
        $app->init();
        $config = $app->get(ConfigService::class);

        self::assertSame('Nesto', $config->get('app.name'));
        self::assertSame(3000, $config->get('app.port'));
        self::assertSame('db', $config->get('database.host'));
        self::assertSame(3000, $config->get('NESTO_CM_PORT'), 'validated value is cast to int');
        self::assertFalse($config->get('NESTO_CM_DEBUG'), 'default from the schema');
        self::assertSame('dev', $config->get('nesto.cm.env'));
        self::assertSame('https://example.com', getenv('NESTO_CM_URL'));
    }

    public function testAttributeStyleImportAndForFeature(): void
    {
        $app = NestoFactory::create(ConfigModuleAttributeRoot::class)->init();
        $config = $app->get(ConfigService::class);

        self::assertSame('attr', $config->get('from.attribute'));
        self::assertSame('feature', $config->get('mail.from'), 'forFeature() merged at init');
        self::assertSame('attr', $app->select(ConfigFeatureConsumer::class)->get(ConfigService::class)->get('from.attribute'));
    }

    public function testValidationFailuresAreReported(): void
    {
        try {
            SchemaValidator::validate([
                'A' => 'required',
                'B' => 'int',
                'C' => 'in:x,y',
                'D' => 'email',
                'E' => 'min:3',
                'F' => 'number|max:1',
            ], ['B' => 'nope', 'C' => 'z', 'D' => 'bad', 'E' => 'ab', 'F' => '2.5']);
            self::fail('expected exception');
        } catch (ConfigValidationException $e) {
            $errors = $e->errors();
            self::assertCount(6, $errors);
            self::assertStringContainsString('"A" is required', $errors[0]);
            self::assertStringContainsString('"B" must be an integer', $errors[1]);
            self::assertStringContainsString('"C" must be one of', $errors[2]);
            self::assertStringContainsString('"D" must be a valid email', $errors[3]);
            self::assertStringContainsString('"E" must be at least 3', $errors[4]);
            self::assertStringContainsString('"F" must be at most 1', $errors[5]);
        }

        self::assertSame(
            ['N' => 2.5, 'B' => true, 'S' => 'x'],
            SchemaValidator::validate(['N' => 'number', 'B' => 'bool', 'S' => 'string', 'OPT' => 'int'], ['N' => '2.5', 'B' => 'yes', 'S' => 'x'])
        );

        $this->expectException(ConfigValidationException::class);
        (new ConfigModule(ignoreEnvFile: true, validationSchema: ['NESTO_CM_REQUIRED' => 'required']))->buildService();
    }
}

#[Module]
final class ConfigModuleTestRoot {}

#[Module(imports: [
    new ConfigModule(isGlobal: true, ignoreEnvFile: true, load: [[ConfigModuleAttributeRoot::class, 'config']]),
    ConfigFeatureConsumer::class,
])]
final class ConfigModuleAttributeRoot
{
    public static function config(): array
    {
        return ['from' => ['attribute' => 'attr']];
    }
}

#[Module(imports: [[ConfigModule::class, 'forFeature', [['mail' => ['from' => 'feature']]]]])]
final class ConfigFeatureConsumer {}
