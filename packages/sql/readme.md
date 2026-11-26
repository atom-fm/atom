# Atom SQL

Atom SQL builds parameterized statements for SQLite, MySQL, and PostgreSQL. It returns the SQL and parameters so the application can execute them using its own connection.

Install with `composer require atom-fm/sql`. PHP 8.4 is required.

```php
use Atom\Database\Sql\Compiler\SqliteCompiler;
use Atom\Database\Sql\Query;

$query = Query::select("articles")
    ->columns("id", "title")
    ->where("status", "published")
    ->orderBy("id");

$command = (new SqliteCompiler())->compile($query);
$statement = $pdo->prepare($command->sql);
$statement->execute($command->parameters);
$articles = $statement->fetchAll(PDO::FETCH_ASSOC);
```

## Supported API

SQL is in maintenance mode. Routine changes focus on fixes, compatibility, and documentation for the APIs below.

| Area | Contract |
| --- | --- |
| Query construction | Use `Query::select()`, `insert()`, `update()`, and `delete()`, or construct an `UpsertQuery` for batched writes. The fluent builders are mutable. |
| Select composition | Use projections, aggregates, joins, grouped conditions, ordering, grouping, pagination, and subqueries. `Op` provides comparison, range, null, list, and pattern predicates. An empty `IN` list matches no rows. |
| SQL expressions | Use expression methods such as `whereExp()`, `havingExp()`, `scalarColumn()`, and `orderByExp()` with application-authored SQL and bound values. Fragments with their own bindings can reuse a placeholder name. The compiler keeps the values separate. |
| Compilation | `QueryCompilerInterface::compile()` accepts a `SqlQueryInterface` and returns a `Command` with `sql` and `parameters`. Choose `SqliteCompiler`, `MySqlCompiler`, or `PostgresCompiler` to match the connection. Dialect-specific features depend on the target database. |

## ORM integration

- ORM compiles through `QueryCompilerInterface` and executes the resulting `Command` through PDO. SQL owns statement generation and bindings. ORM owns connections, execution, and model hydration.
- ORM clones `SelectQuery` before changing projections, filters, ordering, or limits for counts, pagination, and lookups. Clones must keep nested query state independent. ORM uses `setModelColumns()`, `clearColumns()`, and `WhereGroup::groupExisting()` for these operations.
- Relationship queries use `Column`, `Table`, join relation metadata, and correlated subqueries. ORM uses `Command::withParameterPrefix()` to combine separately compiled counts without binding collisions.

## Dialect limits

- `Op::ilike()` is supported by the PostgreSQL compiler. The SQLite and MySQL compilers throw during compilation.
- `DeleteQuery::limit()` is supported by the MySQL compiler. The SQLite and PostgreSQL compilers throw during compilation. The SQLite compiler uses the same rule across builds, including those with optional support for limited deletes.
