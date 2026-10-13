<?php

declare(strict_types=1);

namespace Nesto\Swagger\Attributes;

use Attribute;

/** `#[ApiBearerAuth]` → security: [{bearer: []}] (name must match DocumentBuilder::addBearerAuth()). */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class ApiBearerAuth extends ApiSecurity
{
    public function __construct(string $name = 'bearer')
    {
        parent::__construct($name);
    }
}
