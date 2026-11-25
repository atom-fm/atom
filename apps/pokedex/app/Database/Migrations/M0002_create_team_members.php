<?php

declare(strict_types=1);

use Atom\Database\Migration\Migration;
use Atom\Database\Schema\Schema;
use Atom\Database\Schema\Table;

return new class extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create("team_members", function (Table $table): void {
            $table->id();
            $table->integer("pokemon_id")->unique();
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop("team_members");
    }
};
