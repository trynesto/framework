<?php

declare(strict_types=1);

namespace Nesto\Swagger\Attributes;

use Attribute;

/** Register DTO classes under components.schemas even if no route references them: `#[ApiExtraModels(ErrorDto::class)]`. */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class ApiExtraModels
{
    /** @var list<class-string> */
    public readonly array $models;

    public function __construct(string ...$models)
    {
        $this->models = array_values($models);
    }
}
