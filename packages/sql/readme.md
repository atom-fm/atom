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
