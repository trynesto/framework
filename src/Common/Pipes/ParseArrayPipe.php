<?php

declare(strict_types=1);

namespace Nesto\Common\Pipes;

use Nesto\Common\ArgumentMetadata;
use Nesto\Common\Contracts\Pipe;
use Nesto\Common\Exceptions\ValidationException;

/** "1,2,3" → [1,2,3] (optionally running each item through another pipe). */
final class ParseArrayPipe implements Pipe
{
    public function __construct(
        private readonly string $separator = ',',
        private readonly ?Pipe $items = null,
        private readonly bool $optional = false,
    ) {}

    public function transform(mixed $value, ArgumentMetadata $metadata): ?array
    {
        if ($value === null || $value === '') {
            if ($this->optional) {
                return null;
            }
            throw ValidationException::field($f = ($metadata->data ?? $metadata->type), "$f must be an array");
        }
        $items = is_array($value) ? $value : explode($this->separator, (string) $value);
        if ($this->items) {
            $items = array_map(fn ($i) => $this->items->transform($i, $metadata), $items);
        }

        return array_values($items);
    }
}
