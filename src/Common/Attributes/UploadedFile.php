<?php

declare(strict_types=1);

namespace Nesto\Common\Attributes;

use Attribute;
use Nesto\Http\FileRules;

/**
 * Inject one uploaded file as a Nesto\Http\File (Nest's @UploadedFile()), with optional rules:
 *
 *   public function avatar(#[UploadedFile('avatar', image: true, maxSize: '2MB')] File $avatar)
 *   public function import(#[UploadedFile('sheet', types: ['csv', 'xlsx'])] File $sheet)
 *   public function avatar(#[UploadedFile('avatar')] ?File $avatar = null)          // optional
 *
 * Or put the file in your DTO with #[IsFile] / #[IsImage] and take `#[Body] Dto $dto`.
 * Nest-style pipes still work: #[UploadedFile('avatar', new ParseFilePipe(validators: [...]))].
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class UploadedFile extends ParamAttribute
{
    public readonly FileRules $rules;

    /** @param list<string>|string|null $types extensions ("pdf") or MIME patterns ("image/*") */
    public function __construct(
        ?string $key = null,
        int|string|object|null $maxSize = null,
        array|string|null $types = null,
        bool $image = false,
        string|object ...$pipes,
    ) {
        if (is_object($maxSize)) { // #[UploadedFile('avatar', new ParseFilePipe(...))]
            array_unshift($pipes, $maxSize);
            $maxSize = null;
        }
        parent::__construct($key, ...$pipes);
        $this->rules = new FileRules($maxSize, $types, $image);
    }

    public function type(): string
    {
        return 'file';
    }
}
