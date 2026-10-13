<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Http\FileRules;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * An uploaded file in a multipart DTO (`#[Body] CreatePostDto $dto` receives the files too).
 *
 *   #[IsFile(maxSize: '10MB', types: ['pdf', 'docx'])]
 *   public File $contract;
 *
 *   /** @var list<File> *\/
 *   #[IsFile(maxSize: '2MB', maxFiles: 5)]
 *   public array $attachments = [];
 *
 * Missing → "contract is required", unless #[IsOptional] (then type it ?File $contract = null).
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class IsFile extends Constraint
{
    /** @param list<string>|string|null $types extensions or MIME patterns, see FileRules */
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
        $errors = (new FileRules($this->maxSize, $this->types, false, $this->maxFiles, $this->minFiles))->check($value, $ctx->property);

        return $errors === [] ? null : $this->fail($ctx, $errors[0], $value);
    }
}
