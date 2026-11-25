<?php

declare(strict_types=1);

use Atom\Database\DatabaseConnection;
use Atom\Database\Seeder\Seeder;

return new class extends Seeder
{
    public function run(DatabaseConnection $connection): void
    {
        $connection->execute(
            "INSERT INTO pokemon (id, name, primary_type)
             VALUES (1, 'bulbasaur', 'grass'),
                    (4, 'charmander', 'fire'),
                    (7, 'squirtle', 'water'),
                    (25, 'pikachu', 'electric'),
                    (39, 'jigglypuff', 'normal'),
                    (133, 'eevee', 'normal')
             ON CONFLICT (id) DO NOTHING"
        );
    }
};
