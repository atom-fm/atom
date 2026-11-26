# Application and Service Providers

[Atom Framework](Index.md)

An Atom application extends `Atom\Application`.

Each application hook configures a part of startup.

- `rootPath()` and `configurePaths()` control path aliases
- `environmentFiles()` customizes `.env` files loaded before config and services
- `services()` registers providers and bindings
- `jobs()` registers application background job types
- `schedule()` registers application scheduled tasks
- `modules()` registers framework and application modules
- `components()` registers application component tags
- `pages()` registers page directories
- `bootstrap()` performs runtime setup such as model database configuration and API routes

## Application Example

This application opts in to database services. See [database setup](Database.md#services) for the Composer dependency and connection settings. A routing-and-pages app can start with Framework alone.

```php
namespace App;

use App\Components\Table;
use App\Controllers\ApiController;
use Atom\Database\DatabaseServices;
use Atom\Di\Injector;
use Atom\Di\ServiceProviderRegistry;
use Atom\Module\ModuleRegistry;
use Atom\Modules\Client\Client;
use Atom\Components\Components;
use Atom\Integration\View\ComponentModule;
use Atom\Page\PageRegistry;
use Atom\Router\Route;
use Atom\View\Component\ComponentRegistry;

final class Application extends \Atom\Application
{
    protected function rootPath(): string
    {
        return dirname(__DIR__);
    }

    protected function services(ServiceProviderRegistry $providers): void
    {
        $providers->add(DatabaseServices::fromConfig($this->getConfig(), $this->getPaths()));
    }

    protected function modules(ModuleRegistry $modules): void
    {
        $modules->add(Client::module());
        $modules->add(new ComponentModule(
            Components::definitions(),
            Components::resourceDirectory(),
            Components::DEFAULT_RESOURCE_PATH
        ));
    }

    protected function pages(PageRegistry $pages): void
    {
        $pages->directory("@app/Pages");
    }

    protected function components(ComponentRegistry $components): void
    {
        $components->register("Table", Table::class);
    }

    protected function bootstrap(Injector $injector): void
    {
        Route::attach(ApiController::class);
    }
}
```

## Framework Defaults

The base application registers framework services for:

- console
- dispatcher
- pages and view rendering
- legacy PHP views
- router
- request and response wrappers
- lazy, request-scoped sessions and flash data
- session-backed CSRF tokens and route middleware

Application providers are added on top of those defaults.

## Handling Requests

### Smoke-test the homepage from the command line

Use `Application::handle()` in a test script to dispatch a request and inspect the returned `Response`. The script checks the homepage status and heading, then requests an unknown URL and verifies its status.

Save `check-routes.php` beside `composer.json` in an installed Atom Starter app. If you have customized the homepage, replace the expected heading with text from your page.

```php
<?php

declare(strict_types=1);

use App\Application;
use Atom\Http\Request;

require __DIR__ . "/vendor/autoload.php";

$app = new Application();
$home = $app->handle(new Request("GET", "/"));
if ($home->getStatus() !== 200) {
    fwrite(STDERR, "Expected GET / to return 200, got " . $home->getStatus() . PHP_EOL);
    exit(1);
}

if (!str_contains($home->getContent(), "A small foundation for your next website.")) {
    fwrite(STDERR, "Homepage heading missing." . PHP_EOL);
    exit(1);
}

$missing = $app->handle(new Request("GET", "/missing-route-check"));
if ($missing->getStatus() !== 404) {
    fwrite(STDERR, "Expected missing route to return 404, got " . $missing->getStatus() . PHP_EOL);
    exit(1);
}

echo "Homepage rendered and missing route returned 404.", PHP_EOL;
```

Run `php check-routes.php` from the project root. On success it prints `Homepage rendered and missing route returned 404.` A failed check prints the reason to standard error and exits with code `1`, so the same command can run in CI.

Use `run()` in a front controller. It calls `handle()`, emits the response, and returns it:

```php
(new App\Application())->run();
```

## Bootstrappers

Service providers can contribute runtime bootstrappers by implementing `BootstrapProviderInterface`.

Bootstrappers run after modules, components, and pages are registered, and before the application `bootstrap()` hook. `DatabaseServices` uses this to configure the `Model` base class automatically.

## Profiling

`atom-fm/profiler` provides the `Atom\Profiler` classes. Framework binds `Profiler` in the application container. `Profile::begin()` and `Profile::measure()` use the active profiler provided by Application. In standalone scripts, they record spans in a default profiler available through `Profile::profiler()`.

```php
use Atom\Profiler\Profile;

$span = Profile::begin("view.render");

try {
    // work
} finally {
    $span->end();
}

$result = Profile::measure("request.dispatch", fn() => $handler->handle($request));
```

Each HTTP response reports its own timing in `X-Atom-Time` and grouped headers such as `X-Atom-Time-Request-Dispatch`, `X-Atom-Time-Page-Render`, `X-Atom-Time-View-Parse`, and `X-Atom-Time-View-Render`. Repeated spans within that request include a count, for example `0.42ms; count=3`. When a request triggers application initialization, its response reports that duration separately in `X-Atom-Time-App-Initialize`. Outside request handling, the application profiler contains bootstrap timings.

### Measure repeated work in one request

Suppose a stock report reads two suppliers' JSON feeds. To see how many times the route decodes a feed and how long decoding takes in total, wrap each decode in `Profile::measure()` with the same name.

In a Starter app, import `Atom\Router\Route` and `Atom\Profiler\Profile` in `app/Application.php`. Add this route inside `bootstrap()`. If the method is missing, use the signature in [Application Example](#application-example). The two sample feeds keep the report runnable offline.

```php
Route::get("/reports/stock", static function (): array {
    $feeds = ['{"units":12}', '{"units":8}'];
    $units = 0;
    foreach ($feeds as $feed) {
        $stock = Profile::measure("stock.decode", static fn(): array =>
            json_decode($feed, true, flags: JSON_THROW_ON_ERROR)
        );
        $units += $stock["units"];
    }

    return ["units" => $units];
});
```

Start `php atom serve` from the project root. In another PowerShell terminal, request the report with its response headers.

```powershell
curl.exe -i http://127.0.0.1:8020/reports/stock
```

The body reads `{"units":20}`. Find `X-Atom-Time-Stock-Decode` in the headers. Its millisecond value adds up the time spent in both callbacks, and `count=2` confirms both feeds were decoded. Try removing the second feed and repeat the request. The body now reads `{"units":12}` and the timing header shows only the duration, because Atom includes a count for repeated spans.

## Service Providers

Service providers group DI registrations:

```php
use Atom\Di\Bindings;
use Atom\Di\ServiceProviderInterface;

final class BlogServices implements ServiceProviderInterface
{
    public function register(Bindings $bindings): void
    {
        $bindings->bind(PostService::class)
            ->toSelf()
            ->singleton();
    }
}
```

Register a provider class:

```php
$providers->add(BlogServices::class);
```

Register a configured provider instance:

```php
$providers->add(new DatabaseServices(new SqliteDriver($path)));
```

## Console Providers

Providers can also expose console command discovery paths by implementing `ConsoleCommandProviderInterface`:

```php
use Atom\Console\ConsoleCommandProviderInterface;
use Atom\Console\ConsoleCommandSources;
use Atom\Di\Bindings;
use Atom\Di\ServiceProviderInterface;

final class BlogServices implements ServiceProviderInterface, ConsoleCommandProviderInterface
{
    public function register(Bindings $bindings): void
    {
    }

    public function consoleCommands(ConsoleCommandSources $commands): void
    {
        $commands->add(__DIR__ . "/Commands", "App\\Blog\\Commands");
    }
}
```

The application console discovers these command sources automatically.
