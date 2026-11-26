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

From the monorepo root, run the smoke script with this package's autoloader
after installing its Composer dependencies.

```sh
composer install:database
php packages/framework/integration/sqlite-migration-smoke.php packages/database/vendor/autoload.php
```
