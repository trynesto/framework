<?php

declare(strict_types=1);

namespace Nesto\Swagger\Attributes;

use Attribute;

/** `#[ApiOperation(summary: 'List users', description: '...', deprecated: false, operationId: 'listUsers')]` */
#[Attribute(Attribute::TARGET_METHOD)]
final class ApiOperation
{
    public function __construct(
        public readonly ?string $summary = null,
        public readonly ?string $description = null,
        public readonly bool $deprecated = false,
        public readonly ?string $operationId = null,
    ) {}
}
