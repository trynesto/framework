<?php

declare(strict_types=1);

namespace Nesto\Common\Pipes;

use Nesto\Common\ArgumentMetadata;
use Nesto\Common\Contracts\Pipe;
use Nesto\Common\Exceptions\ValidationException;

final class ParseUuidPipe implements Pipe
{
    public function __construct(private readonly ?int $version = null) {}

    public function transform(mixed $value, ArgumentMetadata $metadata): string
    {
        $v = $this->version ? (string) $this->version : '[1-8]';
        if (is_string($value) && preg_match("/^[0-9a-f]{8}-[0-9a-f]{4}-{$v}[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i", $value)) {
            return strtolower($value);
        }
        throw ValidationException::field($f = ($metadata->data ?? $metadata->type), "$f must be a UUID" . ($this->version ? " (version {$this->version})" : ''));
    }
}
