<?php

declare(strict_types=1);

namespace Nesto\Common\Pipes;

use Nesto\Common\ArgumentMetadata;
use Nesto\Common\Contracts\Pipe;
use Nesto\Common\Exceptions\ValidationException;

final class ParseBoolPipe implements Pipe
{
    public function transform(mixed $value, ArgumentMetadata $metadata): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $v = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($v === null) {
            throw ValidationException::field($f = ($metadata->data ?? $metadata->type), "$f must be a boolean value");
        }

        return $v;
    }
}
