<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures;

use Nesto\Orm\Migration\Blueprint;
use Nesto\Orm\Migration\Migration;
use Nesto\Orm\Migration\Schema;

final class CreateAuthorsMigration extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->createTable('authors', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->boolean('active')->default(true);
            $t->json('meta')->nullable();
            $t->decimal('rating', 4, 2)->default(0);
            $t->enum('role', ['writer', 'editor'])->default('writer');
            $t->timestamps();
            $t->softDeletes();
            $t->index(['name']);
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('authors');
    }
}
