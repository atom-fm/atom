# Atom ORM

Atom ORM provides attribute-based entity mapping, models, relations, and PDO persistence. It uses `atom-fm/sql` for query building and compilation.

Install with `composer require atom-fm/orm`. Supply a PDO connection in exception mode and the compiler for its dialect. This example uses SQLite with an in-memory database.

```php
use Atom\Database\Db;
use Atom\Database\Model;
use Atom\Database\Orm\Attributes\Column;
use Atom\Database\Orm\Attributes\PrimaryKey;
use Atom\Database\Orm\Attributes\Table;
use Atom\Database\Orm\PdoConnection;
use Atom\Database\Sql\Compiler\SqliteCompiler;

#[Table("articles")]
final class Article extends Model
{
    #[PrimaryKey]
    public ?int $id = null;

    #[Column]
    public string $title;
}

$pdo = new PDO("sqlite::memory:");
$pdo->exec("CREATE TABLE articles (id INTEGER PRIMARY KEY, title TEXT NOT NULL)");

$db = new Db(new PdoConnection($pdo, new SqliteCompiler()));
Model::useDb($db);

$article = new Article();
$article->title = "Working with Atom ORM";
$article->save();

$page = Article::query()->orderBy("id")->paginate(1, 10);
foreach ($page as $article) {
    echo $article->title, PHP_EOL;
}
```

Use `$db->select(Article::class)` and `$db->save($article)` to choose the database explicitly. Use `Model::useDb()` when calling the model's convenience methods through a shared default database.

When executing a query builder or a compiled `Command`, pass execution parameters to override bindings by placeholder name. For example, `["id" => 2]` and `[":id" => 2]` both override `:id`. Other bindings keep their compiled values.

`paginate()` returns a `PageResult` with the page items, page number, page size, and totals. It supports iteration and JSON serialization. For SQLite, enable `PDO::SQLITE_ATTR_EXTENDED_RESULT_CODES` on your connection to distinguish unique violations from other constraint errors.

## MySQL and PostgreSQL integration tests

From a monorepo checkout, run these commands in this package directory. Use Docker Compose and PHP 8.4 with `pdo_mysql` and `pdo_pgsql` enabled.

```powershell
composer install
docker compose -f compose.integration.yaml up -d --wait
php vendor/bin/phpunit -c phpunit.integration.xml
docker compose -f compose.integration.yaml down
```

The opt-in suite uses native PDO prepared statements on MySQL and PostgreSQL to verify parameter overrides, batch upserts, unique-constraint errors, and transaction commit and rollback. The database ports are bound to localhost, and database data is stored in temporary container filesystems. The default PHPUnit suite continues to use SQLite.

The Framework migration integration suite uses the same Compose databases. With the containers running, run `composer install` and `php vendor/bin/phpunit -c phpunit.integration.xml` from `packages/framework` to verify migration apply and rollback on both engines.
