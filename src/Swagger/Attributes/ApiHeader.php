<?php

declare(strict_types=1);

namespace Nesto\Swagger\Attributes;

use Attribute;

/** Document a request header: `#[ApiHeader(name: 'X-Request-Id', required: false)]`. */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class ApiHeader
{
    /** @param list<mixed>|null $enum */
    public function __construct(
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly bool $required = false,
        public readonly ?string $type = null,
        public readonly ?array $enum = null,
        public readonly mixed $example = null,
        public readonly ?array $schema = null,
    ) {}
}
