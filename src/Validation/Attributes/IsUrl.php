<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * Value must be a URL with one of the allowed protocols (and a TLD unless requireTld is false).
 *
 *   #[IsUrl(protocols: ['https'])]
 *   public string $website;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class IsUrl extends Constraint
{
    /**
     * @param list<string> $protocols
     * @param list<string> $groups
     */
    public function __construct(
        public readonly array $protocols = ['http', 'https'],
        public readonly bool $requireTld = true,
        public readonly bool $requireProtocol = true,
        ?string $message = null,
        array $groups = [],
        bool $always = false,
        bool $each = false,
    ) {
        parent::__construct($message, $groups, $always, $each);
    }

    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        $ok = false;
        if (is_string($value) && $value !== '' && ! preg_match('/\\s/', $value)) {
            $candidate = $this->requireProtocol || preg_match('#^[a-z][a-z0-9+.-]*://#i', $value) ? $value : 'http://' . $value;
            $parts = parse_url($candidate);
            $host = $parts['host'] ?? '';
            $ok = $parts !== false
                && isset($parts['scheme']) && in_array(strtolower($parts['scheme']), $this->protocols, true)
                && $host !== ''
                && (! $this->requireTld || preg_match('/\\.[a-z]{2,}$/i', $host) || filter_var($host, FILTER_VALIDATE_IP) !== false);
        }

        return $ok ? null : $this->fail($ctx, "{$ctx->property} must be a URL address", $value);
    }
}
