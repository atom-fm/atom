<?php

declare(strict_types=1);

namespace Atom\Tests\Integration;

use Atom\Database\DatabaseConnection;
use Atom\Database\DatabaseDriverInterface;
use Atom\Database\Driver\MySqlDriver;
use Atom\Database\Driver\PostgresDriver;
use Atom\Database\Migration\DatabaseMigrationRepository;
use Atom\Database\Migration\MigrationDiscovery;
use Atom\Database\Migration\MigrationOptions;
use Atom\Database\Migration\Migrator;
use Atom\Database\Sql\Query;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MigrationIntegrationTest extends TestCase
{
    /** @return iterable<string, array{DatabaseDriverInterface}> */
    public static function databases(): iterable
    {
        $options = [PDO::ATTR_EMULATE_PREPARES => false];

        yield "MySQL" => [new MySqlDriver("atom_orm_test", "127.0.0.1", "atom_test", "atom_test", 13306, options: $options)];
        yield "PostgreSQL" => [new PostgresDriver("atom_orm_test", "127.0.0.1", "atom_test", "atom_test", 15432, options: $options)];
    }

    #[DataProvider("databases")]
    public function testAppliesAndRollsBackMigrationBatch(DatabaseDriverInterface $driver): void
    {
        $connection = new DatabaseConnection($driver);
        $repository = new DatabaseMigrationRepository($connection, "integration_migrations");
        $inspector = $driver->schemaInspector($connection);
        $migrator = new Migrator(
            $connection,
            $repository,
            $driver->lockManager($connection),
            new MigrationDiscovery(),
            new MigrationOptions(__DIR__ . "/../tests/Database/Migration/Fixtures"),
        );
        $migrations = [
            "M26_01_01_000000_create_migration_users",
            "M26_01_01_000100_create_migration_posts",
        ];
        $tables = ["migration_users", "seed_users", "migration_posts"];

        self::assertSame([], $repository->applied());
        self::assertSame($migrations, $migrator->run()->migrations);
        self::assertSame($migrations, $repository->applied());
        self::assertSame(1, $repository->latestBatch());
        foreach ($tables as $table) {
            self::assertTrue($inspector->hasTable($table), $table);
        }
        $connection->execute(Query::insert("migration_users")->values(["name" => "Ada"]));
        self::assertSame("Ada", $connection->scalar(Query::select("migration_users")->columns("name")));
        self::assertTrue($migrator->run()->isEmpty());

        self::assertSame(array_reverse($migrations), $migrator->rollback()->migrations);
        self::assertSame([], $repository->applied());
        self::assertSame(0, $repository->latestBatch());
        foreach ($tables as $table) {
            self::assertFalse($inspector->hasTable($table), $table);
        }
    }
}
