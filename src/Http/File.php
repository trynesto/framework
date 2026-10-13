<?php

declare(strict_types=1);

namespace Nesto\Http;

use Nesto\Filesystem\FilesystemManager;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * An uploaded file, as injected by #[UploadedFile] / #[UploadedFiles] (Nest's Express.Multer.File).
 *
 *   #[Post('avatar')]
 *   public function avatar(#[UploadedFile('avatar')] File $avatar): array
 *   {
 *       $path = $avatar->store('avatars', 'public');   // "avatars/3f9c…e1.jpg" on the public disk
 *   }
 *
 * mimeType() and extension() come from the file's content, not from what the client claims: a PHP script
 * renamed to photo.jpg is detected as text/x-php, fails an image FileTypeValidator and is never stored as .jpg.
 */
final class File
{
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/avif' => 'avif',
        'image/svg+xml' => 'svg', 'image/bmp' => 'bmp', 'image/x-icon' => 'ico', 'image/vnd.microsoft.icon' => 'ico',
        'image/heic' => 'heic', 'image/heif' => 'heif', 'image/tiff' => 'tiff',
        'application/pdf' => 'pdf', 'application/zip' => 'zip', 'application/json' => 'json',
        'text/plain' => 'txt', 'text/csv' => 'csv', 'text/html' => 'html', 'text/xml' => 'xml', 'application/xml' => 'xml',
        'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov', 'audio/mpeg' => 'mp3', 'audio/ogg' => 'ogg',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/msword' => 'doc', 'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx', 'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.oasis.opendocument.text' => 'odt', 'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
        'application/rtf' => 'rtf', 'text/rtf' => 'rtf', 'text/markdown' => 'md', 'application/x-7z-compressed' => '7z',
        'application/x-rar' => 'rar', 'application/vnd.rar' => 'rar', 'application/gzip' => 'gz', 'application/x-gzip' => 'gz',
        'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'video/x-msvideo' => 'avi',
    ];

    private ?string $detectedMime = null;

    public function __construct(private readonly UploadedFileInterface $file, public readonly string $fieldName = '') {}

    /** The name the client sent, e.g. "my photo.JPG". Never use it as a storage path. */
    public function originalName(): string
    {
        return (string) $this->file->getClientFilename();
    }

    /** MIME type detected from the content (finfo), falling back to the client's claim. */
    public function mimeType(): string
    {
        if ($this->detectedMime === null) {
            $detected = class_exists(\finfo::class) ? (new \finfo(FILEINFO_MIME_TYPE))->buffer($this->head()) : false;
            $this->detectedMime = is_string($detected) && $detected !== '' ? $detected : $this->clientMimeType();
        }

        return $this->detectedMime;
    }

    /** The Content-Type the client sent for this part (untrusted). */
    public function clientMimeType(): string
    {
        return (string) ($this->file->getClientMediaType() ?: 'application/octet-stream');
    }

    /** Script content: no type for validation, stored as inert .txt. */
    private const SCRIPT_MIMES = [
        'text/x-php', 'application/x-php', 'application/x-httpd-php', 'text/x-shellscript', 'application/x-sh',
        'text/x-python', 'text/x-script.python', 'text/x-perl', 'text/x-ruby',
    ];

    /** Extensions a web server may execute: never taken from the client's file name. */
    private const UNSAFE_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phar', 'inc',
        'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'asp', 'aspx', 'jsp', 'jspx', 'shtml', 'htaccess', 'exe', 'bat', 'cmd', 'com',
    ];

    /**
     * Extension for the detected type ("jpg"); for types this class does not know, the original name's
     * extension unless it is one a server could execute ("bin" then). Used for stored file names.
     */
    public function extension(): string
    {
        $detected = $this->detectedExtension();
        if ($detected !== null) {
            return $detected;
        }
        if (in_array($this->mimeType(), self::SCRIPT_MIMES, true)) {
            return 'txt';
        }
        $client = strtolower(pathinfo($this->originalName(), PATHINFO_EXTENSION));

        return $client === '' || in_array($client, self::UNSAFE_EXTENSIONS, true) || ! preg_match('/^[a-z0-9]{1,10}$/', $client) ? 'bin' : $client;
    }

    /** Extension of the type detected from the content, null when the type is not one this class maps. */
    public function detectedExtension(): ?string
    {
        return self::EXTENSIONS[$this->mimeType()] ?? null;
    }

    public function size(): int
    {
        return (int) $this->file->getSize();
    }

    public function error(): int
    {
        return $this->file->getError();
    }

    public function isValid(): bool
    {
        return $this->error() === UPLOAD_ERR_OK;
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mimeType(), 'image/');
    }

    /** @return array{width: int, height: int}|null for raster images */
    public function dimensions(): ?array
    {
        $info = @getimagesizefromstring($this->contents());

        return $info === false ? null : ['width' => $info[0], 'height' => $info[1]];
    }

    public function contents(): string
    {
        $stream = $this->stream();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return $stream->getContents();
    }

    public function stream(): StreamInterface
    {
        return $this->file->getStream();
    }

    public function psr(): UploadedFileInterface
    {
        return $this->file;
    }

    /**
     * Save on a disk of config/filesystems.php with a random name and the detected extension; returns the path
     * on that disk. `$avatar->store('avatars', 'public')` → "avatars/3f9c…e1.jpg", then Storage::disk('public')->url($path).
     *
     * @param array<string, mixed>|string $options visibility ('public' / 'private') or Flysystem write options
     */
    public function store(string $directory = '', ?string $disk = null, array|string $options = []): string
    {
        return FilesystemManager::instance()->disk($disk)->putFile($directory, $this, $options);
    }

    /** Like store() with your own file name (no path separators). @param array<string, mixed>|string $options */
    public function storeAs(string $directory, string $name, ?string $disk = null, array|string $options = []): string
    {
        return FilesystemManager::instance()->disk($disk)->putFileAs($directory, $this, $name, $options);
    }

    /** store() with public visibility (S3 objects readable through their URL). */
    public function storePublicly(string $directory = '', ?string $disk = null): string
    {
        return $this->store($directory, $disk, 'public');
    }

    private function head(): string
    {
        $stream = $this->stream();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $head = $stream->read(8192);
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return $head;
    }
}
