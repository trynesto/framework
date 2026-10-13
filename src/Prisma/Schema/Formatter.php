<?php

declare(strict_types=1);

namespace Nesto\Prisma\Schema;

use Nesto\Prisma\Schema\Ast\Argument;
use Nesto\Prisma\Schema\Ast\ArgumentList;
use Nesto\Prisma\Schema\Ast\ArrayValue;
use Nesto\Prisma\Schema\Ast\Attribute;
use Nesto\Prisma\Schema\Ast\Block;
use Nesto\Prisma\Schema\Ast\Comment;
use Nesto\Prisma\Schema\Ast\ConstantValue;
use Nesto\Prisma\Schema\Ast\EmptyLine;
use Nesto\Prisma\Schema\Ast\EnumValue;
use Nesto\Prisma\Schema\Ast\Field;
use Nesto\Prisma\Schema\Ast\FieldType;
use Nesto\Prisma\Schema\Ast\Node;
use Nesto\Prisma\Schema\Ast\Property;
use Nesto\Prisma\Schema\Ast\SchemaAst;
use Nesto\Prisma\Schema\Ast\StringValue;

/**
 * `prisma format`: prints a schema the way Prisma's formatter does.
 *
 *   - two-space indentation, one blank line between blocks, at most one blank line inside a block
 *   - field names, types and attributes aligned in columns; a blank line or a block attribute starts a new
 *     alignment group, comment lines do not
 *   - datasource / generator keys aligned on "=", enum values and their attributes aligned
 *   - attribute arguments normalized (`@relation(fields: [a], references: [id])`)
 *   - comments and `///` documentation kept; missing back-relation fields added (`fixRelations`)
 *
 *   $formatted = Formatter::format($source);
 */
final class Formatter
{
    /** Format source text; throws SchemaException on syntax errors. */
    public static function format(string $source, bool $fixRelations = true): string
    {
        $ast = SchemaLoader::parse($source);
        if ($fixRelations) {
            $ast = self::addMissingBackRelations($ast);
        }

        return self::print($ast);
    }

    public static function print(SchemaAst $ast): string
    {
        $chunks = [];
        $pendingBlank = false;
        foreach ($ast->items as $item) {
            if ($item instanceof EmptyLine) {
                $pendingBlank = $chunks !== [];
                continue;
            }
            $text = $item instanceof Block ? self::block($item) : '//' . ($item instanceof Comment ? $item->text : '');
            $previousIsBlock = $chunks !== [] && $chunks[count($chunks) - 1]['block'];
            $separator = $chunks === [] ? '' : (($pendingBlank || $previousIsBlock) ? "\n\n" : "\n");
            $chunks[] = ['text' => $separator . $text, 'block' => $item instanceof Block];
            $pendingBlank = false;
        }

        return implode('', array_column($chunks, 'text')) . "\n";
    }

    private static function block(Block $block): string
    {
        $lines = [];
        foreach (self::docLines($block->documentation, '') as $line) {
            $lines[] = $line;
        }
        $lines[] = $block->kind . ' ' . $block->name . ' {' . ($block->trailingComment !== null ? ' //' . $block->trailingComment : '');
        $group = [];
        $flush = function () use (&$group, &$lines, $block): void {
            if ($group !== []) {
                array_push($lines, ...self::table($group, $block->kind));
                $group = [];
            }
        };
        $lastWasBlank = true;
        foreach ($block->members as $member) {
            if ($member instanceof EmptyLine) {
                if (! $lastWasBlank) {
                    $group[] = ['blank' => true];
                }
                $lastWasBlank = true;
                continue;
            }
            $lastWasBlank = false;
            if ($member instanceof Comment) {
                $group[] = ['comment' => '//' . $member->text];
                continue;
            }
            if ($member instanceof Attribute) {
                $group[] = ['attribute' => $member];
                continue;
            }
            $group[] = ['member' => $member];
        }
        while ($group !== [] && isset($group[count($group) - 1]['blank'])) {
            array_pop($group);
        }
        $flush();
        $lines[] = '}';

        return implode("\n", $lines);
    }

    /**
     * Render one block body: rows between blank lines / block attributes are aligned together.
     *
     * @param list<array<string, mixed>> $entries
     * @return list<string>
     */
    private static function table(array $entries, string $kind): array
    {
        $out = [];
        $segment = [];
        $render = function () use (&$segment, &$out, $kind): void {
            if ($segment === []) {
                return;
            }
            $rows = [];
            foreach ($segment as $entry) {
                if (isset($entry['member'])) {
                    $rows[] = self::columns($entry['member'], $kind);
                }
            }
            $widths = [];
            foreach ($rows as $row) {
                foreach ($row['cells'] as $i => $cell) {
                    $widths[$i] = max($widths[$i] ?? 0, mb_strlen($cell));
                }
            }
            $rowIndex = 0;
            foreach ($segment as $entry) {
                if (isset($entry['comment'])) {
                    $out[] = '  ' . $entry['comment'];
                    continue;
                }
                $row = $rows[$rowIndex++];
                foreach (self::docLines($row['doc'], '  ') as $line) {
                    $out[] = $line;
                }
                $cells = $row['cells'];
                while ($cells !== [] && end($cells) === '') {
                    array_pop($cells);
                }
                $text = '';
                $last = count($cells) - 1;
                foreach ($cells as $i => $cell) {
                    $text .= $i === $last && $row['comment'] === null ? $cell : $cell . str_repeat(' ', $widths[$i] - mb_strlen($cell) + ($i === $last ? 0 : 1));
                }
                if ($row['comment'] !== null) {
                    $text = rtrim($text) . ' //' . $row['comment'];
                }
                $out[] = '  ' . rtrim($text);
            }
            $segment = [];
        };
        foreach ($entries as $entry) {
            if (isset($entry['blank'])) {
                $render();
                $out[] = '';
                continue;
            }
            if (isset($entry['attribute'])) {
                $render();
                /** @var Attribute $attribute */
                $attribute = $entry['attribute'];
                foreach (self::docLines($attribute->documentation, '  ') as $line) {
                    $out[] = $line;
                }
                $out[] = '  ' . self::attribute($attribute) . ($attribute->trailingComment !== null ? ' //' . $attribute->trailingComment : '');
                continue;
            }
            $segment[] = $entry;
        }
        $render();

        return $out;
    }

    /** @return array{cells: list<string>, doc: ?string, comment: ?string} */
    private static function columns(Node $member, string $kind): array
    {
        if ($member instanceof Field) {
            return [
                'cells' => [$member->name, self::fieldType($member->type), implode(' ', array_map(fn (Attribute $a) => self::attribute($a), $member->attributes))],
                'doc' => $member->documentation,
                'comment' => $member->trailingComment,
            ];
        }
        if ($member instanceof EnumValue) {
            return [
                'cells' => [$member->name, implode(' ', array_map(fn (Attribute $a) => self::attribute($a), $member->attributes))],
                'doc' => $member->documentation,
                'comment' => $member->trailingComment,
            ];
        }
        /** @var Property $member */
        return ['cells' => [$member->key, '= ' . $member->value->toSource()], 'doc' => $member->documentation, 'comment' => $member->trailingComment];
    }

    private static function fieldType(FieldType $type): string
    {
        return $type->toSource();
    }

    public static function attribute(Attribute $attribute): string
    {
        return $attribute->toSource();
    }

    /** @return list<string> */
    private static function docLines(?string $documentation, string $indent): array
    {
        if ($documentation === null) {
            return [];
        }

        return array_map(fn (string $line) => $indent . '///' . ($line !== '' ? ' ' . $line : ''), explode("\n", $documentation));
    }

    // ---- back relations ---------------------------------------------------------------------------

    /**
     * Add the opposite field of relations that declare only one side (what `prisma format` does):
     *   - a field with `fields` / `references` (Post.author User) gets `Post Post[]` on User
     *   - a list field (User.posts Post[]) gets `User User? @relation(fields: [userId], references: [id])`
     *     plus `userId Int?` on Post
     */
    public static function addMissingBackRelations(SchemaAst $ast): SchemaAst
    {
        $models = [];
        foreach ($ast->models() as $block) {
            $models[$block->name] = $block;
        }
        $additions = [];
        foreach ($models as $name => $block) {
            foreach ($block->fields() as $field) {
                $target = $models[$field->type->name] ?? null;
                if ($target === null) {
                    continue;
                }
                $relationName = self::relationName($field);
                $hasOpposite = false;
                foreach ([...$target->fields(), ...($additions[$target->name] ?? [])] as $candidate) {
                    if ($candidate->type->name === $name && self::relationName($candidate) === $relationName && ! ($target === $block && $candidate->name === $field->name)) {
                        $hasOpposite = true;
                        break;
                    }
                }
                if ($hasOpposite || $target === $block) {
                    continue;
                }
                $relation = $field->attribute('relation');
                $hasFields = $relation !== null && $relation->arguments->get('fields') !== null;
                $existing = array_map(fn (Field $f) => $f->name, [...$target->fields(), ...($additions[$target->name] ?? [])]);
                if ($hasFields || ! $field->type->list && $relation !== null) {
                    if (in_array($name, $existing, true)) {
                        continue;
                    }
                    $additions[$target->name][] = self::syntheticField($name, new FieldType($name, false, true, $field->span), $relationName !== null ? [self::relationAttribute($relationName, [])] : []);
                    continue;
                }
                if (! $field->type->list) {
                    continue; // a 1:1 side without fields: which side should own the key is ambiguous
                }
                $id = self::idField($block);
                if ($id === null) {
                    continue;
                }
                $scalar = lcfirst($name) . ucfirst($id->name);
                if (in_array($name, $existing, true) || in_array($scalar, $existing, true)) {
                    continue;
                }
                $additions[$target->name][] = self::syntheticField($name, new FieldType($name, true, false, $field->span), [
                    self::relationAttribute($relationName, ['fields' => [$scalar], 'references' => [$id->name]]),
                ]);
                $additions[$target->name][] = self::syntheticField($scalar, new FieldType($id->type->name, true, false, $field->span), []);
            }
        }
        if ($additions === []) {
            return $ast;
        }
        $items = [];
        foreach ($ast->items as $item) {
            if ($item instanceof Block && isset($additions[$item->name]) && ($item->kind === Block::MODEL || $item->kind === Block::VIEW)) {
                $members = $item->members;
                // after the last field (before trailing blank lines / block attributes)
                $position = 0;
                foreach ($members as $i => $member) {
                    if ($member instanceof Field) {
                        $position = $i + 1;
                    }
                }
                array_splice($members, $position, 0, $additions[$item->name]);
                $item = $item->withMembers($members);
            }
            $items[] = $item;
        }

        return $ast->withItems($items);
    }

    private static function relationName(Field $field): ?string
    {
        $relation = $field->attribute('relation');
        $name = $relation?->arguments->get('name', 0)?->value;

        return $name instanceof StringValue ? $name->value : null;
    }

    private static function idField(Block $block): ?Field
    {
        foreach ($block->fields() as $field) {
            if ($field->attribute('id') !== null) {
                return $field;
            }
        }

        return null;
    }

    /** @param list<Attribute> $attributes */
    private static function syntheticField(string $name, FieldType $type, array $attributes): Field
    {
        return new Field($name, $type->span, $type, $attributes, $type->span);
    }

    /** @param array<string, list<string>> $arguments */
    private static function relationAttribute(?string $name, array $arguments): Attribute
    {
        $span = Span::at(0, 0);
        $args = [];
        if ($name !== null) {
            $args[] = new Argument(null, new StringValue($name, StringValue::quote($name), $span), $span);
        }
        foreach ($arguments as $key => $fields) {
            $args[] = new Argument($key, new ArrayValue(array_map(fn (string $f) => new ConstantValue($f, $span), $fields), $span), $span);
        }

        return new Attribute('relation', new ArgumentList($args), false, $span, $span, true);
    }
}
