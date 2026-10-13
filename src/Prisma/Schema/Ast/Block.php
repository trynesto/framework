<?php

declare(strict_types=1);

namespace Nesto\Prisma\Schema\Ast;

use Nesto\Prisma\Schema\Span;

/**
 * A top-level block: `datasource`, `generator`, `model`, `view`, `enum` or `type`. `members` keeps everything
 * inside it in source order — Field / EnumValue / Property, block Attribute, Comment and EmptyLine nodes.
 */
final class Block extends Node
{
    public const DATASOURCE = 'datasource';
    public const GENERATOR = 'generator';
    public const MODEL = 'model';
    public const VIEW = 'view';
    public const ENUM = 'enum';
    public const TYPE = 'type';

    /** @param list<Node> $members */
    public function __construct(
        public readonly string $kind,
        public readonly string $name,
        public readonly Span $nameSpan,
        public readonly array $members,
        Span $span,
        public readonly ?string $documentation = null,
        public readonly ?string $trailingComment = null,
    ) {
        parent::__construct($span);
    }

    /** @return list<Field> */
    public function fields(): array
    {
        return array_values(array_filter($this->members, fn (Node $n) => $n instanceof Field));
    }

    public function field(string $name): ?Field
    {
        foreach ($this->members as $member) {
            if ($member instanceof Field && $member->name === $name) {
                return $member;
            }
        }

        return null;
    }

    /** @return list<EnumValue> */
    public function values(): array
    {
        return array_values(array_filter($this->members, fn (Node $n) => $n instanceof EnumValue));
    }

    /** @return list<Property> */
    public function properties(): array
    {
        return array_values(array_filter($this->members, fn (Node $n) => $n instanceof Property));
    }

    public function property(string $key): ?Property
    {
        foreach ($this->members as $member) {
            if ($member instanceof Property && $member->key === $key) {
                return $member;
            }
        }

        return null;
    }

    /** Block attributes (@@...), optionally only those named $name. @return list<Attribute> */
    public function attributes(?string $name = null): array
    {
        return array_values(array_filter($this->members, fn (Node $n) => $n instanceof Attribute && ($name === null || $n->name === $name)));
    }

    /** A copy with other members (the formatter adds back-relation fields). @param list<Node> $members */
    public function withMembers(array $members): self
    {
        return new self($this->kind, $this->name, $this->nameSpan, $members, $this->span, $this->documentation, $this->trailingComment);
    }
}
