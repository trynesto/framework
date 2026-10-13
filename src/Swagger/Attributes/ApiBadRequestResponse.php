<?php

declare(strict_types=1);

namespace Nesto\Swagger\Attributes;

use Attribute;

/** Shortcut for `#[ApiResponse(status: 400, ...)]`. */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class ApiBadRequestResponse extends ApiResponse
{
    /** @param array<string, mixed>|null $schema @param array<string, mixed>|null $headers */
    public function __construct(
        ?string $description = null,
        ?string $type = null,
        bool $isArray = false,
        ?array $schema = null,
        mixed $example = null,
        ?array $headers = null,
        string $contentType = 'application/json',
    ) {
        parent::__construct(400, $description, $type, $isArray, $schema, $example, $headers, $contentType);
    }
}
