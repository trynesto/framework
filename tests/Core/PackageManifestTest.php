<?php

declare(strict_types=1);

namespace Nesto\Tests\Core;

use Nesto\Core\Packages\PackageManifest;
use Nesto\Tests\Cli\GeneratorTest;
use PHPUnit\Framework\TestCase;

final class PackageManifestTest extends TestCase
{
    private string $vendor;

    protected function setUp(): void
    {
        $this->vendor = sys_get_temp_dir() . '/nesto-manifest-' . bin2hex(random_bytes(4)) . '/vendor';
        mkdir($this->vendor . '/composer', 0777, true);
        mkdir($this->vendor . '/acme/mail/config', 0777, true);
        PackageManifest::flush();
    }

    protected function tearDown(): void
    {
        GeneratorTest::rrmdir(dirname($this->vendor));
        PackageManifest::flush();
    }

    public function testCollectsOnlyPackagesThatDeclareExtraNesto(): void
    {
        $this->installed([
            ['name' => 'acme/mail', 'install-path' => '../acme/mail', 'extra' => ['nesto' => [
                'module' => 'Acme\\Mail\\MailModule',
                'commands' => ['Acme\\Mail\\Commands\\MailTestCommand'],
                'config' => ['mail' => 'config/mail.php'],
            ]]],
            ['name' => 'acme/multi', 'install-path' => '../acme/multi', 'extra' => ['nesto' => ['modules' => ['A\\One', 'A\\Two']]]],
            ['name' => 'laravel/thing', 'install-path' => '../laravel/thing', 'extra' => ['laravel' => ['providers' => ['X']]]],
            ['name' => 'psr/log', 'install-path' => '../psr/log'],
        ]);

        $manifest = PackageManifest::build($this->vendor);

        self::assertSame(['acme/mail', 'acme/multi'], array_keys($manifest->packages));
        $mail = $manifest->find('ACME/Mail');
        self::assertSame(['Acme\\Mail\\MailModule'], $mail['modules']);
        self::assertSame(realpath($this->vendor . '/acme/mail'), $mail['path']);
        self::assertSame(['A\\One', 'A\\Two'], $manifest->find('acme/multi')['modules']);
        self::assertSame(['Acme\\Mail\\Commands\\MailTestCommand'], $manifest->commands());
        self::assertSame(['mail' => realpath($this->vendor . '/acme/mail') . '/config/mail.php'], $manifest->configFiles());
        self::assertNull($manifest->find('laravel/thing'));
    }

    public function testCacheIsWrittenAndRebuiltWhenComposerChangesTheInstallation(): void
    {
        $this->installed([['name' => 'acme/one', 'install-path' => '../acme/one', 'extra' => ['nesto' => ['module' => 'One']]]]);
        self::assertSame(['acme/one'], array_keys(PackageManifest::forProject($this->vendor)->packages));
        self::assertFileExists($this->vendor . '/nesto-packages.php');

        // composer require another package: installed.json becomes newer than the cache
        $this->installed([
            ['name' => 'acme/one', 'install-path' => '../acme/one', 'extra' => ['nesto' => ['module' => 'One']]],
            ['name' => 'acme/two', 'install-path' => '../acme/two', 'extra' => ['nesto' => ['module' => 'Two']]],
        ]);
        touch($this->vendor . '/composer/installed.json', time() + 5);
        PackageManifest::flush();

        self::assertSame(['acme/one', 'acme/two'], array_keys(PackageManifest::forProject($this->vendor)->packages));
    }

    public function testMissingInstalledJsonMeansNoPackages(): void
    {
        self::assertSame([], PackageManifest::build($this->vendor)->packages);
    }

    /** @param list<array<string, mixed>> $packages */
    private function installed(array $packages): void
    {
        file_put_contents($this->vendor . '/composer/installed.json', json_encode(['packages' => $packages, 'dev' => true]));
    }
}
