<?php

declare(strict_types=1);

namespace Atom\Tests\Database;

use Atom\Application;
use Atom\Config\Config;
use Atom\Database\DatabaseConnection;
use Atom\Database\DatabaseConfig;
use Atom\Database\DatabaseDriverInterface;
use Atom\Database\DatabaseServices;
use Atom\Database\Db;
use Atom\Database\Driver\SqliteDriver;
use Atom\Database\Facades\Db as DbFacade;
use Atom\Database\Model;
use Atom\Database\Orm\Attributes\Column;
use Atom\Database\Orm\Attributes\PrimaryKey;
use Atom\Database\Orm\Attributes\Table;
use Atom\Di\Injector;
use Atom\Di\ServiceProviderRegistry;
use Atom\Support\Paths;
use PHPUnit\Framework\TestCase;

final class DatabaseServicesTest extends TestCase
{
    public function testDatabaseServicesRegistersDriverConnectionAndDb(): void
    {
        $driver = SqliteDriver::memory();
        $injector = ServiceProviderRegistry::create()
            ->add(new DatabaseServices($driver))
            ->injector();

        $db = $injector->get(Db::class);
        $connection = $injector->get(DatabaseConnection::class);

        $this->assertSame($driver, $injector->get(DatabaseDriverInterface::class));
        $this->assertSame($connection, $db->connection());
    }

    public function testDatabaseServicesUseConventionalPathAliases(): void
    {
        $config = Config::fromEnv([
            "DB_DRIVER" => "sqlite",
            "DB_DATABASE" => "storage/app.sqlite",
        ]);
        $paths = (new Paths())
            ->alias("root", "D:/Atom")
            ->alias("migrations", "D:/Atom/app/Database/Migrations")
            ->alias("seeders", "D:/Atom/app/Database/Seeders");

        $injector = ServiceProviderRegistry::create()
            ->add(DatabaseServices::fromConfig($config, $paths))
            ->injector();

        $this->assertSame("sqlite:D:/Atom/storage/app.sqlite", $injector->get(DatabaseDriverInterface::class)->dsn());
        $this->assertSame("D:/Atom/app/Database/Migrations", $injector->get(\Atom\Database\Migration\MigrationOptions::class)->directory);
        $this->assertSame("D:/Atom/app/Database/Seeders", $injector->get(\Atom\Database\Seeder\SeederOptions::class)->directory);
    }

    public function testDatabaseServicesCreatesSqliteStorageDirectoryFromConfig(): void
    {
        $root = sys_get_temp_dir() . "/atom_sqlite_" . uniqid();
        $config = Config::fromEnv([
            "DB_DRIVER" => "sqlite",
            "DB_DATABASE" => "storage/nested/app.sqlite",
        ]);
        $paths = (new Paths())
            ->alias("root", $root)
            ->alias("migrations", $root . "/app/Database/Migrations")
            ->alias("seeders", $root . "/app/Database/Seeders");

        DatabaseServices::fromConfig($config, $paths);

        $this->assertDirectoryExists($root . "/storage/nested");
    }

    public function testDatabaseServicesConfiguresModelBaseDuringApplicationBootstrap(): void
    {
        \Atom\Application::$app = null;
        $app = new DbModelApplication();

        try {
            $app->initialize();

            $user = new DbBootstrappedModelUser();
            $user->name = "Atom";
            $this->assertSame(1, DbFacade::transaction(fn () => $user->save()));

            $this->assertSame(1, DbBootstrappedModelUser::count());
            $this->assertSame("Atom", DbBootstrappedModelUser::find(1)?->name);
        } finally {
            \Atom\Application::$app = null;
        }
    }
}

final class DbModelApplication extends Application
{
    protected function services(ServiceProviderRegistry $providers): void
    {
        $providers->add(new DatabaseServices(SqliteDriver::memory()));
    }

    protected function bootstrap(Injector $injector): void
    {
        $injector->get(DatabaseConnection::class)
            ->execute("CREATE TABLE bootstrapped_users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)");
    }
}

#[Table("bootstrapped_users")]
final class DbBootstrappedModelUser extends Model
{
    #[PrimaryKey("id")]
    public int $id;

    #[Column("name")]
    public string $name;
}
