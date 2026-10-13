<?php

declare(strict_types=1);

namespace Nesto\Auth\Contracts;

use Nesto\Http\Request;

/**
 * A passport-style authentication strategy. Register one as a provider in any module and
 * AuthGuard finds it by name().
 *
 *   #[Injectable]
 *   final class ApiKeyAuth implements Strategy {
 *       public function name(): string { return 'api-key'; }
 *       public function authenticate(Request $request): mixed {
 *           return $request->header('x-api-key') === 'secret' ? ['id' => 'service'] : null;
 *       }
 *   }
 */
interface Strategy
{
    /** The name used in `new AuthGuard('jwt')`. */
    public function name(): string;

    /**
     * Return the authenticated user (any value) or null / throw UnauthorizedException when the
     * request cannot be authenticated.
     */
    public function authenticate(Request $request): mixed;
}
