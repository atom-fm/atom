<?php

declare(strict_types=1);

namespace Atom\Tests\Database\Migration;

use Atom\Database\DatabaseConnection;
use Atom\Database\Driver\SqliteDriver;
use Atom\Database\Migration\DatabaseMigrationRepository;
use Atom\Database\Migration\MigrationDiscovery;
use Atom\Database\Migration\MigrationOptions;
use Atom\Database\Migration\Migrator;
use PHPUnit\Framework\TestCase;

final class MigrationDiscoveryTest extends TestCase
{
    public function testNamedMigrationCanRunAfterStatusInTheSameProcess(): void
    {
        $driver = SqliteDriver::memory();
        $connection = new DatabaseConnection($driver);
        $repository = new DatabaseMigrationRepository($connection);
        $migrator = new Migrator(
            $connection,
            $repository,
            $driver->lockManager($connection),
            new MigrationDiscovery(),
            new MigrationOptions(__DIR__ . "/Fixtures", __NAMESPACE__ . "\\Fixtures"),
        );
        $name = "M2026_01_01_000000_create_named_users";

        $statuses = $migrator->status();
        self::assertCount(1, $statuses);
        self::assertSame($name, $statuses[0]->name);
        self::assertFalse($statuses[0]->applied);

        self::assertSame([$name], $migrator->run()->migrations);
        self::assertSame([$name], $repository->applied());
        $inspector = $driver->schemaInspector($connection);
        self::assertTrue($inspector->hasTable("named_users"));

        self::assertSame([$name], $migrator->rollback()->migrations);
        self::assertFalse($inspector->hasTable("named_users"));
    }
}
