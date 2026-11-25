<?php

declare(strict_types=1);

namespace Atom\Tests\Database\Migration;

use Atom\Console\BufferedConsoleOutput;
use Atom\Console\ConsoleApplication;
use Atom\Console\ConsoleServices;
use Atom\Database\DatabaseConnection;
use Atom\Database\DatabaseServices;
use Atom\Database\Driver\SqliteDriver;
use Atom\Database\Lock\DatabaseLockManagerInterface;
use Atom\Database\Migration\DatabaseMigrationRepository;
use Atom\Database\Migration\MigrationDiscovery;
use Atom\Database\Migration\MigrationOptions;
use Atom\Database\Migration\Migrator;
use Atom\Database\Seeder\SeederOptions;
use Atom\Di\Injector;
use Atom\Di\ServiceProviderRegistry;
use PDOException;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    public function testDiscoversPendingMigrationsAndBuildsSql(): void
    {
        $migrator = $this->migrator();

        $this->assertSame([
            "M26_01_01_000000_create_migration_users",
            "M26_01_01_000100_create_migration_posts",
        ], array_map(static fn($migration): string => $migration->name, $migrator->pending()));

        $sql = $migrator->sql();

        $this->assertArrayHasKey("M26_01_01_000000_create_migration_users", $sql);
        $this->assertStringContainsString("CREATE TABLE", $sql["M26_01_01_000000_create_migration_users"][0]);
        $this->assertStringContainsString("migration_users", $sql["M26_01_01_000000_create_migration_users"][0]);
    }

    public function testRunsPendingMigrationsAndRecordsThem(): void
    {
        $connection = new DatabaseConnection(SqliteDriver::memory());
        $repository = new DatabaseMigrationRepository($connection);
        $migrator = $this->migrator($connection, $repository);

        $result = $migrator->run();

        $this->assertSame(2, $result->count());
        $this->assertSame([
            "M26_01_01_000000_create_migration_users",
            "M26_01_01_000100_create_migration_posts",
        ], $repository->applied());
        $this->assertSame(1, (int) $connection->scalar(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'migration_users'"
        ));

        $secondRun = $migrator->run();

        $this->assertTrue($secondRun->isEmpty());
    }

    public function testRollsBackLatestBatchInReverseOrder(): void
    {
        $connection = new DatabaseConnection(SqliteDriver::memory());
        $repository = new DatabaseMigrationRepository($connection);
        $migrator = $this->migrator($connection, $repository);

        $migrator->run();

        $result = $migrator->rollback();

        $this->assertSame([
            "M26_01_01_000100_create_migration_posts",
            "M26_01_01_000000_create_migration_users",
        ], $result->migrations);
        $this->assertSame([], $repository->applied());
        $this->assertSame(0, (int) $connection->scalar(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'migration_posts'"
        ));
        $this->assertSame(0, (int) $connection->scalar(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'migration_users'"
        ));
    }

    public function testFailedMigrationUndoesEarlierSchemaCommandsAndCanBeRetried(): void
    {
        $connection = new DatabaseConnection(SqliteDriver::memory());
        $repository = new DatabaseMigrationRepository($connection);
        $migrator = $this->migrator($connection, $repository);
        $connection->execute("CREATE TABLE seed_users (id INTEGER)");

        try {
            $migrator->run();
            $this->fail("Expected the second CREATE TABLE to fail.");
        } catch (PDOException $e) {
            $this->assertStringContainsString("already exists", $e->getMessage());
        }

        $this->assertFalse($connection->driver()->schemaInspector($connection)->hasTable("migration_users"));
        $this->assertSame([], $repository->applied());
        $connection->execute("DROP TABLE seed_users");
        $this->assertSame(2, $migrator->run()->count());
    }

    public function testFailedMigrationPreservesTheCallersTransaction(): void
    {
        $connection = new DatabaseConnection(SqliteDriver::memory());
        $repository = new DatabaseMigrationRepository($connection);
        $migrator = $this->migrator($connection, $repository);
        $schema = $connection->driver()->schemaInspector($connection);
        $pdo = $connection->pdo();
        $pdo->beginTransaction();
        try {
            $connection->execute("CREATE TABLE seed_users (id INTEGER)");
            try {
                $migrator->run();
                $this->fail("Expected the second CREATE TABLE to fail.");
            } catch (PDOException $e) {
                $this->assertStringContainsString("already exists", $e->getMessage());
            }

            $this->assertTrue($pdo->inTransaction());
            $this->assertTrue($schema->hasTable("seed_users"));
            $this->assertFalse($schema->hasTable("migration_users"));
            $connection->execute("DROP TABLE seed_users");
            $this->assertSame(2, $migrator->run()->count());
        } finally {
            $pdo->rollBack();
        }
        $this->assertFalse($schema->hasTable("migration_users"));
        $this->assertFalse($repository->exists());
    }

    public function testFailedRollbackRestoresDroppedTableAndItsRows(): void
    {
        $connection = new DatabaseConnection(SqliteDriver::memory());
        $repository = new DatabaseMigrationRepository($connection);
        $migrator = $this->migrator($connection, $repository);
        $migrator->run();
        $connection->execute("INSERT INTO seed_users (name) VALUES ('keep me')");
        $connection->execute("DROP TABLE migration_users");

        try {
            $migrator->rollback();
            $this->fail("Expected the second DROP TABLE to fail.");
        } catch (PDOException $e) {
            $this->assertStringContainsString("no such table", $e->getMessage());
        }

        $this->assertSame(["M26_01_01_000000_create_migration_users"], $repository->applied());
        $this->assertFalse($connection->driver()->schemaInspector($connection)->hasTable("migration_posts"));
        $this->assertSame("keep me", $connection->scalar("SELECT name FROM seed_users"));
        $connection->execute("CREATE TABLE migration_users (id INTEGER)");
        $this->assertSame(1, $migrator->rollback()->count());
    }

    public function testFailedHistoryInsertUndoesOnlyTheCurrentMigration(): void
    {
        $connection = new DatabaseConnection(SqliteDriver::memory());
        $repository = new DatabaseMigrationRepository($connection);
        $migrator = $this->migrator($connection, $repository);
        $repository->create();
        $connection->execute("CREATE TRIGGER fail_record AFTER INSERT ON migrations
            WHEN NEW.migration = 'M26_01_01_000100_create_migration_posts'
            BEGIN SELECT RAISE(FAIL, 'history insert failed'); END");

        try {
            $migrator->run();
            $this->fail("Expected recording the second migration to fail.");
        } catch (PDOException $e) {
            $this->assertStringContainsString("history insert failed", $e->getMessage());
        }

        $schema = $connection->driver()->schemaInspector($connection);
        $this->assertTrue($schema->hasTable("migration_users"));
        $this->assertFalse($schema->hasTable("migration_posts"));
        $this->assertSame(["M26_01_01_000000_create_migration_users"], $repository->applied());
        $connection->execute("DROP TRIGGER fail_record");
        $this->assertSame(["M26_01_01_000100_create_migration_posts"], $migrator->run()->migrations);
    }

    public function testFailedHistoryDeleteRestoresSchemaDataAndHistory(): void
    {
        $connection = new DatabaseConnection(SqliteDriver::memory());
        $repository = new DatabaseMigrationRepository($connection);
        $migrator = $this->migrator($connection, $repository);
        $applied = $migrator->run()->migrations;
        $connection->execute("INSERT INTO migration_posts (title) VALUES ('keep me')");
        $connection->execute("CREATE TRIGGER fail_delete AFTER DELETE ON migrations
            BEGIN SELECT RAISE(FAIL, 'history delete failed'); END");

        try {
            $migrator->rollback();
            $this->fail("Expected deleting migration history to fail.");
        } catch (PDOException $e) {
            $this->assertStringContainsString("history delete failed", $e->getMessage());
        }

        $this->assertSame($applied, $repository->applied());
        $this->assertSame("keep me", $connection->scalar("SELECT title FROM migration_posts"));
        $connection->execute("DROP TRIGGER fail_delete");
        $this->assertSame(2, $migrator->rollback()->count());
    }

    public function testRollbackCanRollBackMultipleBatches(): void
    {
        $connection = new DatabaseConnection(SqliteDriver::memory());
        $repository = new DatabaseMigrationRepository($connection);

        $this->migratorWithOptions(
            $connection,
            $repository,
            new MigrationOptions(__DIR__ . "/FirstBatchFixtures")
        )->run();
        $this->migrator($connection, $repository)->run();

        $result = $this->migrator($connection, $repository)->rollback(2);

        $this->assertSame([
            "M26_01_01_000100_create_migration_posts",
            "M26_01_01_000000_create_migration_users",
        ], $result->migrations);
        $this->assertSame([], $repository->applied());
    }

    public function testMigrationCommandsAreDiscoveredFromDatabaseServices(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "atom_make_migrations_" . uniqid();
        $providers = ServiceProviderRegistry::create()
            ->add(ConsoleServices::class)
            ->add(new DatabaseServices(
                SqliteDriver::memory(),
                new MigrationOptions($directory)
            ));
        $bindings = $providers->bindings()
            ->value(ServiceProviderRegistry::class, $providers);
        $console = Injector::create($bindings)->get(ConsoleApplication::class);

        $this->assertTrue($console->commands()->has("make:migration"));
        $this->assertTrue($console->commands()->has("migrate"));
        $this->assertTrue($console->commands()->has("migrate:fresh"));
        $this->assertTrue($console->commands()->has("migrate:rollback"));
        $this->assertTrue($console->commands()->has("migrate:status"));
        $this->assertTrue($console->commands()->has("migrate:sql"));

        $output = new BufferedConsoleOutput();
        $code = $console->run(["atom", "make:migration", "create_users"], $output);

        $this->assertSame(0, $code);
        $this->assertStringContainsString("Created migration:", $output->output());
        $this->assertCount(1, glob($directory . DIRECTORY_SEPARATOR . "M*_create_users.php") ?: []);
    }

    public function testRollbackCommandAcceptsStepsOption(): void
    {
        $connection = new DatabaseConnection(SqliteDriver::memory());
        $repository = new DatabaseMigrationRepository($connection);

        $this->migratorWithOptions(
            $connection,
            $repository,
            new MigrationOptions(__DIR__ . "/FirstBatchFixtures")
        )->run();

        $providers = ServiceProviderRegistry::create()
            ->add(ConsoleServices::class)
            ->add(new DatabaseServices(
                SqliteDriver::memory(),
                $this->options()
            ));
        $bindings = $providers->bindings()
            ->value(ServiceProviderRegistry::class, $providers)
            ->value(DatabaseConnection::class, $connection)
            ->value(\Atom\Database\Migration\MigrationRepositoryInterface::class, $repository);
        $injector = Injector::create($bindings);
        $injector->get(Migrator::class)->run();
        $console = $injector->get(ConsoleApplication::class);

        $output = new BufferedConsoleOutput();
        $code = $console->run(["atom", "migrate:rollback", "--steps=2"], $output);

        $this->assertSame(0, $code);
        $this->assertStringContainsString("Rolled back: M26_01_01_000100_create_migration_posts", $output->output());
        $this->assertStringContainsString("Rolled back: M26_01_01_000000_create_migration_users", $output->output());
        $this->assertSame([], $repository->applied());
    }

    public function testMigrationSqlCommandShowsPendingSql(): void
    {
        $providers = ServiceProviderRegistry::create()
            ->add(ConsoleServices::class)
            ->add(new DatabaseServices(
                SqliteDriver::memory(),
                $this->options()
            ));
        $bindings = $providers->bindings()
            ->value(ServiceProviderRegistry::class, $providers);
        $console = Injector::create($bindings)->get(ConsoleApplication::class);

        $output = new BufferedConsoleOutput();
        $code = $console->run(["atom", "migrate:sql"], $output);

        $this->assertSame(0, $code);
        $this->assertStringContainsString("M26_01_01_000000_create_migration_users", $output->output());
        $this->assertStringContainsString("CREATE TABLE", $output->output());
        $this->assertStringContainsString("migration_users", $output->output());
    }

    public function testFreshCommandDropsTablesRerunsMigrationsAndCanSeed(): void
    {
        $providers = ServiceProviderRegistry::create()
            ->add(ConsoleServices::class)
            ->add(new DatabaseServices(
                SqliteDriver::memory(),
                $this->options(),
                new SeederOptions(dirname(__DIR__) . "/Seeder/Fixtures")
            ));
        $bindings = $providers->bindings()
            ->value(ServiceProviderRegistry::class, $providers);
        $injector = Injector::create($bindings);
        $connection = $injector->get(DatabaseConnection::class);
        $connection->execute("CREATE TABLE stale_table (id INTEGER PRIMARY KEY AUTOINCREMENT)");
        $console = $injector->get(ConsoleApplication::class);

        $output = new BufferedConsoleOutput();
        $code = $console->run(["atom", "migrate:fresh", "--seed"], $output);

        $this->assertSame(0, $code);
        $this->assertStringContainsString("Database reset.", $output->output());
        $this->assertStringContainsString("Migrated: M26_01_01_000000_create_migration_users", $output->output());
        $this->assertStringContainsString("Seeded: S26_01_01_000000_seed_users", $output->output());
        $this->assertSame(0, (int) $connection->scalar(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'stale_table'"
        ));
        $this->assertSame("Edin", $connection->scalar("SELECT name FROM seed_users LIMIT 1"));
    }

    public function testFreshCommandPreservesDataWhenMigrationLockIsHeld(): void
    {
        $providers = ServiceProviderRegistry::create()
            ->add(ConsoleServices::class)
            ->add(new DatabaseServices(SqliteDriver::memory(), $this->options()));
        $injector = Injector::create($providers->bindings()->value(ServiceProviderRegistry::class, $providers));
        $connection = $injector->get(DatabaseConnection::class);
        $connection->execute("CREATE TABLE existing_data (name TEXT)");
        $connection->execute("INSERT INTO existing_data (name) VALUES ('keep me')");
        $console = $injector->get(ConsoleApplication::class);
        $output = new BufferedConsoleOutput();

        $lock = $injector->get(DatabaseLockManagerInterface::class)->acquire("migrations");
        $this->assertNotNull($lock);
        try {
            $code = $console->run(["atom", "migrate:fresh"], $output);
        } finally {
            $lock->release();
        }

        $this->assertSame(1, $code);
        $this->assertStringContainsString("Migrations are already running.", $output->errors());
        $this->assertTrue($connection->driver()->schemaInspector($connection)->hasTable("existing_data"));
        $this->assertSame("keep me", $connection->scalar("SELECT name FROM existing_data"));
        $this->assertStringNotContainsString("Database reset.", $output->output());
    }

    public function testMigratorProvidesLiveSchemaInspectorToMigrations(): void
    {
        $connection = new DatabaseConnection(SqliteDriver::memory());
        $connection->execute("CREATE TABLE conditional_users (id INTEGER PRIMARY KEY AUTOINCREMENT)");
        $repository = new DatabaseMigrationRepository($connection);
        $migrator = new Migrator(
            $connection,
            $repository,
            $connection->driver()->lockManager($connection),
            new MigrationDiscovery(),
            new MigrationOptions(__DIR__ . "/ConditionalFixtures")
        );

        $result = $migrator->run();

        $this->assertSame(["M26_01_01_000000_conditionally_update_users"], $result->migrations);
        $this->assertSame(1, (int) $connection->scalar(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'conditional_users'"
        ));
        $this->assertSame(["id", "email"], $connection->driver()->schemaInspector($connection)->columns("conditional_users"));
    }

    private function migrator(
        ?DatabaseConnection $connection = null,
        ?DatabaseMigrationRepository $repository = null
    ): Migrator {
        $connection ??= new DatabaseConnection(SqliteDriver::memory());
        $repository ??= new DatabaseMigrationRepository($connection);

        return $this->migratorWithOptions($connection, $repository, $this->options());
    }

    private function migratorWithOptions(
        DatabaseConnection $connection,
        DatabaseMigrationRepository $repository,
        MigrationOptions $options
    ): Migrator {
        return new Migrator(
            $connection,
            $repository,
            $connection->driver()->lockManager($connection),
            new MigrationDiscovery(),
            $options
        );
    }

    private function options(): MigrationOptions
    {
        return new MigrationOptions(
            __DIR__ . "/Fixtures",
            ""
        );
    }
}
