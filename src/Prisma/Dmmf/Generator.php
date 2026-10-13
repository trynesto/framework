<?php

declare(strict_types=1);

namespace Nesto\Prisma\Dmmf;

/** A `generator` block: provider ("nesto-client"), output, previewFeatures and any other key as config. */
final class Generator
{
    /**
     * @param array<string, string|list<string>> $config
     * @param list<string>                       $previewFeatures
     * @param list<string>                       $binaryTargets
     */
    public function __construct(
        public readonly string $name,
        public readonly EnvValue $provider,
        public readonly ?EnvValue $output = null,
        public readonly array $config = [],
        public readonly array $previewFeatures = [],
        public readonly array $binaryTargets = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'provider' => $this->provider->toArray(),
            'output' => $this->output?->toArray(),
            'config' => $this->config,
            'binaryTargets' => $this->binaryTargets,
            'previewFeatures' => $this->previewFeatures,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['name'],
            EnvValue::fromArray($data['provider']) ?? new EnvValue(null),
            EnvValue::fromArray($data['output'] ?? null),
            $data['config'] ?? [],
            $data['previewFeatures'] ?? [],
            $data['binaryTargets'] ?? [],
        );
    }
}
