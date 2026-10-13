<?php

declare(strict_types=1);

namespace Nesto\Prisma\Schema\Ast;

/** A value in an attribute argument or a datasource / generator property. */
abstract class Expression extends Node
{
    /** The expression as the formatter prints it. */
    abstract public function toSource(): string;

    /** Short description for error messages ("string", "array", "function call", ...). */
    abstract public function kind(): string;
}
