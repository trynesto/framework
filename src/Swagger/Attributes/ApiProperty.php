<?php

declare(strict_types=1);

namespace Nesto\Swagger\Attributes;

use Attribute;

/**
 * Document a DTO property. Everything is optional: the PHP type is used when `type` is omitted.
 *
 *   #[ApiProperty(description: 'Full name', example: 'Jane', minLength: 2)] public string $name;
 *   #[ApiProperty(type: 'string', format: 'date-time')] public string $createdAt;
 *   #[ApiProperty(type: AddressDto::class)] public array $addresses;              // + isArray: true for a list
 *   #[ApiProperty(enum: ['a', 'b'], default: 'a')] public string $kind;
 *   #[ApiProperty(enum: Status::class)] public string $status;                      // backed enum → its cases
 *
 * Also on an #[Expose]d method (a computed value the serializer outputs):
 *
 *   #[Expose(name: 'imageUrl'), ApiProperty(format: 'uri')] public function imageUrl(): string { ... }
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER | Attribute::TARGET_METHOD)]
class ApiProperty
{
    /**
     * @param string|null             $type  'string'|'integer'|'number'|'boolean'|'array'|'object' or a class-string (→ $ref)
     * @param list<mixed>|string|null $enum  values, or a backed-enum class name
     */
    public function __construct(
        public readonly ?string $description = null,
        public readonly mixed $example = null,
        public readonly ?string $type = null,
        public readonly ?bool $required = null,
        public readonly array|string|null $enum = null,
        public readonly ?bool $isArray = null,
        public readonly ?bool $nullable = null,
        public readonly ?string $format = null,
        public readonly int|float|null $minimum = null,
        public readonly int|float|null $maximum = null,
        public readonly mixed $default = null,
        public readonly ?string $name = null,
        public readonly ?int $minLength = null,
        public readonly ?int $maxLength = null,
        public readonly ?string $pattern = null,
        public readonly ?int $minItems = null,
        public readonly ?int $maxItems = null,
        public readonly ?string $title = null,
        public readonly bool $readOnly = false,
        public readonly bool $writeOnly = false,
        public readonly bool $deprecated = false,
        public readonly ?array $items = null,
    ) {}

    public function isRequired(): ?bool
    {
        return $this->required;
    }
}
