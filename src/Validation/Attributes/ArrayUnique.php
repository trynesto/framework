<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * All elements of the array must be unique (compared by value, objects by identity).
 *
 *   #[ArrayUnique]
 *   public array $tags;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class ArrayUnique extends Constraint
{
    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        $ok = false;
        if (is_array($value)) {
            $seen = [];
            $ok = true;
            foreach ($value as $item) {
                $key = is_object($item) ? spl_object_id($item) : serialize($item);
                if (isset($seen[$key])) {
                    $ok = false;
                    break;
                }
                $seen[$key] = true;
            }
        }

        return $ok ? null : $this->fail($ctx, "All {$ctx->property}'s elements must be unique", $value);
    }
}
