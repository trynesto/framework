<?php

declare(strict_types=1);

namespace Nesto\Common\Pipes\Files;

use Nesto\Http\File;

/** One rule checked by ParseFilePipe (Nest's FileValidator). Extend it for your own rules. */
abstract class FileValidator
{
    abstract public function isValid(File $file): bool;

    abstract public function buildErrorMessage(File $file): string;
}
