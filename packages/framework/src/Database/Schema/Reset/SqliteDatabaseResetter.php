<?php

declare(strict_types=1);

namespace Atom\Database\Schema\Reset;

use Atom\Database\DatabaseConnection;

final readonly class SqliteDatabaseResetter implements DatabaseResetterInterface
{
    public function reset(DatabaseConnection $connection): void
    {
        $connection->execute("PRAGMA foreign_keys = OFF");

        try {
            $tables = $connection->all(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
            );

            foreach ($tables as $table) {
                $connection->execute("DROP TABLE " . $this->name((string) $table["name"]));
            }
        } finally {
            $connection->execute("PRAGMA foreign_keys = ON");
        }
    }

    private function name(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }
}
