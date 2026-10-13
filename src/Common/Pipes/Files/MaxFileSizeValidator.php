<?php

declare(strict_types=1);

namespace Nesto\Common\Pipes\Files;

use Nesto\Http\File;
use Nesto\Http\FileRules;

/** `new MaxFileSizeValidator(maxSize: 2 * 1024 * 1024)` — size in bytes. */
final class MaxFileSizeValidator extends FileValidator
{
    public function __construct(public readonly int $maxSize, public readonly ?string $message = null) {}

    public function isValid(File $file): bool
    {
        return $file->size() <= $this->maxSize;
    }

    public function buildErrorMessage(File $file): string
    {
        return $this->message ?? sprintf('%s must not be larger than %s', $file->fieldName ?: 'file', FileRules::human($this->maxSize));
    }
}
