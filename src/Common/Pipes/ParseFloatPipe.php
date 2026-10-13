<?php

declare(strict_types=1);

namespace Nesto\Common\Pipes;

use Nesto\Common\ArgumentMetadata;
use Nesto\Common\Contracts\Pipe;
use Nesto\Common\Exceptions\ValidationException;

final class ParseFloatPipe implements Pipe
{
    public function transform(mixed $value, ArgumentMetadata $metadata): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }
        throw ValidationException::field($f = ($metadata->data ?? $metadata->type), "$f must be a number");
    }
}
