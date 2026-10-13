<?php

declare(strict_types=1);

namespace Nesto\Auth;

use Nesto\Auth\Attributes\Roles;
use Nesto\Common\Attributes\Injectable;
use Nesto\Common\Contracts\Guard;
use Nesto\Common\Exceptions\ForbiddenException;
use Nesto\Core\ExecutionContext;
use Nesto\Core\Reflector;

/**
 * Checks #[Roles(...)] on the handler (overrides the class) against the authenticated user's roles.
 * Run it after AuthGuard: `#[UseGuards(new AuthGuard('jwt'), RolesGuard::class)]`.
 *
 * The user's roles are read from `$user->roles`, `$user['roles']`, `$user->role` or `$user['role']`
 * (strings, backed enums, or objects/arrays with a `name` key). No #[Roles] → allowed. No user → 403.
 */
#[Injectable]
class RolesGuard implements Guard
{
    private Reflector $reflector;

    public function __construct(?Reflector $reflector = null)
    {
        $this->reflector = $reflector ?? new Reflector;
    }

    public function canActivate(ExecutionContext $context): bool
    {
        /** @var Roles|null $required */
        $required = $this->reflector->getAllAndOverride(Roles::class, [$context->getHandler(), $context->getClass()]);
        if ($required === null || $required->roles === []) {
            return true;
        }

        $user = $context->switchToHttp()->getRequest()->user();
        if ($user === null) {
            throw new ForbiddenException('Forbidden resource');
        }

        $roles = self::rolesOf($user);
        foreach ($required->roles as $role) {
            if (in_array($role, $roles, true)) {
                return true;
            }
        }

        throw new ForbiddenException('Forbidden resource');
    }

    /** @return list<string> */
    public static function rolesOf(mixed $user): array
    {
        $raw = null;
        foreach (['roles', 'role'] as $key) {
            $value = is_array($user) ? ($user[$key] ?? null) : (is_object($user) ? ($user->{$key} ?? null) : null);
            if ($value === null && is_object($user) && method_exists($user, 'get' . ucfirst($key))) {
                $value = $user->{'get' . ucfirst($key)}();
            }
            if ($value !== null) {
                $raw = $value;
                break;
            }
        }
        if ($raw === null) {
            return [];
        }
        $out = [];
        foreach (is_iterable($raw) && ! is_string($raw) ? $raw : [$raw] as $role) {
            $out[] = match (true) {
                $role instanceof \BackedEnum => (string) $role->value,
                $role instanceof \UnitEnum => $role->name,
                is_array($role) => (string) ($role['name'] ?? $role['slug'] ?? ''),
                is_object($role) => (string) ($role->name ?? $role->slug ?? (method_exists($role, '__toString') ? $role : '')),
                default => (string) $role,
            };
        }

        return array_values(array_filter($out, fn ($r) => $r !== ''));
    }
}
