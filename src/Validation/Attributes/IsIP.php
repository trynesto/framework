<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * String must be an IPv4 / IPv6 address (any version unless one is given).
 *
 *   #[IsIP(version: 4)]
 *   public string $ip;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class IsIP extends Constraint
{
    /**
     * @param 4|6|'4'|'6'|null $version
     * @param list<string> $groups
     */
    public function __construct(
        public readonly int|string|null $version = null,
        ?string $message = null,
        array $groups = [],
        bool $always = false,
        bool $each = false,
    ) {
        parent::__construct($message, $groups, $always, $each);
    }

    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        $flags = match ((string) $this->version) {
            '4' => FILTER_FLAG_IPV4,
            '6' => FILTER_FLAG_IPV6,
            default => 0,
        };
        $ok = is_string($value) && filter_var($value, FILTER_VALIDATE_IP, $flags) !== false;

        return $ok ? null : $this->fail($ctx, "{$ctx->property} must be an ip address", $value);
    }
}
