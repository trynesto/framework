<?php

declare(strict_types=1);

namespace Nesto\Prisma\Schema;

use Nesto\Prisma\PrismaException;

/**
 * A schema that does not parse or validate (Prisma's P1012). The message is the full Prisma-style report
 * (every error with its location and source excerpt, then "Validation Error Count: N"); `diagnostics` holds
 * the individual errors.
 */
final class SchemaException extends PrismaException
{
    /** @param list<Diagnostic> $diagnostics */
    public function __construct(public readonly array $diagnostics, public readonly string $source = '', public readonly string $schemaFile = 'schema.prisma')
    {
        parent::__construct(self::report($diagnostics, $source, $schemaFile));
    }

    /** @param list<Diagnostic> $diagnostics */
    public static function report(array $diagnostics, string $source, string $file): string
    {
        $errors = array_values(array_filter($diagnostics, fn (Diagnostic $d) => $d->isError()));
        $out = "Prisma schema validation - Error code: P1012\n";
        foreach ($errors as $diagnostic) {
            $out .= $diagnostic->render($source, $file) . "\n";
        }

        return $out . 'Validation Error Count: ' . count($errors);
    }

    /** The error messages alone ("12:3: Field ..."). @return list<string> */
    public function messages(): array
    {
        return array_values(array_map(fn (Diagnostic $d) => (string) $d, array_filter($this->diagnostics, fn (Diagnostic $d) => $d->isError())));
    }
}
