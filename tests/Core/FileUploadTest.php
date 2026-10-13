<?php

declare(strict_types=1);

namespace Nesto\Tests\Core;

use Nesto\Common\Attributes\Body;
use Nesto\Common\Attributes\Controller;
use Nesto\Common\Attributes\Post;
use Nesto\Common\Attributes\UploadedFile;
use Nesto\Common\Attributes\UploadedFiles;
use Nesto\Common\Pipes\Files\FileTypeValidator;
use Nesto\Common\Pipes\Files\MaxFileSizeValidator;
use Nesto\Common\Pipes\ParseFilePipe;
use Nesto\Core\NestoFactory;
use Nesto\Filesystem\FilesystemManager;
use Nesto\Http\File;
use Nesto\Http\Request;
use Nesto\Swagger\DocumentBuilder;
use Nesto\Swagger\SwaggerModule;
use Nesto\Testing\Test;
use Nesto\Testing\TestingModule;
use Nesto\Tests\Cli\GeneratorTest;
use Nesto\Validation\Attributes\IsString;
use Nesto\Validation\Attributes\Length;
use Nesto\Validation\ValidationPipe;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class ProfileDto
{
    #[IsString, Length(min: 2)]
    public string $name;
}

#[Controller('uploads')]
final class UploadsController
{
    public static string $dir = '';

    #[Post('avatar')]
    public function avatar(
        #[UploadedFile('avatar', new ParseFilePipe(validators: [
            new MaxFileSizeValidator(1024),
            new FileTypeValidator('image/png|image/jpeg'),
        ]))] File $avatar,
    ): array {
        return [
            'field' => $avatar->fieldName,
            'original' => $avatar->originalName(),
            'mime' => $avatar->mimeType(),
            'extension' => $avatar->extension(),
            'size' => $avatar->size(),
            'dimensions' => $avatar->dimensions(),
            'path' => $avatar->store('avatars', 'uploads'),
        ];
    }

    #[Post('gallery')]
    public function gallery(#[UploadedFiles('photos', new ParseFilePipe(validators: [new FileTypeValidator('image/*')], maxCount: 2))] array $photos): array
    {
        return array_map(static fn (File $f) => $f->originalName(), $photos);
    }

    #[Post('optional')]
    public function optional(#[UploadedFile('doc')] ?File $doc = null): array
    {
        return ['received' => $doc?->originalName()];
    }

    #[Post('profile')]
    public function profile(#[Body] ProfileDto $dto, #[UploadedFile('avatar')] File $avatar): array
    {
        return ['name' => $dto->name, 'avatar' => $avatar->originalName()];
    }
}

final class FileUploadTest extends TestCase
{
    /** 1×1 transparent PNG */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private TestingModule $module;

    protected function setUp(): void
    {
        UploadsController::$dir = sys_get_temp_dir() . '/nesto-uploads-' . bin2hex(random_bytes(4));
        FilesystemManager::setInstance(FilesystemManager::fromConfig(['disks' => ['uploads' => ['driver' => 'local', 'root' => UploadsController::$dir]]]));
        $this->module = Test::createTestingModule(['controllers' => [UploadsController::class]])->compile();
        $this->module->app()->useGlobalPipes(new ValidationPipe(whitelist: true, transform: true));
    }

    protected function tearDown(): void
    {
        GeneratorTest::rrmdir(UploadsController::$dir);
        FilesystemManager::setInstance(null);
    }

    public function testSingleFileIsValidatedInspectedAndStored(): void
    {
        $response = $this->module->request()->post('/uploads/avatar')
            ->attach('avatar', base64_decode(self::PNG), 'Me Photo.PNG', 'image/png')
            ->expect(201)->json();

        self::assertSame('avatar', $response['field']);
        self::assertSame('Me Photo.PNG', $response['original']);
        self::assertSame('image/png', $response['mime']);
        self::assertSame('png', $response['extension']);
        self::assertSame(['width' => 1, 'height' => 1], $response['dimensions']);
        self::assertMatchesRegularExpression('#^avatars/[0-9a-f]{32}\.png$#', $response['path']);
        self::assertSame(base64_decode(self::PNG), file_get_contents(UploadsController::$dir . '/' . $response['path']));
    }

    public function testTypeIsDetectedFromContentNotFromTheClient(): void
    {
        $this->module->request()->post('/uploads/avatar')
            ->attach('avatar', '<?php system($_GET["c"]);', 'shell.jpg', 'image/jpeg')
            ->expect(400)
            ->assertJsonPath('errors', ['avatar' => ['avatar must be a file of type: image/png, image/jpeg']]);
    }

    public function testSizeLimitAndRequiredFile(): void
    {
        $this->module->request()->post('/uploads/avatar')
            ->attach('avatar', base64_decode(self::PNG) . str_repeat("\0", 2048), 'big.png', 'image/png')
            ->expect(400)->assertJsonPath('errors', ['avatar' => ['avatar must not be larger than 1 KB']]);

        $this->module->request()->post('/uploads/avatar')->field('nothing', 'here')
            ->expect(400)->assertJsonPath('errors', ['avatar' => ['avatar is required']]);
    }

    public function testMultipleFilesWithMaxCount(): void
    {
        $png = base64_decode(self::PNG);
        $this->module->request()->post('/uploads/gallery')
            ->attach('photos', $png, 'a.png', 'image/png')->attach('photos', $png, 'b.png', 'image/png')
            ->expect(201)->assertJson(['a.png', 'b.png']);

        $this->module->request()->post('/uploads/gallery')
            ->attach('photos', $png, 'a.png')->attach('photos', $png, 'b.png')->attach('photos', $png, 'c.png')
            ->expect(400)->assertJsonPath('errors', ['photos' => ['photos must not have more than 2 files']]);
    }

    public function testOptionalFileAndDtoTogetherWithFiles(): void
    {
        $this->module->request()->post('/uploads/optional')->field('x', '1')->expect(201)->assertJson(['received' => null]);

        $this->module->request()->post('/uploads/profile')
            ->field('name', 'Hussein')->attach('avatar', base64_decode(self::PNG), 'me.png', 'image/png')
            ->expect(201)->assertJson(['name' => 'Hussein', 'avatar' => 'me.png']);

        $this->module->request()->post('/uploads/profile')
            ->field('name', 'H')->attach('avatar', base64_decode(self::PNG), 'me.png')
            ->expect(400);
    }

    public function testServerUploadLimitsBecome413(): void
    {
        $factory = new Psr17Factory;
        $tooBig = $factory->createUploadedFile($factory->createStream(''), 0, UPLOAD_ERR_INI_SIZE, 'huge.png', 'image/png');
        $request = new Request(Request::create('POST', '/uploads/optional', [], null, ['Content-Type' => 'multipart/form-data'])->psr()->withUploadedFiles(['doc' => $tooBig]));

        $response = $this->module->app()->handle($request);

        self::assertSame(413, $response->getStatusCode());
        self::assertSame(['doc' => ['doc must not be larger than ' . ini_get('upload_max_filesize') . ' (upload_max_filesize)']], json_decode((string) $response->getBody(), true)['errors']);
    }

    public function testSwaggerDescribesUploadsAsMultipartBinary(): void
    {
        $doc = SwaggerModule::createDocument(NestoFactory::create(FileUploadSwaggerModule::class), (new DocumentBuilder)->setTitle('t')->build());

        $avatar = $doc['paths']['/uploads/avatar']['post']['requestBody']['content']['multipart/form-data']['schema'];
        self::assertSame(['type' => 'string', 'format' => 'binary'], $avatar['properties']['avatar']);
        self::assertSame(['avatar'], $avatar['required']);

        $gallery = $doc['paths']['/uploads/gallery']['post']['requestBody']['content']['multipart/form-data']['schema'];
        self::assertSame(['type' => 'array', 'items' => ['type' => 'string', 'format' => 'binary']], $gallery['properties']['photos[]'], 'lists are sent as photos[] so PHP keeps every file');

        $profile = $doc['paths']['/uploads/profile']['post']['requestBody']['content']['multipart/form-data']['schema'];
        self::assertSame(['name', 'avatar'], array_keys($profile['properties']), 'DTO fields and file fields in one form');
        self::assertSame(['type' => 'string', 'format' => 'binary'], $profile['properties']['avatar']);
    }
}

#[\Nesto\Common\Attributes\Module(controllers: [UploadsController::class])]
final class FileUploadSwaggerModule {}
