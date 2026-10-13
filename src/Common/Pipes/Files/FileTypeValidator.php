<?php

declare(strict_types=1);

namespace Nesto\Common\Pipes\Files;

use Nesto\Http\File;

/**
 * Checks the MIME type detected from the file's content (not the client's claim):
 *
 *   new FileTypeValidator('image/*')                    // any image
 *   new FileTypeValidator('image/jpeg|image/png')       // alternatives
 *   new FileTypeValidator('/^image\/(jpeg|png|webp)$/') // a regex
 */
final class FileTypeValidator extends FileValidator
{
    public function __construct(public readonly string $fileType, public readonly ?string $message = null) {}

    public function isValid(File $file): bool
    {
        $mime = $file->mimeType();
        if (preg_match('#^/.+/[a-z]*$#i', $this->fileType)) {
            return (bool) preg_match($this->fileType, $mime);
        }
        foreach (explode('|', $this->fileType) as $pattern) {
            $regex = '#^' . str_replace('\*', '[^/]+', preg_quote(trim($pattern), '#')) . '$#i';
            if (preg_match($regex, $mime)) {
                return true;
            }
        }

        return false;
    }

    public function buildErrorMessage(File $file): string
    {
        return $this->message ?? sprintf('%s must be a file of type: %s', $file->fieldName ?: 'file', str_replace('|', ', ', $this->fileType));
    }
}
