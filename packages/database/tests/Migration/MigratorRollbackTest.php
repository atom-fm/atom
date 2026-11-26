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
use RuntimeException;

final class MigratorRollbackTest extends TestCase
{
    public function testIrreversibleMigrationKeepsItsTableAndHistory(): void
    {
        $driver = SqliteDriver::memory();
        $connection = new DatabaseConnection($driver);
        $repository = new DatabaseMigrationRepository($connection);
        $migrator = new Migrator(
            $connection,
            $repository,
            $driver->lockManager($connection),
            new MigrationDiscovery(),
            new MigrationOptions(__DIR__ . "/IrreversibleFixtures"),
        );
        $name = "M2026_01_01_000000_create_audit_log";
        self::assertSame([$name], $migrator->run()->migrations);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("This migration is irreversible because it does not implement down().");

        try {
            $migrator->rollback();
        } finally {
            self::assertSame([$name], $repository->applied());
            self::assertSame(1, $repository->latestBatch());
            self::assertTrue($driver->schemaInspector($connection)->hasTable("audit_log"));
        }
    }
}
