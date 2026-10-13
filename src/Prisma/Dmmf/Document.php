<?php

declare(strict_types=1);

namespace Nesto\Prisma\Dmmf;

use Nesto\Prisma\PrismaException;

/**
 * The validated schema as data — Prisma's DMMF (`datamodel` with models / enums / views / indexes) plus the
 * datasource and generators (Prisma's getConfig()). This is what the client, the generator and migrate
 * consume; it never re-reads the schema text.
 *
 *   $document = SchemaLoader::loadFile('prisma/schema.prisma');
 *   $document->model('User')->field('email')->isUnique;
 *   $document->provider();                       // 'postgresql' | 'mysql' | 'sqlite'
 *   $document->toArray();                        // plain arrays (JSON / var_export friendly)
 *   Document::fromArray($array);                 // back (DmmfCache stores this form)
 *
 * Objects are immutable: one Document can be shared by every request of a long-running worker.
 */
final class Document
{
    /** Bumped when the array layout changes (invalidates DmmfCache files). */
    public const FORMAT_VERSION = 1;

    /** @var array<string, Model> */
    private array $models = [];
    /** @var array<string, Enum> */
    private array $enums = [];

    /**
     * @param list<Model>      $models    models and views (Model::$isView)
     * @param list<Enum>       $enums
     * @param list<Generator>  $generators
     * @param list<Index>      $indexes
     * @param list<ManyToMany> $manyToMany
     * @param list<string>     $warnings validation warnings
     */
    public function __construct(
        public readonly ?Datasource $datasource,
        public readonly array $generators,
        array $models,
        array $enums,
        public readonly array $indexes = [],
        public readonly array $manyToMany = [],
        public readonly array $warnings = [],
    ) {
        foreach ($models as $model) {
            $this->models[$model->name] = $model;
        }
        foreach ($enums as $enum) {
            $this->enums[$enum->name] = $enum;
        }
    }

    /** The connector: postgresql, mysql or sqlite (null without a datasource block). */
    public function provider(): ?string
    {
        return $this->datasource?->provider;
    }

    public function requireDatasource(): Datasource
    {
        return $this->datasource ?? throw new PrismaException('Could not find a datasource in the schema. Add a `datasource db { provider = "postgresql"  url = env("DATABASE_URL") }` block.');
    }

    /** Models (not views), in declaration order. @return list<Model> */
    public function models(): array
    {
        return array_values(array_filter($this->models, fn (Model $m) => ! $m->isView));
    }

    /** @return list<Model> */
    public function views(): array
    {
        return array_values(array_filter($this->models, fn (Model $m) => $m->isView));
    }

    /** A model or view by name. */
    public function model(string $name): ?Model
    {
        return $this->models[$name] ?? null;
    }

    /** @return list<Enum> */
    public function enums(): array
    {
        return array_values($this->enums);
    }

    public function enum(string $name): ?Enum
    {
        return $this->enums[$name] ?? null;
    }

    public function generator(string $name): ?Generator
    {
        foreach ($this->generators as $generator) {
            if ($generator->name === $name) {
                return $generator;
            }
        }

        return null;
    }

    /** Index / unique / id entries of one model. @return list<Index> */
    public function indexesOf(string $model): array
    {
        return array_values(array_filter($this->indexes, fn (Index $i) => $i->model === $model));
    }

    /** The relation field on the other side of $field (model $model). */
    public function oppositeField(Model $model, Field $field): ?Field
    {
        $other = $this->model($field->type);
        if ($other === null || ! $field->isRelation()) {
            return null;
        }
        foreach ($other->relationFields() as $candidate) {
            if ($candidate->relationName === $field->relationName && $candidate->type === $model->name && ($other !== $model || $candidate->name !== $field->name)) {
                return $candidate;
            }
        }

        return null;
    }

    public function manyToManyOf(string $relationName): ?ManyToMany
    {
        foreach ($this->manyToMany as $relation) {
            if ($relation->relationName === $relationName) {
                return $relation;
            }
        }

        return null;
    }

    /**
     * Prisma's DMMF shape: `['datamodel' => ['enums', 'models', 'types', 'views', 'indexes']]`.
     *
     * @return array<string, mixed>
     */
    public function dmmf(): array
    {
        return [
            'datamodel' => [
                'enums' => array_map(fn (Enum $e) => $e->toArray(), $this->enums()),
                'models' => array_map(fn (Model $m) => $m->toArray(), $this->models()),
                'types' => [],
                'views' => array_map(fn (Model $m) => $m->toArray(), $this->views()),
                'indexes' => array_map(fn (Index $i) => $i->toArray(), $this->indexes),
            ],
        ];
    }

    /** Everything, as plain arrays (DMMF + datasources + generators + Nesto extras). @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->dmmf() + [
            'datasources' => $this->datasource !== null ? [$this->datasource->toArray()] : [],
            'generators' => array_map(fn (Generator $g) => $g->toArray(), $this->generators),
            'nesto' => [
                'version' => self::FORMAT_VERSION,
                'implicitManyToMany' => array_map(fn (ManyToMany $m) => $m->toArray(), $this->manyToMany),
                'warnings' => $this->warnings,
            ],
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $datamodel = $data['datamodel'] ?? [];

        return new self(
            isset($data['datasources'][0]) ? Datasource::fromArray($data['datasources'][0]) : null,
            array_map(fn (array $g) => Generator::fromArray($g), $data['generators'] ?? []),
            [
                ...array_map(fn (array $m) => Model::fromArray($m), $datamodel['models'] ?? []),
                ...array_map(fn (array $m) => Model::fromArray($m, true), $datamodel['views'] ?? []),
            ],
            array_map(fn (array $e) => Enum::fromArray($e), $datamodel['enums'] ?? []),
            array_map(fn (array $i) => Index::fromArray($i), $datamodel['indexes'] ?? []),
            array_map(fn (array $m) => ManyToMany::fromArray($m), $data['nesto']['implicitManyToMany'] ?? []),
            $data['nesto']['warnings'] ?? [],
        );
    }

    public function toJson(int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE): string
    {
        return (string) json_encode($this->toArray(), $flags);
    }
}
