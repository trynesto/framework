<?php

declare(strict_types=1);

namespace Nesto\Prisma\Dmmf;

/**
 * The `datasource` block. `provider` is the connector Nesto uses (postgresql, mysql, sqlite);
 * `activeProvider` is what the schema says ("mariadb" is accepted and runs on the mysql provider).
 * `directUrl` is what migrate / db commands connect to (PrismaProject::databaseUrl()); `extensions`
 * (PostgreSQL, `postgresqlExtensions` preview feature) are created by db push / migrate.
 */
final class Datasource
{
    /**
     * @param list<string> $schemas
     * @param list<array{name: string, dbName: string, schema: ?string, version: ?string}> $extensions
     */
    public function __construct(
        public readonly string $name,
        public readonly string $provider,
        public readonly string $activeProvider,
        public readonly EnvValue $url,
        public readonly ?EnvValue $directUrl = null,
        public readonly ?EnvValue $shadowDatabaseUrl = null,
        public readonly string $relationMode = 'foreignKeys',
        public readonly array $schemas = [],
        public readonly array $extensions = [],
    ) {}

    public function usesForeignKeys(): bool
    {
        return $this->relationMode === 'foreignKeys';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
            'provider' => $this->provider,
            'activeProvider' => $this->activeProvider,
            'url' => $this->url->toArray(),
            'directUrl' => $this->directUrl?->toArray(),
            'shadowDatabaseUrl' => $this->shadowDatabaseUrl?->toArray(),
            'relationMode' => $this->relationMode,
            'schemas' => $this->schemas,
        ];
        if ($this->extensions !== []) {
            $data['extensions'] = $this->extensions;
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['name'],
            $data['provider'],
            $data['activeProvider'] ?? $data['provider'],
            EnvValue::fromArray($data['url']) ?? new EnvValue(null),
            EnvValue::fromArray($data['directUrl'] ?? null),
            EnvValue::fromArray($data['shadowDatabaseUrl'] ?? null),
            $data['relationMode'] ?? 'foreignKeys',
            $data['schemas'] ?? [],
            $data['extensions'] ?? [],
        );
    }
}
