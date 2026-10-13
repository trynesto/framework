<?php

declare(strict_types=1);

namespace Nesto\Tests\Config;

use Nesto\Config\ConfigModule;
use Nesto\Core\Packages\PackageManifest;
use Nesto\Tests\Cli\GeneratorTest;
use PHPUnit\Framework\TestCase;

final class ConfigFilesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/nesto-config-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/vendor/composer', 0777, true);
        mkdir($this->root . '/vendor/acme/mail/config', 0777, true);
        mkdir($this->root . '/config', 0777, true);
        file_put_contents($this->root . '/vendor/composer/installed.json', json_encode(['packages' => [
            ['name' => 'acme/mail', 'install-path' => '../acme/mail', 'extra' => ['nesto' => ['config' => ['mail' => 'config/mail.php']]]],
        ]]));
        $this->php('vendor/acme/mail/config/mail.php', [
            'driver' => 'log',
            'from' => ['address' => 'noreply@acme.test', 'name' => 'Acme'],
            'channels' => ['log', 'slack'],
        ]);
        PackageManifest::flush();
    }

    protected function tearDown(): void
    {
        GeneratorTest::rrmdir($this->root);
        PackageManifest::flush();
        putenv('NESTO_CFG_DRIVER');
    }

    public function testPackageDefaultsAreAvailableWithoutPublishing(): void
    {
        $config = $this->module()->buildService();

        self::assertSame('log', $config->get('mail.driver'));
        self::assertSame('Acme', $config->get('mail.from.name'));
    }

    public function testProjectConfigOverridesPackageDefaultsKeyByKey(): void
    {
        $this->php('config/mail.php', ['from' => ['name' => 'Blog'], 'channels' => ['sms']]);
        $this->php('config/app.php', ['name' => 'Blog API']);

        $config = $this->module()->buildService();

        self::assertSame('Blog', $config->get('mail.from.name'));
        self::assertSame('noreply@acme.test', $config->get('mail.from.address'), 'untouched keys keep the package default');
        self::assertSame(['sms'], $config->get('mail.channels'), 'lists are replaced, not merged by index');
        self::assertSame('Blog API', $config->get('app.name'));
    }

    public function testConfigFilesReadTheEnvironment(): void
    {
        file_put_contents($this->root . '/config/mail.php', "<?php\nreturn ['driver' => env('NESTO_CFG_DRIVER', 'log')];\n");
        putenv('NESTO_CFG_DRIVER=array');

        self::assertSame('array', $this->module()->buildService()->get('mail.driver'));
    }

    public function testLoadFactoriesWinAndBothSourcesCanBeDisabled(): void
    {
        $this->php('config/mail.php', ['driver' => 'smtp']);

        $config = $this->module(load: ['mail' => fn () => ['driver' => 'array']])->buildService();
        self::assertSame('array', $config->get('mail.driver'));

        $none = (new ConfigModule(ignoreEnvFile: true, configPath: false, packageConfig: false, vendorDir: $this->root . '/vendor'))->buildService();
        self::assertNull($none->get('mail.driver'));
    }

    private function module(array $load = []): ConfigModule
    {
        return new ConfigModule(ignoreEnvFile: true, load: $load, vendorDir: $this->root . '/vendor');
    }

    private function php(string $relative, array $values): void
    {
        file_put_contents($this->root . '/' . $relative, '<?php return ' . var_export($values, true) . ';');
    }
}
