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

## Model connection routing

Set `Model::useDb($mainDb)` for the shared default. To route a family of models, register a connection on their common base class. Individual subclasses can override it.

```php
Model::useDb($mainDb);
ArchiveModel::useConnection($archiveDb);
ArchivedArticle::useConnection($reportingDb);
```

Each model uses its own assignment first, then the nearest configured ancestor, then the shared default. Pass `null` to clear a class's assignment and resume fallback. Explicit `Db` operations and relationship queries use the selected `Db`. A model returned by an explicit query still resolves its convenience methods by class, so reuse that `Db` if you want to save it back to the connection you queried.

ORM rejects relationship loads, joins, filters, and aggregates when the related model has a class-level or inherited assignment to a different connection. Models without such an assignment are resolved on the query's connection.

Transactions cover writes through the connection that opened them. The facade opens transactions on the shared default. For a routed model, use its configured `Db` explicitly, for example `$archiveDb->transaction(fn (Db $db) => $db->save($article))`.

## Query execution and results

When executing a query builder or a compiled `Command`, pass execution parameters to override bindings by placeholder name. For example, `["id" => 2]` and `[":id" => 2]` both override `:id`. Other bindings keep their compiled values.

On PostgreSQL, entity inserts read generated primary keys directly from the inserted row using `RETURNING`.

When an entity has a generated primary key and no insertable columns, `save()` lets the database supply the defaults and assigns the generated key to the entity.

`paginate()` returns a `PageResult` with the page items, page number, page size, and totals. It supports iteration and JSON serialization. For SQLite, enable `PDO::SQLITE_ATTR_EXTENDED_RESULT_CODES` on your connection to distinguish unique violations from other constraint errors.

## Supported API

ORM is in maintenance mode. Routine changes focus on fixes, compatibility, and documentation for the APIs below.

| Area | Contract |
| --- | --- |
| Connection and execution | Construct `PdoConnection` with a PDO instance or a connection factory and the SQL compiler for that dialect. Use `Db` to execute SQL strings, query builders, and compiled commands. |
| Persistence | Use explicit `Db` operations or `Model` convenience methods with `useDb()` and `useConnection()`. Use table, column, primary-key, and relation attributes to map entities. `Model` tracks changes, and column value providers supply values for writes. |
| Reads | Use `Db::select()` and `DbSelect` to return models, rows, or typed projections. Supported operations include filters, ordering, aggregates, eager loading, relation filters, and pagination. `PageResult` supports iteration and JSON serialization. |
| Transactions and errors | `transaction()` commits a successful callback and rolls back a callback that throws. `UniqueViolationException` exposes the original PDO exception. Affected row counts follow the database driver. |

## Framework integration

- Framework's `DatabaseConnection` extends `PdoConnection` and passes in a lazy PDO factory and a dialect compiler. Migrations and database queues use this connection contract.
- Framework registers `Db`, `EntityMetadataFactory`, and `RowHydrator` as services. Startup calls `Model::useDb()` to configure the default database for models and the database facade.
- The API model builder recognizes `PageResult` when generating response schemas. Framework owns database configuration, schema changes, and migration orchestration.

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
