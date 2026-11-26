# Atom Database

Use PDO drivers, schema tools, and migrations for SQLite, MySQL, and PostgreSQL.
The package uses Atom ORM for PDO execution and Atom SQL for query compilation.
Enable the PDO extension for the database you want to use.

```php
use Atom\Database\DatabaseConnection;
use Atom\Database\Driver\SqliteDriver;
use Atom\Database\Migration\DatabaseMigrationRepository;
use Atom\Database\Migration\MigrationDiscovery;
use Atom\Database\Migration\MigrationOptions;
use Atom\Database\Migration\Migrator;

$driver = SqliteDriver::memory();
$connection = new DatabaseConnection($driver);
$migrator = new Migrator(
    $connection,
    new DatabaseMigrationRepository($connection),
    $driver->lockManager($connection),
    new MigrationDiscovery(),
    new MigrationOptions(__DIR__ . "/migrations"),
);
$migrator->run();
```

## Supported API

Database is in maintenance mode. Changes focus on fixes, compatibility, and
documentation for the APIs below.

| Area | API |
| --- | --- |
| Connections | Construct `DatabaseConnection` with `SqliteDriver`, `MySqlDriver`, or `PostgresDriver`. Use the inherited ORM connection API for queries and transactions. |
| Schema | Define changes with `Schema`, `Table`, and their column and index builders. Use the driver's schema inspector to check tables and columns. |
| Migrations | Implement `Migration::up()` and supply `down()` for reversible migrations. Discover anonymous or named migrations using `MigrationDiscovery` and `MigrationOptions`. Use `Migrator` to inspect status, preview SQL, apply pending migrations, or roll back batches. |
| History and locks | Use `DatabaseMigrationRepository` for history and the driver's lock manager for migration coordination. The migrator accepts the repository and lock-manager interfaces for custom implementations. |

Discovery rejects duplicate migration filenames across subdirectories before
applying any migration or invoking the reset callback. Rollback stops at a
migration without `down()` and keeps its history entry. SQLite and PostgreSQL apply each migration's schema
and history changes together. MySQL DDL can commit implicitly.

The concrete dialect compilers, schema operation and batch representations,
history SQL drivers, and lock implementations are implementation details.

## Framework integration

Framework's opt-in `DatabaseServices` selects and configures the driver,
registers `DatabaseConnection` as the ORM's `PdoConnection`, and wires the
migration repository, lock manager, runner, and resetter. Framework also owns
environment configuration, default model connection setup, scaffolding, and
seeding.

The Framework CLI calls `Migrator::run()`, `rollback()`, `status()`, and
`sql()`. The `migrate:fresh` command uses the `run()` callback to reset the
database while holding the migration lock. Framework's database queue uses
`DatabaseConnection` for its queries and transactions.

## Local verification

From the monorepo root, run the smoke script with this package's autoloader
after installing its Composer dependencies.

```sh
composer install:database
php packages/framework/integration/sqlite-migration-smoke.php packages/database/vendor/autoload.php
```

The opt-in MySQL and PostgreSQL migration tests use the shared ORM Docker
Compose services. Run them from the monorepo root with the corresponding PHP
PDO extensions enabled.

```sh
docker compose -f packages/orm/compose.integration.yaml up -d --wait
php packages/framework/vendor/bin/phpunit -c packages/framework/phpunit.integration.xml
docker compose -f packages/orm/compose.integration.yaml down
```
