# Logging

[Atom Framework](Index.md)

When a route throws an unhandled exception, Atom returns HTTP 500. With file logging enabled, you can use its request ID to find the route that failed and the exception that caused it.

## Enable file logging

Start in an Atom Starter project with Composer dependencies installed. In `app/Application.php`, import the logging module.

```php
use Atom\Modules\Logging\Logging;
```

Add this line inside the existing `modules(ModuleRegistry $modules)` method, alongside the starter's module registrations.

```php
$modules->add(Logging::file(dirname(__DIR__) . "/storage/logs/app.log"));
```

The absolute path sends CLI and web requests to the same file. Atom creates the log directory on the first write. To configure the destination per environment, register `Logging::module()` instead and set `LOG_PATH` to an absolute file path in `.env`. You can also set a `LoggingOptions` instance in the application's `configure()` method.

## Trace a failed request

Create `trace-report.php` in the project root. The script registers a report route for this process and sends it one request with a known ID. The exception stands in for a report service that is temporarily unavailable.

```php
<?php

declare(strict_types=1);

use App\Application;
use Atom\Http\Request;
use Atom\Router\Route;

require __DIR__ . "/vendor/autoload.php";

$app = new Application();
$app->initialize();

Route::get("/reports/daily", static function (): never {
    throw new RuntimeException("Daily totals are unavailable");
})->name("reports.daily");

$request = new Request("GET", "/reports/daily", headers: [
    "X-Request-Id" => "report-demo-42",
]);
$response = $app->handle($request);
echo "HTTP ", $response->getStatus(), PHP_EOL;
```

Run the script from the project root. It prints `HTTP 500`. In PowerShell, find the matching log entry by its request ID.

```powershell
php trace-report.php
Select-String -Path storage/logs/app.log -SimpleMatch '"request_id":"report-demo-42"'
```

Look for `ERROR: Unhandled exception` followed by JSON. The `route_name` field identifies `reports.daily`, and the nested `exception` object gives the type (`RuntimeException`), message (`Daily totals are unavailable`), file and line. Atom's error handler writes this entry automatically.

## Find the last report step before an error

A stack trace identifies where a report failed. To see what the application was doing just beforehand, log the report step with the same request ID. Start with `trace-report.php` from [Trace a failed request](#trace-a-failed-request) and the file logger writing to `storage/logs/app.log`.

Add `use Atom\Logging\LoggerInterface;` to the script's imports. Replace its `Route::get(...)` registration with this version. Atom supplies the current request and the configured logger to the route.

```php
Route::get("/reports/daily", static function (Request $request, LoggerInterface $logger): never {
    $logger->info("Loading daily totals", [
        "request_id" => $request->headers()->get("X-Request-Id"),
        "report" => "daily",
    ]);

    throw new RuntimeException("Daily totals are unavailable");
})->name("reports.daily");
```

Run the script again and read the last two entries for the walkthrough's request ID.

```powershell
php trace-report.php
Select-String -Path storage/logs/app.log -SimpleMatch '"request_id":"report-demo-42"' |
    Select-Object -Last 2 -ExpandProperty Line
```

The `INFO: Loading daily totals` entry identifies the report. The following `ERROR: Unhandled exception` entry gives the route and exception. Both contain `"request_id":"report-demo-42"`. The route copies that field from the request into its own log context, and Atom's error handler records it when the exception escapes.

## Capture one diagnostic run in its own log

When reproducing a failure, write the trace to a fresh file so earlier runs with the same request ID cannot appear in the results. In `app/Application.php`, replace the walkthrough's `Logging::file(...)` registration with the following line. This tells the module to read its destination from `LOG_PATH` during startup.

```php
$modules->add(Logging::module());
```

Set `LOG_PATH` in the project's `.env` to the absolute path of your usual `storage/logs/app.log`. For a single diagnostic run, override it in PowerShell before starting PHP. Run this from the project root with the `trace-report.php` script above.

```powershell
$traceFile = [System.IO.Path]::GetTempFileName()
$previousLogPath = $env:LOG_PATH
try {
    $env:LOG_PATH = $traceFile
    php trace-report.php
} finally {
    $env:LOG_PATH = $previousLogPath
}

Select-String -LiteralPath $traceFile -SimpleMatch '"request_id":"report-demo-42"'
```

The script prints `HTTP 500`. The search finds one matching entry in the temporary file, including `"route_name":"reports.daily"`. The PHP process inherits the shell setting, which takes precedence over `.env`. The `finally` block restores the shell setting for later commands. Once you have finished inspecting the trace, delete its temporary file with `Remove-Item -LiteralPath $traceFile`.

## Facade

For events your application records itself, inject `Atom\Logging\LoggerInterface` into a service. Call `$logger->info()` or `$logger->error()` with a message and an optional array of context fields. A CLI script that has initialized the application can also use the `Log` facade.

```php
use Atom\Logging\Log;

Log::info("Report generated", ["report" => "daily", "rows" => 12]);
```

The current application supplies the configured logger. Standalone calls and initialized applications with no logger binding use `NullLogger`.

## Container Bridge

`Container` is the official static bridge to the current application injector:

```php
use Atom\Container;
use Atom\Logging\LoggerInterface;

$logger = Container::get(LoggerInterface::class);
```

Prefer constructor injection for application code. Use `Container` and `Log` for bootstrapping, scripts, framework internals, or short-lived glue code.
