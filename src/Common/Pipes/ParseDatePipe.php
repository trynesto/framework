<?php

declare(strict_types=1);

namespace Nesto\Common\Pipes;

use Nesto\Common\ArgumentMetadata;
use Nesto\Common\Contracts\Pipe;
use Nesto\Common\Exceptions\ValidationException;

final class ParseDatePipe implements Pipe
{
    public function transform(mixed $value, ArgumentMetadata $metadata): \DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }
        try {
            return new \DateTimeImmutable((string) $value);
        } catch (\Throwable) {
            throw ValidationException::field($f = ($metadata->data ?? $metadata->type), "$f must be a valid date");
        }
    }
}
