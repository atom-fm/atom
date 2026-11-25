<?php

declare(strict_types=1);

use Atom\Database\Migration\Migration;
use Atom\Database\Schema\Schema;
use Atom\Database\Schema\Table;

return new class extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create("pokemon", function (Table $table): void {
            $table->integer("id")->primary();
            $table->string("name")->unique();
            $table->string("primary_type");
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop("pokemon");
    }
};
