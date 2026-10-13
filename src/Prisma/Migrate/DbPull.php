<?php

declare(strict_types=1);

namespace Nesto\Prisma\Migrate;

use Nesto\Prisma\Introspection\SchemaIntrospector;
use Nesto\Prisma\PrismaException;
use Nesto\Prisma\PrismaProject;
use Nesto\Prisma\Schema\Ast\Block;
use Nesto\Prisma\Schema\Ast\EmptyLine;
use Nesto\Prisma\Schema\Ast\SchemaAst;
use Nesto\Prisma\Schema\Formatter;
use Nesto\Prisma\Schema\SchemaLoader;

/**
 * `prisma db pull`: introspect the datasource and return the schema text — the current schema's datasource,
 * generators, views and comments, followed by the introspected models and enums (re-introspection keeps the
 * names chosen in the current schema unless `force`).
 */
final class DbPull
{
    public function __construct(private readonly PrismaProject $project, private readonly Reporter $reporter) {}

    public function pull(bool $force = false): string
    {
        $source = $this->project->source();
        $ast = SchemaLoader::parse($source, $this->project->relative($this->project->schemaPath));
        if (! $force) {
            $this->project->document(); // re-introspection needs a valid schema
        }
        $datasource = $ast->blocks(Block::DATASOURCE)[0] ?? throw new PrismaException('Could not find a datasource in the schema.');
        $provider = $this->project->databaseUrl()->provider;
        $connection = $this->project->connect();
        $schema = DatabaseSchema::introspect($connection);
        if ($schema->tables === []) {
            throw new PrismaException(sprintf(
                'P4001 The introspected database was empty: %s. prisma db pull could not create any models in your schema.prisma file and you will not be able to generate Prisma Client with the Prisma models. Make sure that the database contains tables.',
                $this->project->databaseUrl()->location(),
            ));
        }
        $introspector = new SchemaIntrospector($schema, $connection->platform(), $provider, $datasource->name, $force ? null : $ast);
        $blocks = $introspector->blocks();
        $items = [];
        foreach ($ast->items as $item) {
            if ($item instanceof Block && ($item->kind === Block::MODEL || $item->kind === Block::ENUM)) {
                continue;
            }
            $items[] = $item;
        }
        while ($items !== [] && end($items) instanceof EmptyLine) {
            array_pop($items);
        }
        foreach ($blocks as $block) {
            $items[] = $block;
        }
        $models = count(array_filter($blocks, fn (Block $b) => $b->kind === Block::MODEL));
        $this->reporter->info(sprintf('Introspected %d model%s and wrote %s.', $models, $models === 1 ? '' : 's', 'them into the schema'));

        return Formatter::print(new SchemaAst($items));
    }
}
