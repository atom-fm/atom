# Cache

Atom’s file-backed cache can reuse a computed value across requests. This example caches the number of units in a stock file and refreshes the total after a delivery.

## Usage

In the root of an Atom Starter project with Composer dependencies installed, create `stock.json` with two products.

```json
[
    {"sku": "notebook", "units": 4},
    {"sku": "pencil-case", "units": 3}
]
```

Save the following as `stock-total.php` beside it. On a cache miss, `remember()` runs the callback and stores the returned total for 300 seconds. On a hit, it returns the stored total immediately.

```php
<?php

declare(strict_types=1);

use App\Application;

require __DIR__ . "/vendor/autoload.php";

$app = new Application();
$app->initialize();
$cache = $app->getCache();
$key = "guide.stock-total";

if (in_array("--refresh", $argv, true)) {
    $cache->delete($key);
}

$total = $cache->remember($key, 300, static function (): int {
    echo "Reading stock.json", PHP_EOL;
    $contents = file_get_contents(__DIR__ . "/stock.json");
    $stock = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    return array_sum(array_column($stock, "units"));
});

echo "Units available: {$total}", PHP_EOL;
```

### Read the total twice

Run both commands from the project root. The first clears this example’s key so you can repeat the walkthrough.

```shell
php stock-total.php --refresh
php stock-total.php
```

The first run prints `Reading stock.json` followed by `Units available: 7`. The second prints only `Units available: 7`. A separate PHP process reused the result.

### Refresh after a delivery

Change the notebook quantity in `stock.json` from `4` to `7`. Within the five-minute cache lifetime, `php stock-total.php` still prints `Units available: 7`. Run `php stock-total.php --refresh` to delete the stock total and recalculate it immediately. The command prints these two lines.

```text
Reading stock.json
Units available: 10
```

In an application, call `delete()` on the same key after a successful stock update. The next read then picks up the delivery. Services can receive `Atom\Cache\CacheInterface` through constructor injection to access the same cache.

Use `set()` when you already have a value and `get()` to read it with an optional fallback. Values must be serializable. Cached `null` and `false` are returned as stored.

## Configuration

```env
CACHE_DIRECTORY=@root/storage/cache
CACHE_PREFIX=atom
CACHE_DEFAULT_TTL=0
```

TTL values are seconds. `0` means no expiration, and a negative TTL removes or declines to store the value. `DateInterval` instances are also accepted by the API. The prefix creates an isolated namespace; clearing one prefix does not clear another.

## Atomic operations

`remember()`, `add()`, and `increment()` hold an exclusive per-key file lock, making them safe across PHP workers:

```php
$acquired = $cache->add("job:import", true, 30);
$attempts = $cache->increment("login:{$clientIp}", 1, 60);
```

When incrementing an existing counter, its original expiration is preserved. This supports fixed-window rate limiting without extending the window on every request.

The file store uses hashed paths, atomic replacement, namespace-wide locking for `clear()`, and automatic removal of expired or corrupt entries. A database or distributed driver can implement `CacheInterface` later without changing application consumers.

### Count catalogue views in a fixed window

To see which product pages are busy, keep a short-lived counter for each SKU. Each page view increments its product's counter and gets the updated count back. The file cache locks that key during the update, so simultaneous views from PHP workers sharing the same cache directory each contribute one count.

Save `count-view.php` in the root of an installed Starter project. Each invocation represents one view. The ten-second window makes expiry easy to observe locally.

```php
<?php

declare(strict_types=1);

use App\Application;

require __DIR__ . "/vendor/autoload.php";

$app = new Application();
$app->initialize();

$sku = $argv[1] ?? "notebook";
$views = $app->getCache()->increment("guide.views.{$sku}", amount: 1, ttl: 10);
echo "{$sku}: {$views}", PHP_EOL;
```

Starting with fresh counters, run this block in PowerShell. The first view starts the notebook's window. The second view arrives six seconds later, and the last arrives after the original window expires.

```powershell
php count-view.php notebook
php count-view.php pencil
Start-Sleep -Seconds 6
php count-view.php notebook
Start-Sleep -Seconds 5
php count-view.php notebook
```

```text
notebook: 1
pencil: 1
notebook: 2
notebook: 1
```

The pencil gets a separate counter because its SKU is part of the key. The second notebook view keeps the original expiry. Once it passes, the next increment starts a new window at one.

## Maintenance commands

### Prune expired previews and keep published prices

A storefront caches a draft price briefly and keeps the published price until the next update. If nobody requests the preview again, its expired file can stay on disk. Run `php atom cache:prune` to reclaim it while keeping the published price available.

In a fresh Starter app, set `CACHE_PREFIX=price-guide` in `.env` so the example has its own cache namespace. Save `price-cache.php` beside the `atom` command.

```php
<?php

declare(strict_types=1);

use App\Application;

require __DIR__ . "/vendor/autoload.php";

$app = new Application();
$app->initialize();
$cache = $app->getCache();

if (($argv[1] ?? "") === "seed") {
    $cache->set("price.preview", "12.50", 1);
    $cache->set("price.published", "14.00", 0);
    exit(0);
}

echo "Preview: ", $cache->get("price.preview", "missing"), PHP_EOL;
echo "Published: ", $cache->get("price.published", "missing"), PHP_EOL;
```

Run these commands in PowerShell from the project root. The preview expires after one second. Waiting two seconds and running the prune command before reading either price lets you see how many entries the command removed.

```powershell
php price-cache.php seed
Start-Sleep -Seconds 2
php atom cache:prune
php price-cache.php
php atom cache:prune
```

```text
Pruned 1 cache entry.
Preview: missing
Published: 14.00
Pruned 0 cache entries.
```

The published price has a TTL of `0`, so it survives both runs. Use `php atom cache:clear` when you intend to remove every entry in the active prefix, including unexpired values.

The cache service provider registers both commands. The file cache also removes corrupt entries during pruning.

Future cache drivers may optionally implement `PrunableCacheInterface`. The prune command exits with an error when the active driver does not support explicit pruning.
