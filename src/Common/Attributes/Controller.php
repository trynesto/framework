<?php

declare(strict_types=1);

namespace Nesto\Common\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class Controller
{
    /**
     * @param string|list<string>      $path
     * @param string|list<string>|null $host only serve requests for this host: 'admin.example.com', or with params
     *                                       ':account.example.com' (captured in the Constants::HOST_PARAMS request attribute)
     */
    public function __construct(
        public readonly string|array $path = '',
        public readonly string|array|null $host = null,
    ) {}
}
