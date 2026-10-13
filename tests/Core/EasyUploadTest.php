<?php

declare(strict_types=1);

namespace Nesto\Tests\Core;

use Nesto\Common\Attributes\Body;
use Nesto\Common\Attributes\Controller;
use Nesto\Common\Attributes\Get;
use Nesto\Common\Attributes\Module;
use Nesto\Common\Attributes\Param;
use Nesto\Common\Attributes\Post;
use Nesto\Common\Attributes\UploadedFile;
use Nesto\Common\Attributes\UploadedFiles;
use Nesto\Common\Exceptions\ValidationException;
use Nesto\Core\NestoFactory;
use Nesto\Http\File;
use Nesto\Http\FileRules;
use Nesto\Swagger\DocumentBuilder;
use Nesto\Swagger\SwaggerModule;
use Nesto\Testing\Test;
use Nesto\Testing\TestingModule;
use Nesto\Validation\Attributes\IsFile;
use Nesto\Validation\Attributes\IsImage;
use Nesto\Validation\Attributes\IsNotEmpty;
use Nesto\Validation\Attributes\IsOptional;
use Nesto\Validation\Attributes\IsString;
use Nesto\Validation\ValidationPipe;
use PHPUnit\Framework\TestCase;

final class CreateArticleDto
{
    #[IsString, IsNotEmpty]
    public string $title;

    #[IsImage(maxSize: '1KB')]
    public File $cover;

    #[IsOptional, IsFile(types: ['pdf', 'txt'])]
    public ?File $attachment = null;

    /** @var list<File> */
    #[IsOptional, IsImage(maxFiles: 2)]
    public array $gallery = [];
}

#[Controller('articles')]
final class ArticlesController
{
    #[Post]
    public function create(#[Body] CreateArticleDto $dto): array
    {
        return [
            'title' => $dto->title,
            'cover' => $dto->cover->originalName(),
            'attachment' => $dto->attachment?->originalName(),
            'gallery' => array_map(static fn (File $f) => $f->originalName(), $dto->gallery),
        ];
    }

    #[Post('avatar')]
    public function avatar(#[UploadedFile('avatar', image: true, maxSize: '1KB')] File $avatar): array
    {
        return ['avatar' => $avatar->originalName()];
    }

    #[Post('optional')]
    public function optional(#[UploadedFile('doc', types: 'pdf')] ?File $doc = null): array
    {
        return ['doc' => $doc?->originalName()];
    }

    #[Post('photos')]
    public function photos(#[UploadedFiles('photos', image: true, maxFiles: 2)] array $photos): array
    {
        return ['count' => count($photos)];
    }

    #[Get('{id}')]
    public function show(#[Param('id')] int $id): array
    {
        return ['id' => $id];
    }
}

#[Module(controllers: [ArticlesController::class])]
final class ArticlesModule {}

final class EasyUploadTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private TestingModule $module;

    protected function setUp(): void
    {
        $this->module = Test::createTestingModule(['controllers' => [ArticlesController::class]])->compile();
        $this->module->app()->useGlobalPipes(new ValidationPipe(whitelist: true, transform: true));
    }

    protected function tearDown(): void
    {
        ValidationException::$defaultStatus = 400;
    }

    public function testFilesLiveInTheDtoNextToTheTextFields(): void
    {
        $png = base64_decode(self::PNG);
        $this->module->request()->post('/articles')
            ->field('title', 'Hello')
            ->attach('cover', $png, 'cover.png')
            ->attach('attachment', 'plain notes', 'notes.txt')
            ->attach('gallery[]', $png, 'a.png')
            ->attach('gallery[]', $png, 'b.png')
            ->expect(201)
            ->assertJson(['title' => 'Hello', 'cover' => 'cover.png', 'attachment' => 'notes.txt', 'gallery' => ['a.png', 'b.png']]);

        $this->module->request()->post('/articles')->field('title', 'No extras')->attach('cover', $png, 'c.png')
            ->expect(201)->assertJson(['attachment' => null, 'gallery' => []]);

        // an empty list sent as `gallery[]=` (Swagger UI, form libraries) counts as not sent
        $this->module->request()->post('/articles')->field('title', 'Empty list')->attach('cover', $png, 'c.png')->field('gallery[]', '')
            ->expect(201)->assertJson(['gallery' => []]);

        // one file sent as "gallery" (no []) still lands in the list
        $this->module->request()->post('/articles')->field('title', 'One')->attach('cover', $png, 'c.png')->attach('gallery', $png, 'only.png')
            ->expect(201)->assertJson(['gallery' => ['only.png']]);
    }

    public function testEveryProblemIsReportedPerFieldInOneResponse(): void
    {
        $png = base64_decode(self::PNG);
        $response = $this->module->request()->post('/articles')
            ->attach('cover', '<?php echo 1;', 'cover.png', 'image/png')
            ->attach('attachment', $png, 'x.png')
            ->attach('gallery[]', $png, 'a.png')->attach('gallery[]', $png, 'b.png')->attach('gallery[]', $png, 'c.png')
            ->expect(400)->json();

        self::assertSame([
            'title' => ['title must be a string', 'title should not be empty'],
            'cover' => ['cover must be an image (jpg, png, gif, webp, avif)'],
            'attachment' => ['attachment must be a file of type: pdf, txt'],
            'gallery' => ['gallery must not have more than 2 files'],
        ], $response['errors']);
        self::assertSame('title must be a string (and 4 more errors)', $response['message']);

        $missing = $this->module->request()->post('/articles')->field('title', 'x')->expect(400)->json();
        self::assertSame(['cover' => ['cover is required']], $missing['errors']);

        $big = $this->module->request()->post('/articles')->field('title', 'x')
            ->attach('cover', $png . str_repeat("\0", 2048), 'big.png')->expect(400)->json();
        self::assertSame(['cover' => ['cover must not be larger than 1 KB']], $big['errors']);
    }

    public function testShorthandRulesOnTheParameter(): void
    {
        $png = base64_decode(self::PNG);
        $this->module->request()->post('/articles/avatar')->attach('avatar', $png, 'me.png')->expect(201)->assertJson(['avatar' => 'me.png']);
        $this->module->request()->post('/articles/avatar')->attach('avatar', 'just some text', 'me.png', 'image/png')
            ->expect(400)->assertJsonPath('errors', ['avatar' => ['avatar must be an image (jpg, png, gif, webp, avif)']]);
        $this->module->request()->post('/articles/avatar')->field('x', '1')
            ->expect(400)->assertJsonPath('errors', ['avatar' => ['avatar is required']]);

        $this->module->request()->post('/articles/optional')->field('x', '1')->expect(201)->assertJson(['doc' => null]);
        $this->module->request()->post('/articles/optional')->attach('doc', $png, 'x.png')
            ->expect(400)->assertJsonPath('errors', ['doc' => ['doc must be a file of type: pdf']]);

        $this->module->request()->post('/articles/photos')->attach('photos[]', $png, 'a.png')->attach('photos[]', $png, 'b.png')->attach('photos[]', $png, 'c.png')
            ->expect(400)->assertJsonPath('errors', ['photos' => ['photos must not have more than 2 files']]);
    }

    public function testRouteParamsUseTheSameShapeAnd422IsOneOption(): void
    {
        $this->module->request()->get('/articles/abc')->expect(400)
            ->assertJson(['message' => 'id must be an integer', 'errors' => ['id' => ['id must be an integer']], 'error' => 'Bad Request']);

        $this->module->app()->useGlobalPipes(new ValidationPipe(errorHttpStatusCode: 422));
        $this->module->request()->get('/articles/abc')->expect(422)->assertJson(['error' => 'Unprocessable Entity']);
    }

    public function testSwaggerSendsFileDtosAsMultipart(): void
    {
        $doc = SwaggerModule::createDocument(NestoFactory::create(ArticlesModule::class), (new DocumentBuilder)->setTitle('t')->build());

        $content = $doc['paths']['/articles']['post']['requestBody']['content'];
        self::assertSame(['multipart/form-data'], array_keys($content));
        $form = $content['multipart/form-data']['schema'];
        self::assertSame(['title', 'cover', 'attachment', 'gallery[]'], array_keys($form['properties']));
        self::assertSame(['title', 'cover'], $form['required']);
        $props = $doc['components']['schemas']['CreateArticleDto']['properties'];
        self::assertSame(['type' => 'string', 'format' => 'binary', 'description' => 'image; max 1KB'], $props['cover']);
        self::assertSame(['type' => 'array', 'items' => ['type' => 'string', 'format' => 'binary'], 'description' => 'image; up to 2 files'], $props['gallery']);
        self::assertSame('binary', $props['attachment']['format']);
    }

    public function testOfficeTextAndMisconfiguredRules(): void
    {
        $docx = new FileRules(types: ['pdf', 'docx']);
        self::assertSame([], $docx->check(self::file((string) file_get_contents(__DIR__ . '/fixtures/sample.docx'), 'cv.docx'), 'cv'));
        self::assertSame(['cv must be a file of type: pdf, docx'], $docx->check(self::file('PK just a zip?', 'cv.docx'), 'cv'));

        $csv = new FileRules(types: ['csv', 'txt']);
        self::assertSame([], $csv->check(self::file("name,age\nAli,3\n", 'people.csv'), 'sheet'), 'text formats are recognised by name');
        self::assertSame(['sheet must be a file of type: csv, txt'], $csv->check(self::file('<?php system($x);', 'people.csv'), 'sheet'), 'a PHP file is not text/plain');

        // never stored with an executable extension, whatever the client calls it
        self::assertSame('txt', self::file('<?php system($x);', 'shell.php')->extension());
        self::assertSame('bin', self::file("\x00\x01binary", 'run.phtml')->extension());
        self::assertSame('bin', self::file("\x00\x01binary", 'noext')->extension());
        self::assertSame('png', self::file(base64_decode(self::PNG), 'trick.php')->extension());

        $this->expectExceptionMessage("IsImage only accepts image types, got: docx. Use #[IsFile(types: ['jpg', 'docx'])] for other files.");
        (new FileRules(types: ['jpg', 'docx'], image: true))->check(self::file('x', 'a.docx'), 'image');
    }

    private static function file(string $contents, string $name): File
    {
        $f = new \Nyholm\Psr7\Factory\Psr17Factory;

        return new File($f->createUploadedFile($f->createStream($contents), strlen($contents), UPLOAD_ERR_OK, $name, 'application/octet-stream'), 'f');
    }

    public function testSizesAndTypes(): void
    {
        self::assertSame(5 * 1024 * 1024, FileRules::bytes('5MB'));
        self::assertSame(512 * 1024, FileRules::bytes('512 kb'));
        self::assertSame(1536, FileRules::bytes('1.5K'));
        self::assertSame(2048, FileRules::bytes(2048));
        self::assertSame('1.5 MB', FileRules::human(1572864));
    }
}
