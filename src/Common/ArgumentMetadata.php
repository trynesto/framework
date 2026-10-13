<?php

declare(strict_types=1);

namespace Nesto\Common;

/** Passed to every Pipe::transform() so it knows what it is transforming. */
final class ArgumentMetadata
{
    /**
     * @param 'body'|'query'|'param'|'headers'|'file'|'files'|'custom' $type
     * @param class-string|string|null                   $metatype PHP type of the parameter (class or scalar name)
     */
    public function __construct(
        public readonly string $type,
        public readonly ?string $metatype = null,
        public readonly ?string $data = null,
    ) {}
}
