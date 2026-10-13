<?php

declare(strict_types=1);

namespace Nesto\Swagger\Attributes;

use Attribute;

/**
 * Describe a response. `type` is a DTO class (→ $ref), a primitive name ('string', 'integer', ...) or null.
 *
 *   #[ApiResponse(status: 200, description: 'The user', type: UserDto::class)]
 *   #[ApiResponse(status: 200, type: UserDto::class, isArray: true)]
 *   #[ApiResponse(status: 404, description: 'Not found')]
 *   #[ApiResponse(status: 200, schema: ['type' => 'object', 'properties' => [...]])]   // raw OpenAPI schema
 *
 * In raw schemas a class name as `$ref` stands for Nest's getSchemaPath(), e.g. a page of posts:
 *
 *   #[ApiOkResponse(schema: ['allOf' => [
 *       ['$ref' => Pagination::class],
 *       ['properties' => ['data' => ['type' => 'array', 'items' => ['$ref' => Post::class]]]],
 *   ]])]
 *
 * Without `type` / `schema`, a 2xx response is documented with the handler's return type.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class ApiResponse
{
    /**
     * @param array<string, mixed>|null $schema  raw schema, wins over $type
     * @param array<string, mixed>|null $headers OpenAPI header objects keyed by name
     */
    public function __construct(
        public readonly int|string $status = 200,
        public readonly ?string $description = null,
        public readonly ?string $type = null,
        public readonly bool $isArray = false,
        public readonly ?array $schema = null,
        public readonly mixed $example = null,
        public readonly ?array $headers = null,
        public readonly string $contentType = 'application/json',
    ) {}
}
