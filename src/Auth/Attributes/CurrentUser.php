<?php

declare(strict_types=1);

namespace Nesto\Auth\Attributes;

use Attribute;
use Nesto\Common\Contracts\ParamResolver;
use Nesto\Core\ExecutionContext;

/**
 * Injects the user stored on the request by AuthGuard (`$request->user()`).
 *
 *   public function profile(#[CurrentUser] array $user) { ... }
 *   public function me(#[CurrentUser('id')] int $id) { ... }       // plucks $user['id'] / $user->id
 *   public function me(#[CurrentUser('profile.email')] string $e)  // dot paths work too
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class CurrentUser implements ParamResolver
{
    public function __construct(public readonly ?string $property = null) {}

    public function resolve(ExecutionContext $context): mixed
    {
        $user = $context->switchToHttp()->getRequest()->user();
        if ($this->property === null || $user === null) {
            return $user;
        }

        return data_get($user, $this->property);
    }
}
