<?php

declare(strict_types=1);

namespace Nesto\Swagger\Attributes;

use Attribute;

/** Document a path parameter: `#[ApiParam(name: 'id', description: 'User id', type: 'integer')]`. */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class ApiParam
{
    /** @param list<mixed>|string|null $enum values or a backed-enum class */
    public function __construct(
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly ?string $type = null,
        public readonly bool $required = true,
        public readonly array|string|null $enum = null,
        public readonly mixed $example = null,
        public readonly ?string $format = null,
        public readonly ?array $schema = null,
    ) {}
}
