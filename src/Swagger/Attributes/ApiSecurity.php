<?php

declare(strict_types=1);

namespace Nesto\Swagger\Attributes;

use Attribute;

/**
 * Require a security scheme declared on the DocumentBuilder (addBearerAuth / addApiKey / addSecurity...).
 *
 *   #[ApiSecurity('api-key')]
 *   #[ApiSecurity('oauth2', ['read:users'])]
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class ApiSecurity
{
    /** @param list<string> $scopes */
    public function __construct(public readonly string $name, public readonly array $scopes = []) {}
}
