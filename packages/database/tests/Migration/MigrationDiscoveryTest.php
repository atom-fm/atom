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

final class MigrationDiscoveryTest extends TestCase
{
    public function testDuplicateFilenamesFailBeforeMigrationOrReset(): void
    {
        $driver = SqliteDriver::memory();
        $connection = new DatabaseConnection($driver);
        $repository = new DatabaseMigrationRepository($connection);
        $migrator = new Migrator(
            $connection,
            $repository,
            $driver->lockManager($connection),
            new MigrationDiscovery(),
            new MigrationOptions(__DIR__ . "/DuplicateFixtures"),
        );

        $resetCalled = false;
        try {
            $migrator->run(static function () use (&$resetCalled): void {
                $resetCalled = true;
            });
            self::fail("Expected duplicate migration filenames to be rejected.");
        } catch (RuntimeException $exception) {
            self::assertStringContainsString("Duplicate migration name 'M01'", $exception->getMessage());
            self::assertStringContainsString("/First/M01.php", $exception->getMessage());
            self::assertStringContainsString("/Second/M01.php", $exception->getMessage());
        }

        self::assertFalse($resetCalled);
        self::assertFalse($repository->exists());
        $inspector = $driver->schemaInspector($connection);
        self::assertFalse($inspector->hasTable("first_migration"));
        self::assertFalse($inspector->hasTable("second_migration"));
    }

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
