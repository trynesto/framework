<?php

declare(strict_types=1);

namespace Nesto\Prisma\Client;

/**
 * Prisma's null kinds for Json fields (`Prisma.JsonNull`, `Prisma.DbNull`, `Prisma.AnyNull`): a PHP null cannot
 * tell "the JSON value null" from "the column is NULL".
 *
 *   ['data' => ['meta' => Prisma::JsonNull]]          // stores the JSON value null
 *   ['data' => ['meta' => Prisma::DbNull]]            // stores SQL NULL (same as PHP null)
 *   ['where' => ['meta' => ['equals' => Prisma::AnyNull]]]
 */
enum NullType
{
    case JsonNull;
    case DbNull;
    case AnyNull;
}
