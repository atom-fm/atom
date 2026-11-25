# Configuration

[Atom Framework](Index.md)

Atom loads application settings from `.env`. To use those settings in PHP, follow the [CSV export example](#typed-options).

The starter reads settings such as the application environment and database connection from a key/value file.

```env
APP_ENV=local
APP_DEBUG=true
DB_DRIVER=sqlite
DB_DATABASE=storage/atom_sample.sqlite
DB_HOST=localhost
DB_PORT=
DB_USERNAME=
DB_PASSWORD=
DB_CHARSET=utf8mb4
```

## Loading

Applications load `@root/.env` by default. Environment files are loaded before typed config is created and before services are registered.

To give local settings priority, list the optional local file first. Atom checks the files in order and uses the first value it finds for each key that the process environment has not already set.

```php
protected function environmentFiles(): array
{
    return [
        "@root/.env.local",
        "@root/.env",
    ];
}
```

Load a file when it exists:

```php
use Atom\Config\Env;

Env::loadIfExists(dirname(__DIR__) . "/.env");
```

Load a required file:

```php
Env::load(dirname(__DIR__) . "/.env");
```

Existing environment variables are not overwritten by default.

```php
Env::load($path, override: true);
```

## Reading Values

```php
Env::string("APP_ENV", "production");
Env::bool("APP_DEBUG", false);
Env::int("HTTP_PORT", 8080);
Env::float("RATIO", 1.0);
Env::get("OPTIONAL_VALUE");
Env::has("DB_DATABASE");
```

Boolean values recognize:

```text
true:  1, true, yes, on
false: 0, false, no, off, empty string
```

## Syntax

Supported syntax:

```env
# comments
NAME=Atom
EMPTY=
QUOTED="Hello Atom"
export EXPORTED=yes
```

The loader does not currently support advanced dotenv features like variable expansion or multiline values.

## Typed Options

### Configure a CSV export

Suppose a warehouse needs a short stock list with column headings, but its import tool expects just the data rows. Give the export two settings so you can change its output in `.env`.

In an Atom Starter project with Composer dependencies installed, create `app/Config/StockExportOptions.php`.

```php
<?php

declare(strict_types=1);

namespace App\Config;

use Atom\Config\Options;

#[Options(prefix: "STOCK_EXPORT_")]
final readonly class StockExportOptions
{
    public function __construct(
        public int $maxRows = 2,
        public bool $includeHeader = true
    ) {
    }
}
```

Atom combines the prefix with each constructor parameter's name in uppercase snake case. For example, `$maxRows` reads `STOCK_EXPORT_MAX_ROWS`. If the key is absent, Atom uses the constructor default.

Save the following as `export-stock.php` in the project root. Initializing the starter loads its `.env`. Calling `options()` gives the script an integer row limit and a boolean header setting.

```php
<?php

declare(strict_types=1);

use App\Application;
use App\Config\StockExportOptions;

require __DIR__ . "/vendor/autoload.php";

$app = new Application();
$app->initialize();
$options = $app->getConfig()->options(StockExportOptions::class);

$stock = [["notebook", 4], ["pencil", 12], ["binder", 3]];
if ($options->includeHeader) {
    fputcsv(STDOUT, ["sku", "units"], escape: "");
}
foreach (array_slice($stock, 0, max(0, $options->maxRows)) as $row) {
    fputcsv(STDOUT, $row, escape: "");
}
```

Run `php export-stock.php` with the defaults. The header is separate from the two-row limit.

```text
sku,units
notebook,4
pencil,12
```

For the import tool, add these settings to the project's `.env` and run the same command again.

```env
STOCK_EXPORT_MAX_ROWS=3
STOCK_EXPORT_INCLUDE_HEADER=no
```

```text
notebook,4
pencil,12
binder,3
```

Each CLI run starts a new application. Within one application, `options()` reuses the object it first resolved. Application services can also receive `StockExportOptions` through constructor injection.

### Preview one stock row with a local setting

Keep the three-row CSV export as the project default and use a one-row limit on your machine. Start with the `export-stock.php` script above and its two settings in `.env` (`STOCK_EXPORT_MAX_ROWS=3` and `STOCK_EXPORT_INCLUDE_HEADER=no`).

Add the `environmentFiles()` method from [Loading](#loading) to `app/Application.php`, with `.env.local` first. In the project root, create `.env.local` with just this setting.

```env
STOCK_EXPORT_MAX_ROWS=1
```

Run `php export-stock.php`. The export takes its row limit from `.env.local` and its header setting from `.env`, so it prints a single data row.

```text
notebook,4
```

Remove the `STOCK_EXPORT_MAX_ROWS` line from `.env.local` and run the command again. All three data rows return. Atom skips a missing file, so a checkout with only `.env` also uses the three-row limit. If your shell already defines `STOCK_EXPORT_MAX_ROWS`, its value takes precedence over both files.

### Map an existing environment key

If the warehouse already uses `STOCK_EXPORT_LIMIT`, import `Atom\Config\FromEnv` and annotate the row-limit parameter in the options constructor.

```php
#[FromEnv("LIMIT")]
public int $maxRows = 2,
```

The class prefix still applies. Set `STOCK_EXPORT_LIMIT` to control this parameter. Atom also supports strings, floats, nullable values, and string-backed enums. A missing non-nullable parameter without a default raises an exception that names the required key.

### Choose a delimiter for the stock export

One warehouse imports comma-separated files and another needs semicolons. Give the [CSV export](#configure-a-csv-export) a delimiter setting that accepts those two characters. Create `app/Config/StockDelimiter.php` alongside `StockExportOptions.php`.

```php
<?php

declare(strict_types=1);

namespace App\Config;

enum StockDelimiter: string
{
    case Comma = ",";
    case Semicolon = ";";
}
```

In `StockExportOptions`, add a comma after the `$includeHeader` parameter and append this constructor parameter.

```php
public StockDelimiter $delimiter = StockDelimiter::Comma,
```

In `export-stock.php`, pass `$options->delimiter->value` as the `separator` argument in both `fputcsv()` calls. Use the same character for the headings and data rows.

```php
if ($options->includeHeader) {
    fputcsv(STDOUT, ["sku", "units"], separator: $options->delimiter->value, escape: "");
}
foreach (array_slice($stock, 0, max(0, $options->maxRows)) as $row) {
    fputcsv(STDOUT, $row, separator: $options->delimiter->value, escape: "");
}
```

Set `STOCK_EXPORT_DELIMITER=";"` in `.env` and run `php export-stock.php` from the project root. The notebook row reads `notebook;4`. With headings enabled, the first line reads `sku;units`.

The setting takes the enum's backing character. Removing it restores comma-separated output on the next run. Setting it to `semicolon` raises a `ValueError` when `options()` resolves the class, before the script writes any CSV rows.

### Set options in PHP

In `app/Application.php`, the `configure()` hook runs after environment files are loaded. To supply a fixed export policy, import `Atom\Config\Config` and `App\Config\StockExportOptions` and set the object in the application registry.

```php
protected function configure(Config $config): void
{
    $config->set(new StockExportOptions(maxRows: 3, includeHeader: false));
}
```

Services, bootstrappers, and modules use the same registry. A module can retrieve the settings with `$context->config->options(StockExportOptions::class)`. An explicit container binding takes precedence when resolving an options class through injection.
