<?php

declare(strict_types=1);

namespace Nesto\Orm\Migration;

/**
 * Base class of a migration.
 *
 *   final class CreateUsers20240101000000 extends Migration
 *   {
 *       public function up(Schema $schema): void
 *       {
 *           $schema->createTable('users', function (Blueprint $t) { $t->id(); $t->string('name'); $t->timestamps(); });
 *       }
 *
 *       public function down(Schema $schema): void
 *       {
 *           $schema->dropTable('users');
 *       }
 *   }
 */
abstract class Migration
{
    abstract public function up(Schema $schema): void;

    abstract public function down(Schema $schema): void;

    /** Name recorded in the migrations table (defaults to the class name). */
    public function name(): string
    {
        return static::class;
    }

    /**
     * TypeORM's `transaction` property: null follows the transaction mode; false for migrations that cannot
     * run inside a transaction (CREATE INDEX CONCURRENTLY — refused by the "all" mode, run them with "each"
     * or "none"); true asks for a transaction even in the "none" mode.
     */
    public function transactional(): ?bool
    {
        return null;
    }
}
