<?php

declare(strict_types=1);

namespace Atom\Tests\Database\Migration\Fixtures;

use Atom\Database\Migration\Migration;
use Atom\Database\Schema\Schema;
use Atom\Database\Schema\Table;

final class M2026_01_01_000000_create_named_users extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create("named_users", static fn(Table $table) => $table->id());
    }

    public function down(Schema $schema): void
    {
        $schema->drop("named_users");
    }
}
