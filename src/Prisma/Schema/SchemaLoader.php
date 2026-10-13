<?php

declare(strict_types=1);

namespace Nesto\Prisma\Schema;

use Nesto\Prisma\Dmmf\Document;
use Nesto\Prisma\PrismaException;
use Nesto\Prisma\Schema\Ast\SchemaAst;

/**
 * Entry point for reading schema.prisma:
 *
 *   $document = SchemaLoader::loadFile('prisma/schema.prisma');   // parse + validate → DMMF Document
 *   $document = SchemaLoader::load($source);
 *   $ast = SchemaLoader::parse($source);                          // syntax only (formatter)
 *   $diagnostics = SchemaLoader::diagnose($source);               // every error and warning, no exception
 *
 * Errors throw SchemaException, whose message is Prisma's report (P1012) with every error located.
 */
final class SchemaLoader
{
    public static function loadFile(string $path): Document
    {
        if (! is_file($path)) {
            throw new PrismaException("Could not find Prisma Schema that is required for this command.\nChecked following paths:\n\n$path: file not found");
        }

        return self::load((string) file_get_contents($path), $path);
    }

    public static function load(string $source, string $file = 'schema.prisma'): Document
    {
        $parser = new Parser($source);
        $ast = $parser->parse();

        return (new SchemaValidator())->validate($ast, $source, $file, $parser->diagnostics);
    }

    /** Parse only; throws SchemaException on syntax errors. */
    public static function parse(string $source, string $file = 'schema.prisma'): SchemaAst
    {
        $parser = new Parser($source);
        $ast = $parser->parse();
        if (array_filter($parser->diagnostics, fn (Diagnostic $d) => $d->isError()) !== []) {
            throw new SchemaException($parser->diagnostics, $source, $file);
        }

        return $ast;
    }

    /** @return list<Diagnostic> */
    public static function diagnose(string $source): array
    {
        $parser = new Parser($source);
        $ast = $parser->parse();
        $validator = new SchemaValidator();
        $validator->build($ast, $parser->diagnostics);

        return $validator->diagnostics();
    }
}
