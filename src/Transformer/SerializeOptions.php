<?php

declare(strict_types=1);

namespace Nesto\Transformer;

use Attribute;

/**
 * Per-handler / per-controller options read by ClassSerializerInterceptor (Nest's @SerializeOptions()).
 *
 *   #[SerializeOptions(groups: ['admin'])]
 *   #[Get(':id')]
 *   public function show(#[Param('id')] int $id): UserEntity { ... }
 *
 * A method attribute overrides a class attribute, which overrides the interceptor constructor options.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class SerializeOptions
{
    /**
     * @param list<string>|null  $groups
     * @param 'snake'|'camel'|null $namingStrategy
     */
    public function __construct(
        public readonly ?array $groups = null,
        public readonly ?bool $excludeExtraneousValues = null,
        public readonly ?string $namingStrategy = null,
    ) {}

    /** @return array<string, mixed> only the explicitly set options */
    public function toArray(): array
    {
        return array_filter([
            'groups' => $this->groups,
            'excludeExtraneousValues' => $this->excludeExtraneousValues,
            'namingStrategy' => $this->namingStrategy,
        ], static fn ($v) => $v !== null);
    }
}
