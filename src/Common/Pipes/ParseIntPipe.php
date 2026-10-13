<?php

declare(strict_types=1);

namespace Nesto\Common\Pipes;

use Nesto\Common\ArgumentMetadata;
use Nesto\Common\Contracts\Pipe;
use Nesto\Common\Exceptions\ValidationException;

final class ParseIntPipe implements Pipe
{
    public function transform(mixed $value, ArgumentMetadata $metadata): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', trim($value))) {
            return (int) $value;
        }
        throw ValidationException::field($f = ($metadata->data ?? $metadata->type), "$f must be an integer");
    }
}
