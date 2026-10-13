<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Http\FileRules;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * An uploaded image (jpg, png, gif, webp, avif by default; SVG is excluded because it can carry scripts).
 *
 *   #[IsImage(maxSize: '5MB')]
 *   public File $cover;
 *
 *   #[IsImage(maxSize: '2MB', types: ['jpg', 'png'], maxFiles: 10)]
 *   public array $gallery = [];
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class IsImage extends Constraint
{
    /** @param list<string>|string|null $types */
    public function __construct(
        public readonly int|string|null $maxSize = null,
        public readonly array|string|null $types = null,
        public readonly ?int $maxFiles = null,
        public readonly ?int $minFiles = null,
        ?string $message = null,
        array $groups = [],
        bool $always = false,
    ) {
        parent::__construct($message, $groups, $always);
    }

    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        $errors = (new FileRules($this->maxSize, $this->types, true, $this->maxFiles, $this->minFiles))->check($value, $ctx->property);

        return $errors === [] ? null : $this->fail($ctx, $errors[0], $value);
    }
}
