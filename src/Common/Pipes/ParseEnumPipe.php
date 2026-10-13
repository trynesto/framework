<?php

declare(strict_types=1);

namespace Nesto\Common\Pipes;

use Nesto\Common\ArgumentMetadata;
use Nesto\Common\Contracts\Pipe;
use Nesto\Common\Exceptions\ValidationException;

/** Converts a scalar to a backed enum: #[Param('status', new ParseEnumPipe(Status::class))] Status $s */
final class ParseEnumPipe implements Pipe
{
    /** @param class-string<\BackedEnum> $enum */
    public function __construct(private readonly string $enum) {}

    public function transform(mixed $value, ArgumentMetadata $metadata): \BackedEnum
    {
        if ($value instanceof $this->enum) {
            return $value;
        }
        $cases = array_map(fn ($c) => $c->value, $this->enum::cases());
        $isInt = is_int($cases[0] ?? null);
        $try = $isInt && is_numeric($value) ? (int) $value : $value;
        $case = is_scalar($try) ? $this->enum::tryFrom($try) : null;
        if ($case === null) {
            throw ValidationException::field($f = ($metadata->data ?? $metadata->type), "$f must be one of the following values: " . implode(', ', $cases));
        }

        return $case;
    }
}
