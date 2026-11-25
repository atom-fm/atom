# Database

Build a support queue that shows which open tickets the team should handle first. You’ll create a `tickets` table, map its columns to a `Ticket` model, and query that model for the two highest-priority open tickets.

Start in a fresh Atom Starter project with Composer dependencies installed and PDO SQLite enabled. The starter registers `DatabaseServices` during startup. Set these values in the project’s `.env` so the example uses its own local database.

```env
DB_DRIVER=sqlite
DB_DATABASE=storage/tickets-demo.sqlite
```

## Create the migration

From the project root, generate a migration.

```powershell
php atom make:migration create_tickets
```

Open the generated file in `app/Database/Migrations`. Keep its generated filename and replace its contents with the following PHP.

```php
<?php

declare(strict_types=1);

use Atom\Database\Migration\Migration;
use Atom\Database\Schema\Schema;
use Atom\Database\Schema\Table;

return new class extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create("tickets", function (Table $table): void {
            $table->id();
            $table->string("subject");
            $table->string("status");
            $table->integer("priority");
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop("tickets");
    }
};
```

Apply the pending migrations. The ID column is an auto-incrementing primary key. Larger priority values will appear first in the queue.

```powershell
php atom migrate
```

## Define the Ticket model

Create `app/Models/Ticket.php`. The table attribute names the table created above. Each property maps to a column, and the primary key tells Atom how to identify a saved ticket.

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Atom\Database\Model;
use Atom\Database\Orm\Attributes\Column;
use Atom\Database\Orm\Attributes\PrimaryKey;
use Atom\Database\Orm\Attributes\Table;

#[Table("tickets")]
final class Ticket extends Model
{
    #[PrimaryKey("id")]
    public int $id;

    #[Column("subject")]
    public string $subject;

    #[Column("status")]
    public string $status;

    #[Column("priority")]
    public int $priority;
}
```

## Save a few tickets

Create `open-tickets.php` next to the project’s `atom` command. Initializing the application connects the model to the configured database. When the table is empty, this script inserts four tickets with `save()`. Running it again reuses those rows.

```php
<?php

declare(strict_types=1);

use App\Application;
use App\Models\Ticket;

require __DIR__ . "/vendor/autoload.php";

(new Application())->initialize();

if (Ticket::count() === 0) {
    $examples = [
        ["Fix the broken checkout", "open", 3],
        ["Update the help link", "open", 1],
        ["Restore the email worker", "closed", 4],
        ["Retry failed invoices", "open", 2],
    ];

    foreach ($examples as [$subject, $status, $priority]) {
        $ticket = new Ticket();
        $ticket->subject = $subject;
        $ticket->status = $status;
        $ticket->priority = $priority;
        $ticket->save();
    }
}
```

## Query the open queue

Append the following code to `open-tickets.php`. `where()` selects open tickets, `orderByDesc()` puts the highest priority first, and `limit(2)` caps the result. The second sort key, `id`, gives tickets with equal priority a stable order. `all()` executes the query and returns `Ticket` objects.

```php
$openTickets = Ticket::query()
    ->where("status", "open")
    ->orderByDesc("priority")
    ->orderBy("id")
    ->limit(2)
    ->all();

foreach ($openTickets as $ticket) {
    printf("[%d] %s\n", $ticket->priority, $ticket->subject);
}
```

Run the script from the project root.

```powershell
php open-tickets.php
```

The script prints the first two open tickets.

```text
[3] Fix the broken checkout
[2] Retry failed invoices
```

The email worker ticket has the highest priority overall, but its status is `closed`. The help-link ticket is open and sits third in the queue. Increase the limit to three to see it.

## Services

Register database services from the application:

```php
use Atom\Database\DatabaseConfig;
use Atom\Database\DatabaseServices;

protected function services(ServiceProviderRegistry $providers): void
{
    $providers->add(DatabaseServices::fromConfig($this->getConfig(), $this->getPaths()));
}
```

Connection settings come from the typed `DatabaseConfig` options class.

The sample app reads its SQLite path from `.env`:

```env
DB_DRIVER=sqlite
DB_DATABASE=storage/atom_sample.sqlite
DB_HOST=localhost
DB_PORT=
DB_USERNAME=
DB_PASSWORD=
DB_CHARSET=utf8mb4
```

Supported driver values:

- `sqlite`
- `mysql`
- `mariadb`
- `pgsql`
- `postgres`
- `postgresql`

PostgreSQL has unit-level driver, SQL compiler, schema compiler, migration repository, inspector, resetter, and lock support. Real PostgreSQL integration tests are still optional and not part of the default test suite.

Database structure follows the framework convention: migrations live in
`app/Database/Migrations` and seeders live in `app/Database/Seeders`. The application registers
these locations as `@migrations` and `@seeders`, so publishing bundles and database commands can
use predictable paths without additional configuration.

`DatabaseServices` configures the `Model` base class during application startup, so model classes can use `query()`, `find()`, `save()`, and `delete()` without manual bootstrap code.

Use `Atom\Database\Facades\Db::transaction()` to commit model writes together on the configured model database. In standalone scripts and tests, `Model::useDb($db)` configures the same instance for both models and the facade.

```php
use Atom\Database\Facades\Db;

Db::transaction(function () use ($order, $item): void {
    $order->save();
    $item->orderId = $order->id;
    $item->save();
});
```

The callback can accept the configured `Atom\Database\Db` instance as its argument. Its return value is returned after commit. An exception rolls back the transaction and propagates to the caller.

### Count several queries in one statement

Pass an array of named model queries to `Atom\Database\Facades\Db::counts()` to read their counts in one `SELECT` on the model connection. The injected `Atom\Database\Db` instance provides the same method.

```php
use Atom\Database\Facades\Db;

$counts = Db::counts([
    "published" => Article::query()->where("published", true),
    "categories" => Category::query(),
]);
```

Each key maps to an integer, including zero when a query has no matching rows. Groups, limits, and offsets count the resulting rows. The builders can be reused afterward. An empty array yields an empty result without executing SQL.

Use `orderBy("id")->offset(10)` to skip the first ten rows and retrieve the rest. Passing that same builder to `Db::counts()` counts the remaining rows and returns zero if the offset reaches or exceeds the number of matches.

### Move stock in one transaction

Moving three notebooks from a storeroom to a shop shelf takes two writes. If the destination bin is missing, the notebooks should stay in the storeroom. Wrap both updates in `DatabaseConnection::transaction()` so they commit together.

Save `move-stock.php` in the root of an Atom Starter project with Composer dependencies installed and PDO SQLite enabled. The script uses an in-memory database. Each run starts with ten notebooks in the storeroom and an empty shelf.

```php
<?php

declare(strict_types=1);

use Atom\Database\DatabaseConnection;
use Atom\Database\Driver\SqliteDriver;

require __DIR__ . "/vendor/autoload.php";

$db = new DatabaseConnection(SqliteDriver::memory());
$db->execute("CREATE TABLE bins (name TEXT PRIMARY KEY, units INTEGER NOT NULL)");
$db->execute("INSERT INTO bins (name, units) VALUES ('storeroom', 10), ('shelf', 0)");

$destination = $argv[1] ?? "shelf";

try {
    $db->transaction(static function (DatabaseConnection $connection) use ($destination): void {
        $removed = $connection->execute(
            "UPDATE bins SET units = units - 3 WHERE name = 'storeroom' AND units >= 3"
        );
        if ($removed !== 1) {
            throw new DomainException("Three notebooks are unavailable.");
        }

        $added = $connection->execute(
            "UPDATE bins SET units = units + 3 WHERE name = :destination",
            [":destination" => $destination]
        );
        if ($added !== 1) {
            throw new DomainException("Destination bin not found.");
        }
    });
    echo "Transfer complete.", PHP_EOL;
} catch (DomainException $error) {
    echo $error->getMessage(), PHP_EOL;
}

foreach ($db->all("SELECT name, units FROM bins ORDER BY name") as $bin) {
    printf("%s: %d\n", $bin["name"], $bin["units"]);
}
```

Run `php move-stock.php shelf`. Both updates succeed, and the final query shows the committed quantities.

```text
Transfer complete.
shelf: 3
storeroom: 7
```

Now run `php move-stock.php missing`. The first update removes three notebooks. The second matches zero rows, so the callback throws. Atom rolls back the first update and rethrows the exception before the script reads the quantities.

```text
Destination bin not found.
shelf: 0
storeroom: 10
```

In an application service, inject the configured `DatabaseConnection` and use the same transaction callback. Keep the exception handler outside `transaction()` so a failed transfer reaches the rollback logic.

## Query Builder

### Search product names

Search a product catalogue for the text a shopper typed. A search for `20%` should find a discount label, while a search for `BOX_A` should find that exact sequence in a product name.

Save `find-products.php` in the root of an Atom Starter project with Composer dependencies installed and PDO SQLite enabled. Each run creates its own in-memory catalogue.

```php
<?php

declare(strict_types=1);

use Atom\Database\DatabaseConnection;
use Atom\Database\Driver\SqliteDriver;
use Atom\Database\Sql\Op;
use Atom\Database\Sql\Query;

require __DIR__ . "/vendor/autoload.php";

$db = new DatabaseConnection(SqliteDriver::memory());
$db->execute("CREATE TABLE products (name TEXT NOT NULL)");
foreach (["Notebook 20% off", "Notebook 200 pages", "Tray BOX_A", "Tray BOX1A"] as $name) {
    $db->execute(Query::insert("products")->values(["name" => $name]));
}

$term = $argv[1] ?? "20%";
$query = Query::select("products")
    ->columns("name")
    ->where("name", Op::contains($term))
    ->orderBy("name");

$matches = $db->all($query);
if ($matches === []) {
    echo "No matching products.", PHP_EOL;
}
foreach ($matches as $product) {
    echo $product["name"], PHP_EOL;
}
```

Run `php find-products.php` to print `Notebook 20% off`. Try `php find-products.php BOX_A` to print `Tray BOX_A`, or `php find-products.php pencil` to see the empty-result message.

`Op::contains()` escapes SQL pattern characters such as `%` and `_` and binds the search value. `Query::select()` builds the query and `$db->all($query)` executes it.

### List members who still need a profile

An onboarding report lists active members aged 18 and over, including those who have yet to create a profile. Save `member-profiles.php` in your Starter project root. It uses PDO SQLite and creates a fresh in-memory database each time.

```php
<?php

declare(strict_types=1);

use Atom\Database\DatabaseConnection;
use Atom\Database\Driver\SqliteDriver;
use Atom\Database\Sql\Op;
use Atom\Database\Sql\Query;

require __DIR__ . "/vendor/autoload.php";

$db = new DatabaseConnection(SqliteDriver::memory());
$db->execute("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, active INTEGER, age INTEGER)");
$db->execute("CREATE TABLE profiles (user_id INTEGER PRIMARY KEY, name TEXT NOT NULL)");
$db->execute("INSERT INTO users VALUES
    (1, 'Ada', 1, 31), (2, 'Ivo', 1, 18), (3, 'Lea', 0, 42), (4, 'Max', 1, 17)");
$db->execute("INSERT INTO profiles VALUES (1, 'Ada K.')");

$query = Query::select("users u")
    ->columns("u.id", "u.name", "p.name profileName")
    ->leftJoin("profiles p", function ($join): void {
        $join->on("p.user_id", Op::column("u.id"));
    })
    ->where("u.active", true)
    ->where("u.age", Op::gte(18))
    ->orderBy("u.name")
    ->limit(20);

if (($argv[1] ?? "") === "--missing") {
    $query->where("p.user_id", null);
}

foreach ($db->all($query) as $row) {
    echo $row["name"], " - ", $row["profileName"] ?? "Profile missing", PHP_EOL;
}
```

Run `php member-profiles.php` to see the directory.

```text
Ada - Ada K.
Ivo - Profile missing
```

Run `php member-profiles.php --missing` to print only `Ivo - Profile missing`. The left join keeps Ivo's user row and supplies `NULL` for the missing profile columns. Filtering on `p.user_id` selects those unmatched rows. `Op::column("u.id")` tells the join to compare the two ID columns.

### More query examples

Simple join strings are supported:

```php
Query::select("users u")
    ->leftJoin("profiles p on p.user_id = u.id");
```

Condition shortcuts:

```php
Query::select("users")
    ->where("active", true)       // active = true
    ->where("id", [1, 2, 3])      // id in (...)
    ->where("deleted_at", null);  // deleted_at is null
```

Use `Op::contains($text)` for a literal substring search, such as `->where("name", Op::contains($search))`. The search treats `%` and `_` as ordinary characters. Use `Op::like($pattern)` when the pattern includes SQL wildcards on purpose.

Raw criteria expressions can be used when needed:

```php
Query::select("users u")
    ->whereExp("u.id = :id and u.id < 100", ["id" => 2]);
```

The compiler reserves caller-supplied binding names across the whole query, including subqueries. For example, if an expression binds `:p1`, the builder skips that name when generating placeholders for ordinary filters and updates.

`whereExp()` also supports simple correlated `EXISTS` subqueries with bound parameters.

Mutation builders:

```php
Query::insert("users")->values(["name" => "Edin"]);

Query::update("users")
    ->set(["name" => "Amar"])
    ->where("id", 1);

Query::delete("users")
    ->where("id", Op::gte(10));
```

## Db

### Report ticket counts by status

A support lead needs a count of tickets by status and the highest-priority ticket in a chosen status. Use the four tickets and the `Ticket` model from the [Database walkthrough](#create-the-migration). Run its `open-tickets.php` script once to populate the example SQLite database.

Save the following as `ticket-report.php` in the same project root.

```php
<?php

declare(strict_types=1);

use App\Application;
use App\Models\Ticket;
use Atom\Database\Db;

require __DIR__ . "/vendor/autoload.php";

$app = new Application();
$app->initialize();
$db = $app->getInjector()->get(Db::class);

$counts = $db->select("tickets")
    ->columns("status")
    ->count("*", "ticket_count")
    ->groupBy("status")
    ->orderBy("status")
    ->all();

foreach ($counts as $row) {
    printf("%s: %d\n", $row["status"], $row["ticket_count"]);
}

$status = $argv[1] ?? "open";
$ticket = $db->select(Ticket::class)
    ->where("status", $status)
    ->orderByDesc("priority")
    ->orderBy("id")
    ->limit(1)
    ->first();

echo $ticket?->subject ?? "No matching tickets", PHP_EOL;
```

The first query selects a table by name, so `all()` returns associative arrays. Each row contains a status and its count under the alias `ticket_count`. The second query selects a model class, so `first()` returns a `Ticket` or `null`. Ordering by ID breaks ties between tickets with the same priority.

Pass columns in priority order when they share a direction. For example, `orderBy("sort_order", "name", "id")` sorts by position, then by name, then by ID. `orderByDesc("priority", "id")` sorts both columns descending. Chain the two methods when the directions differ.

Run `php ticket-report.php`. With the walkthrough data, it prints the following report.

```text
closed: 1
open: 3
Fix the broken checkout
```

Run `php ticket-report.php closed` to see `Restore the email worker` on the last line. Try `php ticket-report.php pending` to see `No matching tickets`. The counts stay the same because only the model query filters on the requested status.

For a single count, `$db->select("tickets")->where("status", "open")->total()` returns the integer `3` for this data. Use `exists()` on a filtered query when you only need to know whether a match exists. See [Relations](#relations) for loading related models with `with()`.

Use `whereAny()` to match the same value or `Op` against several columns. For example, `Article::query()->whereAny(["title", "body"], Op::contains($term))->where("is_published", true)` searches either field within published articles. The OR conditions form one parenthesized group. Pass at least one column.

For a literal substring search, use `whereContainsAny(["title", "body"], $term)`.
An empty string adds no condition. Every other term is used as supplied, including
`"0"`, whitespace, and literal `%` and `_` characters. Pass `trim($term)` when the
listing should ignore surrounding whitespace.

Use `whereFromUntil("placed_at_utc", $fromInclusive, $untilExclusive)` to include
rows at or after the start and strictly before the end. For a whole reporting
day, pass its start and the start of the following day in the column's time zone.
The caller calculates the boundaries and Atom binds them as supplied.

Use `whereHas("comments")` to select articles with at least one related comment.
Pass a callback to filter matching children by their database column names, for
example `whereHas("comments", fn (WhereGroup $comments) => $comments->where("visible", true))`.
For a search across the parent and a `HasMany` relationship, put `orWhereHas()`
inside a group so other filters apply to both alternatives.

```php
use Atom\Database\Sql\{Op, WhereGroup};

$pattern = Op::contains($term);
$articles = Article::query()
    ->group(fn (WhereGroup $where) => $where
        ->where("title", $pattern)
        ->orWhereHas("comments", fn (WhereGroup $comments) => $comments->where("body", $pattern)))
    ->where("is_published", true)
    ->orderBy("id")
    ->paginate(1, 25);
```

Atom uses a correlated `EXISTS` with the relation's mapped keys. Each qualifying
article appears once and contributes one to the pagination total, even when
several comments match.

### Page through the support queue

Expose the tickets from the walkthrough two at a time at `GET /api/tickets`. Filter by status before paginating so the total and the next-page link describe the same queue.

In the Starter app's `app/Application.php`, import `App\Models\Ticket`, `Atom\Di\Injector`, `Atom\Http\Request`, and `Atom\Router\Route`. Register this route in `bootstrap()`. If the hook already exists, add the route to its body.

```php
protected function bootstrap(Injector $injector): void
{
    Route::get("/api/tickets", static function (Request $request): array {
        $query = $request->query();
        $status = $query->string("status", "open");
        $page = Ticket::query()
            ->where("status", $status)
            ->orderByDesc("priority")
            ->orderBy("id")
            ->paginate($query->int("page", 1), 2);

        $subjects = [];
        foreach ($page as $ticket) {
            $subjects[] = $ticket->subject;
        }

        $next = $page->hasNext()
            ? "/api/tickets?" . http_build_query(["status" => $status, "page" => $page->page() + 1])
            : null;

        return ["page" => $page->page(), "total" => $page->totalCount(), "subjects" => $subjects, "next" => $next];
    })->api();
}
```

Run `php atom serve` in the project root. In a second PowerShell terminal, fetch the first page and follow its `next` link.

```powershell
$site = "http://127.0.0.1:8020"
$first = Invoke-RestMethod "$site/api/tickets"
$first | ConvertTo-Json -Compress
Invoke-RestMethod "$site$($first.next)" | ConvertTo-Json -Compress
```

With the four walkthrough tickets, both responses count three open tickets. Priority determines their order, with ID breaking ties.

```json
{"page":1,"total":3,"subjects":["Fix the broken checkout","Retry failed invoices"],"next":"/api/tickets?status=open&page=2"}
```

```json
{"page":2,"total":3,"subjects":["Update the help link"],"next":null}
```

Try `/api/tickets?status=open&page=99` to see the last available page. With `/api/tickets?status=pending`, no tickets match, so the endpoint returns page `1`, total `0`, an empty list, and `next` set to `null`.

Use `paginate($page, $pageSize)` to fetch a page and its total count as a `PagedCollection`. Apply filters and an explicit order before paginating. Use a unique column as the last sort key when other keys can tie. The request clamps to the available pages. Pagination applies its own limit and offset to a copy of the query. Model queries hydrate the page and load requested relations as usual. The total counts result rows, including groups when using `groupBy()` and `having()`.

Use `scalarColumn($expression, $alias, $parameters)` to add a per-row SQL expression, such as a correlated subquery for a display count. Write the SQL expression in code and bind values by name in the optional parameter array. Choose names distinct from the other bindings in the query. Pagination can drop these columns and their bindings from the count query.

Use `subqueryColumn($query, $alias)` to include the result of another model query in the same SQL statement. Select one column or aggregate from the same connection. A count always returns one row. For a lookup, apply filters or an explicit order and `limit(1)` so it returns at most one row. An empty lookup yields `null`. The builder copies the inner query when you add it.

```php
$stats = Category::query()
    ->count("*", "categories")
    ->subqueryColumn(Article::query()->where("published", true)->count(), "published_articles")
    ->asRows()
    ->first();
```

Use `orderByExp($expression, $parameters, descending: true)` to sort by a SQL expression from your code. Bind values in the optional parameter array. The default order is ascending, and `paginate()` omits ordering and its bindings from the count query.

Use `columns("*")` or `columns("t.*")` to select every column from a table alongside computed projections.

## Models

Models extend `Atom\Database\Model` and use ORM attributes for metadata.

Start with `Article::query()` or pass a table alias to `Article::query("a")`.
The default result is an article with its mapped columns. Call `asRows()` for
`stdClass` objects whose public properties match the selected column names or
aliases. Use `asArrays()` for associative arrays keyed by those same names, or
`asProjection(ArticleListItem::class)` for typed objects with matching `Column` or
`Projection` attributes. All three methods copy the query, preserve explicit
selections, and work with `find()`, `first()`, `all()`, and `paginate()`.
With no explicit selection, row and projection queries select `*`.
Use `with()` for eager loading on model results. Relationship joins and aggregates
resolve mappings from the source model before or after choosing a result type.

Static analysis retains the selected type. For example, `Article::query()->first()`
is inferred as `Article|null` and `Article::query()->paginate(1, 25)` as
`PagedCollection<Article>`.

`Article::query()->withCount("comments")->all()` adds a correlated count for
the mapped `HasMany` relationship in the same SQL statement. Declare the count
on the model so Atom can hydrate it without selecting or writing a physical
column.

```php
#[Projection("comments_count")]
public ?int $commentsCount = null;
```

The default alias is the relationship name plus `_count`. Pass a mapped alias
as the second argument to use a different column name. A normal model load
leaves the count at `null`. A requested count is zero when nothing matches.

On a model row query, `withCount()` and `withSum()` select one value per parent
using the keys of a mapped `HasMany` relation. Choose the parent columns with
`columns()`. The aggregate aliases become row properties, or can be mapped on a
result class with `asProjection()`.

```php
$products = Product::query("p")
    ->columns("p.id", "p.name")
    ->withCount("variants", "variant_count")
    ->withSum("variants", "stock", alias: "stock", default: 0)
    ->orderBy("p.id")
    ->asRows()
    ->paginate(1, 30);
```

Pass `constraint:` to `withCount()` to count only matching children. The callback
receives an `Atom\Database\Sql\WhereGroup` for the related table, just as it
does for `whereHas()`. Parents with no matching children receive a count of zero.

```php
use Atom\Database\Sql\{Op, WhereGroup};

$variants = ProductVariant::query()
    ->withCount("locationStocks", alias: "location_count",
        constraint: static fn (WhereGroup $stocks) => $stocks->where("stock", Op::gt(0)))
    ->asRows()
    ->all();
```

Use a dot-separated path to count rows across multiple `HasMany` relationships.
For example, `withCount("productGroups.categories", "category_count")` counts
categories across all product groups for each department. The callback filters
categories, and departments with no matching categories receive zero. Omitting
the alias gives the count the name `productGroups_categories_count`.

`withSum()` accepts a selectable column of the related model by column or
property name. Its default alias is `{relation}_sum_{column}`. An empty
relation or a set of entirely null values yields `null` unless you supply a
numeric default. Both aggregates preserve one result per parent when a parent
has multiple children.

`joinRelation()` adds inner joins along a dot-separated path of `BelongsTo`
relationships. `leftJoinRelation()` adds left joins so rows can still appear when
a related record is missing. Each step uses its mapped foreign and owner keys.
The default SQL alias is the path to that step with dots replaced by underscores.
The optional second argument sets the alias for the final step.

```php
$variants = ProductVariant::query("v")
    ->joinRelation("product", "p")
    ->leftJoinRelation("product.brand", "b")
    ->leftJoinRelation("product.taxonomyCategory", "c")
    ->columns("v.id", "p.name", "b.name brand_name", "c.name category_name")
    ->orderBy("v.id")
    ->asRows()
    ->all();
```

Both nested paths reuse the inner join aliased as `p`. Only the brand and category
joins are left joins. If a prefix has been joined under several aliases, a nested
path follows the first one. Repeating a path with the same alias and join type
reuses that join. To join the same final relation again with a different type,
supply a distinct alias.

`Projection` maps a query result alias to a property and excludes it from
ordinary selects, inserts, and updates. It also works with `scalarColumn()`.

Use `Article::query()->where("category_id", $categoryId)->max("sort_order")`
to read a maximum as a scalar. On an ungrouped query with no matching rows,
the result is `null`. `sum("quantity")` follows the same rules. The builder keeps its
original projection when either aggregate is called.

Use `Article::query()->where("category_id", $categoryId)->nextPosition("sort_order")`
to append to a category's ordered list. It returns one more than the largest
matching integer position, or `0` when there are no matching non-null positions.
If the largest position is already `PHP_INT_MAX`, it throws `OverflowException`.

`Article::query()->orderBy("id")->pluck("title")` returns a list of column values,
including nulls, with the query's filters and ordering. Pass a second column
to key the values, as in `Article::query()->orderBy("id")->pluck("title", "id")`.
The result uses PHP array keys. For repeated keys, the last matching row supplies
the value. Both forms use one query and keep the original builder projection.

A model automatically captures its original writable values during hydration. An entity can also implement `Atom\Database\Orm\HydrationAwareInterface` to initialize derived state. Atom calls `afterHydration()` after it assigns the selected columns and captures the model's originals.

```php
namespace App\Models;

use Atom\Database\Model;
use Atom\Database\Orm\Attributes\Column;
use Atom\Database\Orm\Attributes\PrimaryKey;
use Atom\Database\Orm\Attributes\Table;
use Atom\Database\Orm\Provider\NowProvider;
use DateTimeImmutable;

#[Table("articles")]
final class Article extends Model
{
    #[PrimaryKey("id")]
    public int $id;

    #[Column("title")]
    public string $title;

    #[Column("body")]
    public string $body;

    #[Column("created_at", onInsert: NowProvider::class)]
    public DateTimeImmutable $createdAt;

    #[Column("updated_at", onInsert: NowProvider::class, onUpdate: NowProvider::class)]
    public DateTimeImmutable $updatedAt;
}
```

Query:

```php
$articles = Article::query()
    ->where("is_published", true)
    ->orderByDesc("created_at")
    ->limit(10)
    ->all();
```

Find by primary key:

```php
$article = Article::find(1);
$exists = Article::existsById(1);
```

Both methods use the model's mapped primary key. `find()` returns `null` when the record is missing. `existsById()` checks for a match without hydrating a model.

Use `$articles = Article::query()->where("category_id", $categoryId)` and
`$articles->find($id)` to look up an article within that category. Builder
`find()` adds the mapped primary key to a copy of the query, so you can reuse
`$articles` for another ID. It honors joins, filters, eager loads, and the
selected result type. For example, `$articles->asRows()->find($id)` returns
a `stdClass` row, or `null` if the article is outside the category or missing.

Use `Article::allIdsExist($ids)` to check a selection against the same mapped primary key.
It accepts integer and string IDs, ignores duplicates, and checks large selections in
batches. An empty array returns `true` without a query. A missing ID or a value
of any other type returns `false`.

Use `Article::query()->where("slug", $slug)->exceptId($article->id ?? null)->exists()`
to check whether another article has the same slug. `exceptId()` uses the source
model's mapped primary key and its table alias when present. Passing `null`
adds no condition, so a new article checks against every matching row.

Count:

```php
$total = Article::count();
```

Save and delete:

```php
$article = new Article();
$article->title = "Hello";
$article->body = "Body";
$article->save();

$article->title = "Updated";
$article->save();

$article->delete();
```

`save()` inserts when the primary key is empty and updates otherwise.

On a loaded model, `originalValues()` returns the writable columns Atom read or last wrote. `changedValues()` returns the current edits. Both arrays use database column names and storage values. A save writes those edits and any applicable `onUpdate` provider values. It returns zero when there is nothing to write.

An omitted column, including one mapped with `select: false`, keeps its stored value when another field changes. Assign a value that differs from the property's PHP default to include it in the next save. For an uninitialized property, even assigning `null` counts as a change if the type allows it. To write the default value deliberately, select the column when loading the model or pass that value to `updateById()`.

Custom save methods can use `changedValues()` to build their update, then call `syncOriginalValues(array_keys($written))` after a successful write. A model can override `toDatabaseValue()` to use the same storage format for comparisons and writes. After rolling back an enclosing transaction, reload models saved inside it before reusing them.

`Article::deleteWhere(["category_id" => $categoryId])` deletes matching rows from the
model's mapped table in a single statement and returns the number of rows deleted.
Pass at least one database column name and condition. Multiple conditions are
combined with `AND` and accept the same values and operators as `where()`.
Use the instance `delete()` method when per-record deletion behavior is required.

`Article::updateById($id, ["title" => "Revised"])` updates one mapped primary
key in a single statement and returns the affected-row count. Pass database
column names and storage values. Atom applies each writable column's `onUpdate`
provider last, so its value takes precedence. Pass `[]` to write only the
provider values, for example to refresh an update timestamp. An empty update
requires at least one such provider.

For updates by ID, each `onUpdate` provider must implement
`Atom\Database\Orm\DirectUpdateValueProviderInterface`. Its
`databaseValue(ColumnMetadata $column)` method returns a bindable scalar or
`null` without loading the entity. The provider controls the stored format,
including timestamp precision. Use instance `save()` for providers that read
entity state or for application validation and optimistic concurrency checks.

`Article::updateWhere(["category_id" => $categoryId], ["is_published" => false])`
updates matching rows in one statement and returns the affected-row count. It
requires at least one filter and value. Pass database column names and storage
values. Use instance `save()` when the update needs model validation or value
providers.

Use `Model::upsert($values, $uniqueBy, $update)` for a direct write of one
row or a batch. Use database column names and supply the same columns in
every row. Atom binds each value and executes one statement per call.

```php
VariantLocationStock::upsert(
    values: [
        ["product_variant_id" => 42, "store_location_id" => 3, "stock" => 12],
        ["product_variant_id" => 42, "store_location_id" => 7, "stock" => 5],
    ],
    uniqueBy: ["product_variant_id", "store_location_id"],
    update: ["stock"],
);
```

Use a single column name or a list for `uniqueBy`. SQLite and PostgreSQL use
the specified primary or unique key as the conflict target. MySQL detects a
conflict on any primary or unique index. On a conflict the listed update
columns take their incoming values. Omitting `update` replaces all supplied
columns. Passing `update: []` performs a plain insert, so duplicate keys
raise a database error. An empty batch returns zero. Other calls return
the driver's affected-row count. As with `updateWhere()`, callers supply
storage values, including any application-managed timestamps.

### Export tickets by status as CSV

Use the `Ticket` model and sample data from the [Database walkthrough](#create-the-migration). Run `php open-tickets.php` once to populate the demo database. To share the queue with a colleague, select just the priority and subject from the model's mapped table.

Save `export-tickets.php` in the same project root.

```php
<?php

declare(strict_types=1);

use App\Application;
use App\Models\Ticket;

require __DIR__ . "/vendor/autoload.php";

(new Application())->initialize();

$status = $argv[1] ?? "open";
$rows = Ticket::query()
    ->columns("priority", "subject")
    ->where("status", $status)
    ->orderByDesc("priority")
    ->orderBy("id")
    ->asRows()
    ->all();

fputcsv(STDOUT, ["priority", "subject"], escape: "");
foreach ($rows as $row) {
    fputcsv(STDOUT, [$row->priority, $row->subject], escape: "");
}
```

`asRows()->all()` returns objects with the two selected properties. Sorting by ID gives equally urgent tickets a consistent order. `fputcsv()` handles commas and quotes inside subjects.

Run `php export-tickets.php`. The walkthrough data produces this CSV.

```text
priority,subject
3,"Fix the broken checkout"
2,"Retry failed invoices"
1,"Update the help link"
```

Run `php export-tickets.php closed` for the email worker ticket. A status with no matches produces just the header row.

### Close an open ticket once

A support agent can submit the same close request twice. Put the ticket ID and its current status in the update so only the first request changes the row. Use the four tickets from a fresh copy of the [Database walkthrough](#create-the-migration). Run `php open-tickets.php` once to populate its SQLite database.

Save `close-ticket.php` beside `open-tickets.php`.

```php
<?php

declare(strict_types=1);

use App\Application;
use App\Models\Ticket;

require __DIR__ . "/vendor/autoload.php";

$id = filter_var($argv[1] ?? "", FILTER_VALIDATE_INT);
if ($id === false || $id < 1) {
    fwrite(STDERR, "Usage: php close-ticket.php <positive ticket ID>" . PHP_EOL);
    exit(1);
}

(new Application())->initialize();

$changed = Ticket::updateWhere(
    ["id" => $id, "status" => "open"],
    ["status" => "closed"]
);

echo $changed === 1 ? "Closed ticket {$id}." : "No open ticket matched ID {$id}.", PHP_EOL;
```

From the project root, close the checkout ticket twice, then read the queue.

```powershell
php close-ticket.php 1
php close-ticket.php 1
php open-tickets.php
```

```text
Closed ticket 1.
No open ticket matched ID 1.
[2] Retry failed invoices
[1] Update the help link
```

`updateWhere()` applies both filters in one SQL update and returns the number of affected rows. A closed ticket and a missing ID both yield zero. The queue now shows the next two open tickets in priority order.

## Relations

Print an article index with the category beside each title. Two articles share a category, and a third has none. Use `BelongsTo` to describe the relationship and `with("category")` to load it for the whole list.

### Map the category

Create `article-index.php` in the root of an Atom Starter project with Composer dependencies installed and PDO SQLite enabled. Start with these model definitions. The `categoryId` property holds the database key. The separate `category` property receives the matching `Category` object.

```php
<?php

declare(strict_types=1);

use Atom\Database\DatabaseConnection;
use Atom\Database\Db;
use Atom\Database\Driver\SqliteDriver;
use Atom\Database\Model;
use Atom\Database\Orm\Attributes\BelongsTo;
use Atom\Database\Orm\Attributes\Column;
use Atom\Database\Orm\Attributes\PrimaryKey;
use Atom\Database\Orm\Attributes\Table;

require __DIR__ . "/vendor/autoload.php";

#[Table("categories")]
final class Category extends Model
{
    #[PrimaryKey("id")]
    public int $id;

    #[Column("name")]
    public string $name;
}

#[Table("articles")]
final class Article extends Model
{
    #[PrimaryKey("id")]
    public int $id;

    #[Column("category_id")]
    public ?int $categoryId = null;

    #[Column("title")]
    public string $title;

    #[BelongsTo(Category::class, foreignKey: "category_id")]
    public ?Category $category = null;
}
```

### Load the article index

Append the following to the same script. The in-memory database supplies a fresh set of articles each time you run it. `Model::useDb()` connects the models to this database for the example. In a starter application, `DatabaseServices` normally supplies that connection at startup.

```php
$db = new Db(new DatabaseConnection(SqliteDriver::memory()));
Model::useDb($db);

$db->execute("CREATE TABLE categories (id INTEGER PRIMARY KEY, name TEXT NOT NULL)");
$db->execute("CREATE TABLE articles (id INTEGER PRIMARY KEY, title TEXT NOT NULL, category_id INTEGER REFERENCES categories(id))");
$db->execute("INSERT INTO categories (id, name) VALUES (1, 'Guides')");
$db->execute("INSERT INTO articles (id, title, category_id) VALUES
    (1, 'Getting started', 1), (2, 'Writing a page', 1), (3, 'Release notes', NULL)");

$articles = Article::query()->with("category")->orderBy("id")->all();

foreach ($articles as $article) {
    printf("%s | %s\n", $article->title, $article->category?->name ?? "Uncategorized");
}
```

Run `php article-index.php` from the project root. The null-safe access in the loop gives the third article a fallback label.

```text
Getting started | Guides
Writing a page | Guides
Release notes | Uncategorized
```

Here `with("category")` names the PHP relation property. After reading the articles, Atom collects their distinct non-null `category_id` values and loads the matching categories in one query. Reading `$article->category` inside the loop then uses the object already assigned to that article.

For a single article, use `Article::find($id, "category")`. Pass additional relation names as separate arguments to load them in the same way as `with()`.

Use dots to follow mapped relationships across models. For example,
`Order::query()->with("returnRequests.items")->all()` loads orders, their
return requests, and the items for all those requests in three queries when
returns exist. If no order has a return, Atom skips the item query.
`Order::find($id, "items", "returnRequests.items")` also loads the order's own
items. Paths with the same prefix share the query for that relationship.

For a relation pointing in the other direction, Atom also supports `HasOne` and `HasMany`.

### Count articles beside each category

A category menu needs one row per category, including categories with no articles yet. Extend `article-index.php` to fetch the names and counts together.

Add these imports at the top of the script.

```php
use Atom\Database\Orm\Attributes\HasMany;
use Atom\Database\Orm\Attributes\Projection;
```

Add these properties inside `Category`. `HasMany` connects the category's `id` to `category_id` on the articles table. `Projection` maps the count returned by the query to a PHP property, so the categories table needs no extra column.

```php
/** @var Article[] */
#[HasMany(Article::class, foreignKey: "category_id", localKey: "id", orderBy: ["title", "id"])]
public array $articles = [];

#[Projection("articles_count")]
public ?int $articlesCount = null;
```

The optional `orderBy` list tells Atom to load each category's articles by
title, with ID breaking ties. Use mapped child column or property names in
priority order. Each sorts ascending. When loading several categories with
`with("articles")`, Atom applies the ordering in one shared child query.

Append this code after the article index. It creates an empty category, then asks for the count of the `articles` relation on every category.

```php
$db->execute("INSERT INTO categories (id, name) VALUES (2, 'Events')");

$categories = Category::query()->withCount("articles")->orderBy("name")->all();
foreach ($categories as $category) {
    printf("%s | %d\n", $category->name, $category->articlesCount);
}
```

Run `php article-index.php` again. The category menu appears after the original three article lines.

```text
Events | 0
Guides | 2
```

`withCount("articles")` adds the `articles_count` result to the category query without loading the article objects. `Events` has no matches, so its count is zero. The uncategorized article belongs to neither category. If you omit `withCount()`, the projection keeps its declared default of `null`.

## Migrations and Seeders

Use a migration to create a lookup table and a seeder to fill in its default values. This example gives a ticket form three status choices. Running the seeder again can restore a missing choice while keeping a label someone has edited.

### Create the ticket status table

Start in a fresh Atom Starter project with Composer dependencies installed and PDO SQLite enabled. Set these values in the project's `.env` to give the example its own database.

```env
DB_DRIVER=sqlite
DB_DATABASE=storage/status-demo.sqlite
```

From the project root, generate the migration and seeder.

```shell
php atom make:migration create_ticket_statuses
php atom make:seeder seed_ticket_statuses
```

Keep both generated filenames. In `app/Database/Migrations/`, replace the contents of the new migration with the following. The status code is the primary key so each choice has a stable identifier even when its label changes.

```php
<?php

declare(strict_types=1);

use Atom\Database\Migration\Migration;
use Atom\Database\Schema\Schema;
use Atom\Database\Schema\Table;

return new class extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create("ticket_statuses", function (Table $table): void {
            $table->string("code")->primary();
            $table->string("label");
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop("ticket_statuses");
    }
};
```

### Seed the missing choices

In `app/Database/Seeders/`, replace the contents of the new seeder with this code. Atom injects the configured `DatabaseConnection` into `run()`. SQLite's `ON CONFLICT (code) DO NOTHING` skips only codes that already exist.

```php
<?php

declare(strict_types=1);

use Atom\Database\DatabaseConnection;
use Atom\Database\Seeder\Seeder;

return new class extends Seeder
{
    public function run(DatabaseConnection $connection): void
    {
        $connection->execute(
            "INSERT INTO ticket_statuses (code, label)
             VALUES ('open', 'Open'), ('waiting', 'Waiting for reply'), ('closed', 'Closed')
             ON CONFLICT (code) DO NOTHING"
        );
    }
};
```

Apply the migration, then run the seeder twice.

```shell
php atom migrate
php atom db:seed
php atom db:seed
```

Both seed runs print `Seeded:` followed by the generated seeder name. Atom runs the discovered seeders on each invocation. The primary key and conflict clause make this seeder repeatable.

### Check the result

Run this query against `storage/status-demo.sqlite` in a SQLite client.

```sql
SELECT code, label FROM ticket_statuses ORDER BY code;
```

| code | label |
| --- | --- |
| closed | Closed |
| open | Open |
| waiting | Waiting for reply |

To check that local edits survive, change the `waiting` label to `Awaiting customer` in the demo database, run `php atom db:seed` again, and repeat the query. There should still be three rows, with the new label on `waiting`.

Use `php atom migrate:status` to see which migrations have run and `php atom migrate:sql` to inspect pending SQL. `migrate:rollback` rolls back the latest batch by default. `migrate:fresh` takes the migration lock before dropping tables and holds it through the rebuild. If another migration holds the lock, the command exits before changing the database.

SQLite and PostgreSQL commit each migration's schema changes and history entry in one transaction. If a schema command or history write fails during `migrate` or `migrate:rollback`, Atom rolls back that migration. Earlier migrations completed by the same command stay committed. When calling the migrator inside an existing transaction, Atom uses a savepoint per migration and leaves the final commit to the caller.

MySQL schema changes can commit implicitly. After a failure, inspect the schema and migration history and reconcile any partial changes before retrying.

### Rehearse a change to a populated table

Suppose the ticket form needs a display order for its status choices. Use the populated `storage/status-demo.sqlite` from the walkthrough above. Apply the original migration and seed the three rows **before** generating this second migration. That puts the table creation in an earlier batch, so you can roll back the new column on its own.

```shell
php atom make:migration add_sort_order_to_ticket_statuses
```

Keep the generated filename under `app/Database/Migrations` and replace its contents with this migration. `up()` gives existing choices a default position of `100`. `down()` drops the column and its stored positions.

```php
<?php

declare(strict_types=1);

use Atom\Database\Migration\Migration;
use Atom\Database\Schema\Schema;
use Atom\Database\Schema\Table;

return new class extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->table("ticket_statuses", static function (Table $table): void {
            $table->integer("sort_order")->default(100);
        });
    }

    public function down(Schema $schema): void
    {
        $schema->table("ticket_statuses", static function (Table $table): void {
            $table->dropColumn("sort_order");
        });
    }
};
```

Preview the change from the project root.

```shell
php atom migrate:status
php atom migrate:sql
```

The new migration should be the only one marked `pending`. Its SQL adds `sort_order` to `ticket_statuses` with a default of `100`. Run `php atom migrate`, then check the existing rows in your SQLite client.

```sql
SELECT code, sort_order FROM ticket_statuses ORDER BY code;
```

The query returns `closed`, `open`, and `waiting`, each with position `100`. To rehearse the reverse change on the demo database, run the following commands.

```shell
php atom migrate:rollback
php atom migrate:status
```

The column migration is `pending` again and the original table migration is still marked `ran`. Run `PRAGMA table_info(ticket_statuses);` to see the remaining `code` and `label` columns. The earlier `SELECT code, label` query still returns all three choices, including any label you edited.
