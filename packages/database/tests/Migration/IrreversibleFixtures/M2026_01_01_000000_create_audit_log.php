<?php

declare(strict_types=1);

use Atom\Database\Migration\Migration;
use Atom\Database\Schema\Schema;
use Atom\Database\Schema\Table;

return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create("audit_log", static fn(Table $table) => $table->id());
    }
};
