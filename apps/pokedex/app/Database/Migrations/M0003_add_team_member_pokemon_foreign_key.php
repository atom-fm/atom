<?php

declare(strict_types=1);

use Atom\Database\Migration\Migration;
use Atom\Database\Schema\Schema;
use Atom\Database\Schema\Table;

return new class extends Migration
{
    public function up(Schema $schema): void
    {
        $this->rebuildTeamMembers($schema, withForeignKey: true);
    }

    public function down(Schema $schema): void
    {
        $this->rebuildTeamMembers($schema, withForeignKey: false);
    }

    private function rebuildTeamMembers(Schema $schema, bool $withForeignKey): void
    {
        $schema->statement("ALTER TABLE team_members RENAME TO previous_team_members");
        $schema->statement("DROP INDEX team_members_pokemon_id_unique");
        $schema->create("team_members", function (Table $table) use ($withForeignKey): void {
            $table->id();
            $table->integer("pokemon_id")->unique();
            if ($withForeignKey) {
                $table->foreignKey("pokemon_id", "pokemon", "id");
            }
        });

        $schema->statement("INSERT INTO team_members (id, pokemon_id) SELECT id, pokemon_id FROM previous_team_members");
        // Keep issued IDs from being reused, even when the highest member was deleted.
        $schema->statement("DELETE FROM sqlite_sequence WHERE name = 'team_members'");
        $schema->statement("UPDATE sqlite_sequence SET name = 'team_members' WHERE name = 'previous_team_members'");
        $schema->drop("previous_team_members");
    }
};
