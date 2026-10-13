<?php

declare(strict_types=1);

namespace Nesto\Core;

use Nesto\Common\Provider;
use Nesto\Common\Scope;

/** Internal: a Provider bound to the module that declared it. */
final class ProviderDefinition
{
    /** @param string|null $key registry key inside the module (differs from token() for APP_* providers) */
    public function __construct(
        public readonly Provider $provider,
        public readonly ModuleRef $module,
        public ?string $key = null,
    ) {
        $this->key ??= $provider->provide;
    }

    public function token(): string
    {
        return $this->provider->provide;
    }

    public function scope(): Scope
    {
        return $this->provider->scope;
    }
}
