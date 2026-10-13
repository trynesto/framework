<?php

declare(strict_types=1);

namespace Nesto\Common\Attributes;

use Attribute;
use Nesto\Http\FileRules;

/**
 * Inject several uploaded files (`photos[]` in the form) as list<Nesto\Http\File> (Nest's @UploadedFiles()):
 *
 *   public function gallery(#[UploadedFiles('photos', image: true, maxSize: '5MB', maxFiles: 10)] array $photos)
 *
 * Without a field name every uploaded file of the request is returned.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class UploadedFiles extends ParamAttribute
{
    public readonly FileRules $rules;

    /** @param list<string>|string|null $types */
    public function __construct(
        ?string $key = null,
        int|string|object|null $maxSize = null,
        array|string|null $types = null,
        bool $image = false,
        ?int $maxFiles = null,
        ?int $minFiles = null,
        string|object ...$pipes,
    ) {
        if (is_object($maxSize)) {
            array_unshift($pipes, $maxSize);
            $maxSize = null;
        }
        parent::__construct($key, ...$pipes);
        $this->rules = new FileRules($maxSize, $types, $image, $maxFiles, $minFiles);
    }

    public function type(): string
    {
        return 'files';
    }
}
