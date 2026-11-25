# Paths

[Atom Framework](Index.md)

Use a path alias when an application file has a stable location inside the project. For example, a packing-list script can find the same file whether you launch it from the project root or a different working directory.

## Register a directory for packing lists

In an Atom Starter project with Composer dependencies installed, create `storage/packing-lists/` and save the following as `storage/packing-lists/dispatch.txt`.

```text
2 notebooks
1 pencil case
```

In `app/Application.php`, add this import alongside the other imports.

```php
use Atom\Support\Paths;
```

Add the following method inside `Application`. If the class already has a `configurePaths()` method, add the alias to that method. The starter sets `@root` to the project root, so the alias points to the directory you just created.

```php
protected function configurePaths(Paths $paths): void
{
    $paths->alias("packing_lists", $paths->resolve("@root/storage/packing-lists"));
}
```

## Print a list from another directory

Save this script as `print-packing-list.php` in the project root. It accepts a filename and defaults to `dispatch.txt`.

```php
<?php

declare(strict_types=1);

use App\Application;

require __DIR__ . "/vendor/autoload.php";

$app = new Application();
$app->initialize();
$file = $app->getPaths()->resolveFrom("@packing_lists", $argv[1] ?? "dispatch.txt");

if (!is_file($file) || !is_readable($file)) {
    fwrite(STDERR, "Cannot read packing list: {$file}" . PHP_EOL);
    exit(1);
}

readfile($file);
```

Run these commands from the project root. Both invocations print the two items from `dispatch.txt`.

```shell
php print-packing-list.php
cd storage
php ../print-packing-list.php dispatch.txt
```

`resolveFrom()` joins a relative filename to `@packing_lists`. If you pass an alias such as `@root/storage/packing-lists/dispatch.txt`, it expands that alias directly. An absolute filename uses the supplied location. Atom normalizes backslashes to forward slashes in all three forms.

## Download the current packing list

Give the warehouse team a stable URL for the same `dispatch.txt` that the CLI script prints. Keep the file in `storage/packing-lists`. The route can receive the application's configured `Paths` object and use `@packing_lists` to locate it.

In `app/Application.php`, keep the `Paths` import from the alias setup and add these imports.

```php
use Atom\Di\Injector;
use Atom\Http\Response;
use Atom\Router\Route;
```

Add the route to `bootstrap()`. If your application already defines this method, add the route inside its existing body.

```php
protected function bootstrap(Injector $injector): void
{
    Route::get("/warehouse/dispatch-list", static function (Paths $paths, Response $response): Response {
        $file = $paths->resolve("@packing_lists/dispatch.txt");
        if (!is_file($file)) {
            return $response->status(404)->json(["error" => "No dispatch list is ready."]);
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new \RuntimeException("Could not read the dispatch list.");
        }

        return $response
            ->header("Content-Type", "text/plain; charset=utf-8")
            ->header("Content-Disposition", 'attachment; filename="dispatch.txt"')
            ->content($contents);
    });
}
```

From the project root, run `php atom serve`. In a second PowerShell terminal, fetch the list with its response headers.

```powershell
curl.exe -i http://127.0.0.1:8020/warehouse/dispatch-list
```

Look for HTTP `200` and `Content-Disposition: attachment; filename="dispatch.txt"`, followed by the same two lines as the CLI output. Edit the quantities in `storage/packing-lists/dispatch.txt` and request the URL again to see the updated list. If the file is missing, the route returns HTTP `404` with `{"error":"No dispatch list is ready."}`.

## Built-in aliases

```text
@root        application root
@app         @root/app
@public      @root/public
@database    @app/Database
@migrations  @database/Migrations
@seeders     @database/Seeders
```

In the base application, `rootPath()` defaults to the directory containing the concrete application class. Atom Starter overrides it in `app/Application.php` with `return dirname(__DIR__);` so that `@root` is one level above `app/`.

Use `$paths->resolve("@app/Pages")` for a known alias. Application services can receive `Atom\Support\Paths` through constructor injection. Inside an `Application` method, `$this->path("@app/Pages")` resolves the same alias.

## Use a project-level migrations directory

If your project keeps schema changes in `database/migrations`, set the `migrations` alias so the generator and migration runner use that directory. Try the change in a fresh Starter project with PDO SQLite enabled. From its root, apply the Starter migration, then move its files with their original names.

```powershell
php atom migrate
New-Item -ItemType Directory -Path database | Out-Null
Move-Item -LiteralPath app/Database/Migrations -Destination database/migrations
```

In `app/Application.php`, import `Atom\Support\Paths` and add this method. If you already configured the packing-list directory above, add just the `alias()` call to that method.

```php
protected function configurePaths(Paths $paths): void
{
    $paths->alias("migrations", $paths->resolve("@root/database/migrations"));
}
```

Create a migration and check what Atom discovers.

```powershell
php atom make:migration create_shipments
Get-ChildItem -Name database/migrations
php atom migrate:status
```

The directory contains `M0001_create_users_table.php` and a new file ending in `_create_shipments.php`. The status report shows `ran` for the users migration and `pending` for shipments. Keeping the original filename lets Atom match the moved migration to its database history. Edit the new migration's `up()` and `down()` methods before applying it.
