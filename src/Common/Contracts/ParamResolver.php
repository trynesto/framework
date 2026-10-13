<?php

declare(strict_types=1);

namespace Nesto\Common\Contracts;

use Nesto\Core\ExecutionContext;

/**
 * Nest's createParamDecorator(): implement this on a parameter attribute to build custom injectors.
 *
 *  #[Attribute(Attribute::TARGET_PARAMETER)]
 *  final class CurrentUser implements ParamResolver {
 *      public function resolve(ExecutionContext $ctx): mixed { return $ctx->switchToHttp()->getRequest()->user(); }
 *  }
 */
interface ParamResolver
{
    public function resolve(ExecutionContext $context): mixed;
}
