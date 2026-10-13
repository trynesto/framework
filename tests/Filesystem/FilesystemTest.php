<?php

declare(strict_types=1);

namespace Nesto\Tests\Filesystem;

use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use Nesto\Cli\Application;
use Nesto\Cli\Commands\BuiltIn;
use Nesto\Cli\Output;
use Nesto\Cli\ProjectContext;
use Nesto\Common\Attributes\Injectable;
use Nesto\Core\Paths;
use Nesto\Filesystem\Disk;
use Nesto\Filesystem\FilesystemManager;
use Nesto\Filesystem\FilesystemModule;
use Nesto\Filesystem\Storage;
use Nesto\Http\File;
use Nesto\Testing\Test;
use Nesto\Tests\Cli\GeneratorTest;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

/** A config-only custom driver: `'driver' => MemoryDriver::class`. */
final class MemoryDriver
{
    public function __invoke(array $config): InMemoryFilesystemAdapter
    {
        return new InMemoryFilesystemAdapter();
    }
}

#[Injectable]
final class AvatarService
{
    public function __construct(public readonly FilesystemManager $storage, public readonly Disk $default) {}
}

final class FilesystemTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private string $root;
    private FilesystemManager $manager;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/nesto-fs-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        file_put_contents($this->root . '/composer.json', '{}');
        Paths::setBase($this->root);
        $this->manager = FilesystemManager::fromConfig([
            'default' => 'local',
            'disks' => [
                'memory' => ['driver' => MemoryDriver::class],
                's3' => ['driver' => 's3', 'key' => 'k', 'secret' => 's', 'region' => 'eu-west-1', 'bucket' => 'media', 'url' => 'https://cdn.example.com'],
            ],
        ]);
        FilesystemManager::setInstance($this->manager);
    }

    protected function tearDown(): void
    {
        GeneratorTest::rrmdir($this->root);
        Paths::setBase(null);
        FilesystemManager::setInstance(null);
    }

    public function testDefaultDisksLiveInStorage(): void
    {
        $this->manager->disk()->put('notes/today.txt', 'hello');
        $this->manager->disk('public')->put('a.txt', 'public!');

        self::assertSame('hello', file_get_contents($this->root . '/storage/app/private/notes/today.txt'));
        self::assertSame('public!', file_get_contents($this->root . '/storage/app/public/a.txt'));
        self::assertSame('http://localhost:3000/storage/a.txt', $this->manager->disk('public')->url('a.txt'));
        self::assertSame($this->root . '/storage/app/public/a.txt', $this->manager->disk('public')->path('a.txt'));
    }

    public function testReadWriteListCopyMoveDelete(): void
    {
        $disk = $this->manager->disk();
        $disk->put('docs/a.txt', 'A');
        $disk->append('docs/a.txt', 'B');
        $disk->prepend('docs/a.txt', '0');
        $disk->put('docs/sub/b.txt', 'bb');
        $disk->copy('docs/a.txt', 'docs/c.txt');
        $disk->move('docs/c.txt', 'archive/c.txt');

        self::assertSame("0\nA\nB", $disk->get('docs/a.txt'));
        self::assertNull($disk->get('nope.txt'));
        self::assertTrue($disk->exists('archive/c.txt'));
        self::assertTrue($disk->missing('docs/c.txt'));
        self::assertSame(['docs/a.txt'], $disk->files('docs'));
        self::assertSame(['docs/a.txt', 'docs/sub/b.txt'], $disk->allFiles('docs'));
        self::assertSame(['archive', 'docs'], $disk->directories());
        self::assertSame(2, $disk->size('docs/sub/b.txt'));
        self::assertSame('text/plain', $disk->mimeType('docs/a.txt'));

        self::assertTrue($disk->delete(['docs/a.txt', 'archive/c.txt']));
        self::assertFalse($disk->exists('docs/a.txt'));
        $disk->deleteDirectory('docs');
        self::assertSame(['archive'], $disk->directories());
    }

    public function testUploadedFilesAreStoredWithRandomNamesAndStayReadable(): void
    {
        $file = $this->upload(base64_decode(self::PNG), 'me.PNG', 'image/png');

        $path = $file->store('avatars', 'public');
        self::assertMatchesRegularExpression('#^avatars/[0-9a-f]{32}\.png$#', $path);
        self::assertSame(base64_decode(self::PNG), Storage::disk('public')->get($path));
        self::assertSame('image/png', $file->mimeType(), 'the upload is still usable after store()');

        self::assertSame('avatars/me.png', $file->storeAs('avatars', 'me.png', 'memory'));
        self::assertTrue(Storage::disk('memory')->exists('avatars/me.png'));

        $this->expectException(\InvalidArgumentException::class);
        $file->storeAs('avatars', '../escape.png');
    }

    public function testUrlsForS3AndErrorsForDisksWithoutOne(): void
    {
        self::assertSame('https://cdn.example.com/avatars/x.png', $this->manager->disk('s3')->url('avatars/x.png'));

        $url = $this->manager->build(['driver' => 's3', 'key' => 'k', 'secret' => 's', 'region' => 'eu-west-1', 'bucket' => 'media'])->url('a b.png');
        self::assertSame('https://media.s3.eu-west-1.amazonaws.com/a%20b.png', $url);

        $presigned = $this->manager->disk('s3')->temporaryUrl('private/x.pdf', new \DateTimeImmutable('+5 minutes'));
        self::assertStringContainsString('X-Amz-Signature=', $presigned);
        self::assertStringContainsString('X-Amz-Expires=300', $presigned);

        $this->expectExceptionMessage('Disk [local] has no public URL');
        $this->manager->disk('local')->url('a.txt');
    }

    public function testS3UrlsKeepTheDiskRoot(): void
    {
        $s3 = ['driver' => 's3', 'key' => 'k', 'secret' => 's', 'region' => 'eu-west-1', 'bucket' => 'media'];

        $disk = $this->manager->build($s3 + ['root' => '/tenants/7/', 'url' => 'https://cdn.example.com/']);
        self::assertSame('https://cdn.example.com/tenants/7/avatars/x.png', $disk->url('avatars/x.png'));
        self::assertStringContainsString('/tenants/7/private/x.pdf?', $disk->temporaryUrl('private/x.pdf', new \DateTimeImmutable('+5 minutes')));

        // without "url" the driver builds it, also with the root
        self::assertSame('https://media.s3.eu-west-1.amazonaws.com/tenants/7/a.png', $this->manager->build($s3 + ['root' => 'tenants/7'])->url('a.png'));
        // "prefix" (older option) still wins over root for the url
        self::assertSame('https://cdn.example.com/p/a.png', $this->manager->build($s3 + ['root' => 'r', 'prefix' => 'p', 'url' => 'https://cdn.example.com'])->url('a.png'));
        // a local disk's root is a folder, never part of its url
        self::assertSame('http://localhost:3000/storage/a.png', $this->manager->disk('public')->url('a.png'));
    }

    public function testCustomDriversByExtendAndByInvokableClass(): void
    {
        $this->manager->extend('memory-ext', fn (array $config) => new InMemoryFilesystemAdapter());
        $disk = $this->manager->build(['driver' => 'memory-ext'], 'custom');
        $disk->put('x.txt', 'in memory');
        self::assertSame('in memory', $disk->get('x.txt'));

        $this->manager->disk('memory')->put('y.txt', 'class driver');
        self::assertSame('class driver', $this->manager->disk('memory')->get('y.txt'));
        self::assertFileDoesNotExist($this->root . '/storage/app/private/y.txt');

        $this->expectExceptionMessage('Disk [ftp] uses driver "ftp", which is not supported');
        $this->manager->build(['driver' => 'ftp'], 'ftp');
    }

    public function testFakeSwapsADiskForAnEmptyTestFolder(): void
    {
        $this->manager->disk('public')->put('real.txt', 'keep me');

        $fake = Storage::fake('public');
        $fake->put('test.txt', 'temporary');

        self::assertFalse($fake->exists('real.txt'));
        self::assertSame($this->root . '/storage/framework/testing/disks/public/test.txt', $fake->path('test.txt'));
        self::assertSame('http://localhost:3000/storage/test.txt', $fake->url('test.txt'), 'the fake keeps the url');
        self::assertSame('keep me', file_get_contents($this->root . '/storage/app/public/real.txt'));
    }

    public function testDownloadAndInlineResponses(): void
    {
        $this->manager->disk()->put('invoices/7.pdf', '%PDF-1.4 fake');

        $download = $this->manager->disk()->download('invoices/7.pdf', 'فاتورة 7.pdf');
        self::assertSame(200, $download->getStatusCode());
        self::assertSame('%PDF-1.4 fake', (string) $download->getBody());
        self::assertSame('13', $download->getHeaderLine('Content-Length'));
        self::assertStringStartsWith('attachment; filename="', $download->getHeaderLine('Content-Disposition'));
        self::assertStringContainsString("filename*=UTF-8''%D9%81%D8%A7%D8%AA%D9%88%D8%B1%D8%A9%207.pdf", $download->getHeaderLine('Content-Disposition'));

        self::assertStringStartsWith('inline;', $this->manager->disk()->response('invoices/7.pdf')->getHeaderLine('Content-Disposition'));
    }

    public function testModuleInjectsTheManagerAndTheDefaultDisk(): void
    {
        $module = Test::createTestingModule([
            'imports' => [new FilesystemModule(default: 'public')],
            'providers' => [AvatarService::class],
        ])->compile();

        $service = $module->get(AvatarService::class);
        self::assertSame('public', $service->default->name);
        self::assertSame($service->storage, FilesystemManager::instance(), 'Storage:: and File::store() use the same manager');
    }

    public function testFakeBeforeTheAppBootsSurvivesTheBoot(): void
    {
        FilesystemManager::setInstance(null);
        $fake = Storage::fake('public');

        $module = Test::createTestingModule([
            'imports' => [new FilesystemModule(default: 'public')],
            'providers' => [AvatarService::class],
        ])->compile();
        $service = $module->get(AvatarService::class);

        $service->default->put('a.txt', 'injected disk');
        $service->storage->disk('public')->put('b.txt', 'injected manager');
        Storage::disk('public')->put('c.txt', 'facade');

        $this->assertOnlyInTheFake($fake, ['a.txt', 'b.txt', 'c.txt']);
        self::assertSame($service->storage, FilesystemManager::instance());

        $module->close();
        self::assertNotSame($service->storage, FilesystemManager::instance(), 'closing the app forgets its manager and the fakes');
        self::assertStringStartsWith($this->root . '/storage/app/public', FilesystemManager::instance()->disk('public')->path());
    }

    public function testFakeAfterTheAppBootedReplacesDisksAlreadyInjected(): void
    {
        $module = Test::createTestingModule([
            'imports' => [new FilesystemModule(default: 'public')],
            'providers' => [AvatarService::class],
        ])->compile()->init();
        $service = $module->get(AvatarService::class);
        $service->storage->disk('public')->put('real.txt', 'keep me');

        $fake = Storage::fake('public');
        $service->default->put('a.txt', 'injected disk');
        $service->storage->disk('public')->put('b.txt', 'injected manager');
        Storage::disk('public')->put('c.txt', 'facade');

        $this->assertOnlyInTheFake($fake, ['a.txt', 'b.txt', 'c.txt']);
        self::assertFalse($fake->exists('real.txt'));
        self::assertSame('keep me', file_get_contents($this->root . '/storage/app/public/real.txt'));
        $module->close();
    }

    /** @param list<string> $files */
    private function assertOnlyInTheFake(Disk $fake, array $files): void
    {
        foreach ($files as $file) {
            self::assertTrue($fake->exists($file), "$file is on the fake disk");
            self::assertFileExists($this->root . '/storage/framework/testing/disks/public/' . $file);
            self::assertFileDoesNotExist($this->root . '/storage/app/public/' . $file, "$file never reaches the real disk");
        }
    }

    public function testStorageLinkCreatesAndRemovesThePublicSymlink(): void
    {
        $out = Output::memory();
        $app = (new Application('php nesto'))->setOutput($out)->setContext(new ProjectContext($this->root))->add(...BuiltIn::commands());

        self::assertSame(0, $app->run(['storage:link', '--relative', '--no-ansi']));
        self::assertTrue(is_link($this->root . '/public/storage'));
        self::assertSame('../storage/app/public', readlink($this->root . '/public/storage'));
        self::assertStringContainsString('LINK   public/storage → storage/app/public', $out->fetch());

        file_put_contents($this->root . '/storage/app/public/hello.txt', 'via link');
        self::assertSame('via link', file_get_contents($this->root . '/public/storage/hello.txt'));

        $app->run(['storage:link', '--no-ansi']);
        self::assertStringContainsString('already linked', $out->fetch());

        $app->run(['storage:unlink', '--no-ansi']);
        self::assertFalse(is_link($this->root . '/public/storage'));
        self::assertFileExists($this->root . '/storage/app/public/hello.txt', 'unlink never deletes the files');
    }

    public function testBasePathIsTheFolderOfTheRootModule(): void
    {
        Paths::setBase(null);
        mkdir($this->root . '/app/src', 0777, true);
        touch($this->root . '/app/nesto.config.php');
        Paths::detectFrom($this->root . '/app/src/AppModule.php');

        self::assertSame($this->root . '/app', base_path());
        self::assertSame($this->root . '/app/storage/logs', storage_path('logs'));
        self::assertSame($this->root . '/app/public', public_path());
        self::assertSame('/etc/x', Paths::resolve('/etc/x'));
    }

    private function upload(string $contents, string $name, string $mime): File
    {
        $f = new Psr17Factory;

        return new File($f->createUploadedFile($f->createStream($contents), strlen($contents), UPLOAD_ERR_OK, $name, $mime), 'avatar');
    }
}
