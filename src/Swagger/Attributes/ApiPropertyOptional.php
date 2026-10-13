<?php

declare(strict_types=1);

namespace Nesto\Swagger\Attributes;

use Attribute;

/** Same as #[ApiProperty] but the property is not listed under `required`. */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER | Attribute::TARGET_METHOD)]
final class ApiPropertyOptional extends ApiProperty
{
    public function __construct(
        ?string $description = null,
        mixed $example = null,
        ?string $type = null,
        array|string|null $enum = null,
        ?bool $isArray = null,
        ?bool $nullable = null,
        ?string $format = null,
        int|float|null $minimum = null,
        int|float|null $maximum = null,
        mixed $default = null,
        ?string $name = null,
        ?int $minLength = null,
        ?int $maxLength = null,
        ?string $pattern = null,
        ?int $minItems = null,
        ?int $maxItems = null,
        ?string $title = null,
        bool $readOnly = false,
        bool $writeOnly = false,
        bool $deprecated = false,
        ?array $items = null,
    ) {
        parent::__construct(
            $description, $example, $type, false, $enum, $isArray, $nullable, $format, $minimum, $maximum, $default,
            $name, $minLength, $maxLength, $pattern, $minItems, $maxItems, $title, $readOnly, $writeOnly, $deprecated, $items,
        );
    }
}
