<?php

declare(strict_types=1);

namespace Nesto\Common\Pipes;

use Nesto\Common\ArgumentMetadata;
use Nesto\Common\Contracts\Pipe;
use Nesto\Common\Exceptions\ValidationException;
use Nesto\Common\Pipes\Files\FileValidator;
use Nesto\Http\File;

/**
 * Validates #[UploadedFile] / #[UploadedFiles] (Nest's ParseFilePipe):
 *
 *   #[UploadedFile('avatar', new ParseFilePipe(validators: [
 *       new MaxFileSizeValidator(2 * 1024 * 1024),
 *       new FileTypeValidator('image/jpeg|image/png|image/webp'),
 *   ]))] File $avatar
 *
 *   #[UploadedFiles('photos', new ParseFilePipe(validators: [new FileTypeValidator('image/*')], maxCount: 10))] array $photos
 */
final class ParseFilePipe implements Pipe
{
    /** @param list<FileValidator> $validators */
    public function __construct(
        public readonly array $validators = [],
        public readonly bool $fileIsRequired = true,
        public readonly ?int $maxCount = null,
        public readonly ?int $errorHttpStatusCode = null,
    ) {}

    public function transform(mixed $value, ArgumentMetadata $metadata): mixed
    {
        $field = $metadata->data ?? 'file';
        $files = $value instanceof File ? [$value] : (is_array($value) ? array_values($value) : []);

        if ($files === []) {
            if ($this->fileIsRequired) {
                throw ValidationException::field($field, "$field is required", $this->errorHttpStatusCode);
            }

            return $value;
        }
        $errors = [];
        if ($this->maxCount !== null && count($files) > $this->maxCount) {
            $errors[] = sprintf('%s must not have more than %d file%s', $field, $this->maxCount, $this->maxCount === 1 ? '' : 's');
        }
        foreach ($files as $i => $file) {
            foreach ($this->validators as $validator) {
                if (! $validator->isValid($file)) {
                    $errors[] = $validator->buildErrorMessage($file);
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::field($field, array_values(array_unique($errors)), $this->errorHttpStatusCode);
        }

        return $value;
    }
}
