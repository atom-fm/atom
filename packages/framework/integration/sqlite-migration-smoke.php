<?php

declare(strict_types=1);

use Atom\Database\DatabaseConnection;
use Atom\Database\Driver\SqliteDriver;
use Atom\Database\Migration\DatabaseMigrationRepository;
use Atom\Database\Migration\MigrationDiscovery;
use Atom\Database\Migration\MigrationOptions;
use Atom\Database\Migration\Migrator;
use Atom\Database\Sql\Query;

require $argv[1] ?? dirname(__DIR__) . "/vendor/autoload.php";

$driver = SqliteDriver::memory();
$connection = new DatabaseConnection($driver);
$repository = new DatabaseMigrationRepository($connection);
$inspector = $driver->schemaInspector($connection);
$migrator = new Migrator(
    $connection,
    $repository,
    $driver->lockManager($connection),
    new MigrationDiscovery(),
    new MigrationOptions(__DIR__ . "/../tests/Database/Migration/Fixtures"),
);

$expected = [
    "M26_01_01_000000_create_migration_users",
    "M26_01_01_000100_create_migration_posts",
];
if ($migrator->run()->migrations !== $expected || $repository->applied() !== $expected) {
    throw new RuntimeException("SQLite migration batch was not applied and recorded.");
}

$connection->execute(Query::insert("migration_users")->values(["name" => "Ada"]));
if ($connection->scalar(Query::select("migration_users")->columns("name")) !== "Ada") {
    throw new RuntimeException("Could not read the row inserted into the migrated table.");
}

if ($migrator->rollback()->migrations !== array_reverse($expected) || $repository->applied() !== []) {
    throw new RuntimeException("SQLite migration batch was not rolled back and cleared from history.");
}
foreach (["migration_users", "seed_users", "migration_posts"] as $table) {
    if ($inspector->hasTable($table)) {
        throw new RuntimeException("Table '{$table}' still exists after rollback.");
    }
}

echo "SQLite migration smoke test passed.\n";
