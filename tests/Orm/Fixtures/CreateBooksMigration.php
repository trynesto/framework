<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures;

use Nesto\Orm\Migration\Blueprint;
use Nesto\Orm\Migration\Migration;
use Nesto\Orm\Migration\Schema;

final class CreateBooksMigration extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->createTable('books', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('title', 100);
            $t->text('summary')->nullable();
            $t->integer('pages')->default(0);
            $t->bigInteger('isbn')->nullable();
            $t->float('weight')->nullable();
            $t->date('published_on')->nullable();
            $t->datetime('printed_at')->nullable();
            $t->timestamp('archived_at')->nullable();
            $t->foreignId('author_id')->references('id')->on('authors')->onDelete('CASCADE');
            $t->unique(['title', 'author_id']);
        });
        $schema->addColumn('books', fn (Blueprint $t) => $t->string('subtitle')->nullable());
        $schema->renameColumn('books', 'subtitle', 'tagline');
    }

    public function down(Schema $schema): void
    {
        $schema->dropColumn('books', 'tagline');
        $schema->dropTable('books');
    }
}
