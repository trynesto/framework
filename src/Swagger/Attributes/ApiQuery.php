<?php

declare(strict_types=1);

namespace Nesto\Swagger\Attributes;

use Attribute;

/** Document a query parameter: `#[ApiQuery(name: 'page', required: false, type: 'integer', description: 'Page')]`. */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class ApiQuery
{
    /** @param list<mixed>|string|null $enum values or a backed-enum class */
    public function __construct(
        public readonly string $name,
        public readonly bool $required = false,
        public readonly ?string $type = null,
        public readonly ?string $description = null,
        public readonly array|string|null $enum = null,
        public readonly bool $isArray = false,
        public readonly mixed $example = null,
        public readonly ?string $format = null,
        public readonly ?array $schema = null,
    ) {}
}
