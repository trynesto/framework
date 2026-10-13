<?php

declare(strict_types=1);

namespace Nesto\Swagger\Attributes;

use Attribute;

/**
 * Document the request body: `#[ApiBody(type: CreateUserDto::class, description: 'New user')]`.
 * `type` is a DTO class or a primitive; `schema` is a raw OpenAPI schema and wins over `type`.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class ApiBody
{
    /** @param array<string, mixed>|null $schema @param array<string, mixed>|null $examples */
    public function __construct(
        public readonly ?string $type = null,
        public readonly ?string $description = null,
        public readonly bool $required = true,
        public readonly bool $isArray = false,
        public readonly ?array $schema = null,
        public readonly ?array $examples = null,
        public readonly string $contentType = 'application/json',
    ) {}
}
