<?php

declare(strict_types=1);

namespace Nesto\Common\Pipes;

use Nesto\Common\ArgumentMetadata;
use Nesto\Common\Contracts\Pipe;

final class DefaultValuePipe implements Pipe
{
    public function __construct(private readonly mixed $default) {}

    public function transform(mixed $value, ArgumentMetadata $metadata): mixed
    {
        return $value === null || $value === '' ? $this->default : $value;
    }
}
