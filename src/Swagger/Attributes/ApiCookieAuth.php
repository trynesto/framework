<?php

declare(strict_types=1);

namespace Nesto\Swagger\Attributes;

use Attribute;

/** `#[ApiCookieAuth]` → security: [{cookie: []}] (name must match DocumentBuilder::addCookieAuth()). */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class ApiCookieAuth extends ApiSecurity
{
    public function __construct(string $name = 'cookie')
    {
        parent::__construct($name);
    }
}
