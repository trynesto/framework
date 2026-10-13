<?php

declare(strict_types=1);

namespace Nesto\Common\Contracts;

use Nesto\Common\ArgumentMetadata;

/** Nest's PipeTransform. */
interface Pipe
{
    public function transform(mixed $value, ArgumentMetadata $metadata): mixed;
}
