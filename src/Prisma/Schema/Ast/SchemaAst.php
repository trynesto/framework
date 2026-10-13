<?php

declare(strict_types=1);

namespace Nesto\Prisma\Schema\Ast;

/** A parsed schema file: top-level Block, Comment and EmptyLine nodes in source order. */
final class SchemaAst
{
    /** @param list<Node> $items */
    public function __construct(public readonly array $items) {}

    /** @return list<Block> */
    public function blocks(?string $kind = null): array
    {
        return array_values(array_filter($this->items, fn (Node $n) => $n instanceof Block && ($kind === null || $n->kind === $kind)));
    }

    public function block(string $kind, string $name): ?Block
    {
        foreach ($this->blocks($kind) as $block) {
            if ($block->name === $name) {
                return $block;
            }
        }

        return null;
    }

    /** Models and views (both declare fields). @return list<Block> */
    public function models(): array
    {
        return array_values(array_filter($this->blocks(), fn (Block $b) => $b->kind === Block::MODEL || $b->kind === Block::VIEW));
    }

    /** A copy with other top-level items. @param list<Node> $items */
    public function withItems(array $items): self
    {
        return new self($items);
    }
}
