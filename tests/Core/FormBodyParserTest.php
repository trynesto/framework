<?php

declare(strict_types=1);

namespace Nesto\Tests\Core\FormBodyFixtures;

use Nesto\Common\Attributes\Body;
use Nesto\Common\Attributes\Controller;
use Nesto\Common\Attributes\Module;
use Nesto\Common\Attributes\Patch;
use Nesto\Common\Attributes\UploadedFile;
use Nesto\Http\File;
use Nesto\Validation\Attributes\IsImage;
use Nesto\Validation\Attributes\IsString;

final class UpdatePhotoDto
{
    #[IsString]
    public string $title;

    #[IsImage(maxSize: '1KB')]
    public File $image;
}

#[Controller('photos')]
final class PhotosController
{
    #[Patch('{id}')]
    public function update(#[Body] UpdatePhotoDto $dto): array
    {
        return ['title' => $dto->title, 'image' => $dto->image->originalName(), 'mime' => $dto->image->mimeType()];
    }

    #[Patch('{id}/avatar')]
    public function avatar(#[UploadedFile('avatar', image: true)] File $avatar): array
    {
        return ['avatar' => $avatar->originalName(), 'size' => $avatar->size()];
    }
}

#[Module(controllers: [PhotosController::class])]
final class PhotosModule {}

namespace Nesto\Tests\Core;

use Nesto\Core\NestoFactory;
use Nesto\Http\FormBodyParser;
use Nesto\Http\Request;
use Nesto\Tests\Core\FormBodyFixtures\PhotosModule;
use Nesto\Validation\ValidationPipe;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use PHPUnit\Framework\TestCase;

/** PUT/PATCH form bodies, which PHP leaves out of $_POST/$_FILES (Request::fromGlobals() parses them). */
final class FormBodyParserTest extends TestCase
{
    private const PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89\0\0\0\rIDATx\x9cc\xf8\x0f\0\0\x01\x01\0\x05\x18\xd8N\0\0\0\0IEND\xaeB`\x82";

    /** @param array<string, string|array{0: string, 1: string, 2?: string}> $parts name => value | [filename, content, type] */
    private static function multipart(array $parts, string $boundary = 'XyZ'): string
    {
        $body = '';
        foreach ($parts as $name => $value) {
            $name = preg_replace('/#\d+$/', '', (string) $name);
            $body .= "--$boundary\r\n";
            if (is_array($value)) {
                $body .= "Content-Disposition: form-data; name=\"$name\"; filename=\"{$value[0]}\"\r\nContent-Type: " . ($value[2] ?? 'application/octet-stream') . "\r\n\r\n{$value[1]}\r\n";
            } else {
                $body .= "Content-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
            }
        }

        return $body . "--$boundary--\r\n";
    }

    public function test_parses_fields_and_files_like_php(): void
    {
        $body = self::multipart([
            'title' => 'Sunset',
            'meta[tags][]#1' => 'a',
            'meta[tags][]#2' => 'b',
            'image' => ['sun.png', self::PNG, 'image/png'],
            'photos[]#1' => ['a.txt', "line\n\n"],
            'photos[]#2' => ['b.txt', 'two'],
            'empty' => ['', ''],
        ]);

        [$post, $files] = FormBodyParser::parse($body, 'multipart/form-data; boundary=XyZ');

        self::assertSame(['title' => 'Sunset', 'meta' => ['tags' => ['a', 'b']]], $post);
        self::assertSame('sun.png', $files['image']['name']);
        self::assertSame('image/png', $files['image']['type']);
        self::assertSame(UPLOAD_ERR_OK, $files['image']['error']);
        self::assertSame(self::PNG, file_get_contents($files['image']['tmp_name']), 'binary content kept byte for byte');
        self::assertSame(strlen(self::PNG), $files['image']['size']);
        self::assertSame("line\n\n", file_get_contents($files['photos'][0]['tmp_name']), 'trailing newlines of the content kept');
        self::assertSame('two', file_get_contents($files['photos'][1]['tmp_name']));
        self::assertSame(UPLOAD_ERR_NO_FILE, $files['empty']['error']);
    }

    public function test_urlencoded_and_quoted_boundary(): void
    {
        self::assertSame([['a' => '1', 'b' => ['x' => '2']], []], FormBodyParser::parse('a=1&b%5Bx%5D=2', 'application/x-www-form-urlencoded'));
        [$post] = FormBodyParser::parse(self::multipart(['name' => 'tom'], 'b-1'), 'multipart/form-data; boundary="b-1"');
        self::assertSame(['name' => 'tom'], $post);
        self::assertSame([[], []], FormBodyParser::parse('garbage', 'multipart/form-data'));
    }

    public function test_patch_upload_reaches_dto_validation_and_uploaded_file(): void
    {
        $app = NestoFactory::create(PhotosModule::class)->useGlobalPipes(new ValidationPipe(transform: true));
        $patch = function (string $uri, string $body) use ($app) {
            [$post, $files] = FormBodyParser::parse($body, 'multipart/form-data; boundary=XyZ');
            $creator = new ServerRequestCreator($f = new Psr17Factory, $f, $f, $f);
            $psr = $creator->fromArrays(['REQUEST_METHOD' => 'PATCH', 'REQUEST_URI' => $uri, 'HTTP_HOST' => 'localhost'], ['Content-Type' => 'multipart/form-data; boundary=XyZ'], [], [], $post, $files, $body);

            return $app->handle(new Request($psr));
        };

        $r = $patch('/photos/1', self::multipart(['title' => 'Sunset', 'image' => ['sun.png', self::PNG, 'image/png']]));
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());
        self::assertSame(['title' => 'Sunset', 'image' => 'sun.png', 'mime' => 'image/png'], json_decode((string) $r->getBody(), true));

        $r = $patch('/photos/1', self::multipart(['title' => 'Bad', 'image' => ['evil.png', '<?php echo 1;', 'image/png']]));
        self::assertSame(400, $r->getStatusCode(), '#[IsImage] checks the content on PATCH too');
        self::assertArrayHasKey('image', json_decode((string) $r->getBody(), true)['errors']);

        $r = $patch('/photos/1/avatar', self::multipart(['avatar' => ['me.png', self::PNG, 'image/png']]));
        self::assertSame(['avatar' => 'me.png', 'size' => strlen(self::PNG)], json_decode((string) $r->getBody(), true));
    }
}
