<?php

declare(strict_types=1);

namespace Nesto\Swagger\Attributes;

use Attribute;

/** `#[ApiBasicAuth]` → security: [{basic: []}] (name must match DocumentBuilder::addBasicAuth()). */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class ApiBasicAuth extends ApiSecurity
{
    public function __construct(string $name = 'basic')
    {
        parent::__construct($name);
    }
}
