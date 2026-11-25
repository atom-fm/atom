# Background Jobs and Queues

[Atom Framework](Index.md)

Queue an order's picking list so the process accepting the order can finish before a warehouse worker writes the file. This walkthrough uses the file queue in a fresh Atom Starter project with Composer dependencies installed.

## Defining Jobs

Set `QUEUE_DRIVER=file` in the project's `.env`. The default `sync` driver would run the job as soon as you dispatch it.

Create `app/Jobs/WritePickingList.php`. The order number and items travel with the job as JSON. The worker supplies the `Paths` service when it calls `handle()`.

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use Atom\Queue\Job;
use Atom\Support\Paths;
use RuntimeException;

final readonly class WritePickingList extends Job
{
    /** @param array<string, int> $items Product codes mapped to quantities. */
    public function __construct(private int $orderId, private array $items)
    {
    }

    public static function type(): string
    {
        return "warehouse.write-picking-list";
    }

    public function handle(Paths $paths): void
    {
        $lines = ["Order {$this->orderId}"];
        foreach ($this->items as $code => $quantity) {
            $lines[] = "{$quantity} x {$code}";
        }

        $path = $paths->resolve("@root/storage/pick-{$this->orderId}.txt");
        if (file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException("Could not write the picking list.");
        }
    }
}
```

In `app/Application.php`, import `App\Jobs\WritePickingList` and `Atom\Queue\JobRegistry`. Register the class in the application's `jobs()` hook so the worker can map the saved type to the PHP class. If the hook already exists, add the registration to it.

```php
protected function jobs(JobRegistry $jobs): void
{
    $jobs->register(WritePickingList::class);
}
```

## Dispatch and process one order

Save `dispatch-order.php` in the project root. Dispatching returns a job ID. Use the same queue name when starting the worker.

```php
<?php

declare(strict_types=1);

use App\Application;
use App\Jobs\WritePickingList;
use Atom\Queue\JobDispatcherInterface;

require __DIR__ . "/vendor/autoload.php";

$app = new Application();
$app->initialize();
$dispatcher = $app->getInjector()->get(JobDispatcherInterface::class);

$job = new WritePickingList(42, ["NOTEBOOK" => 2, "PENCIL" => 5]);
echo $dispatcher->dispatch($job, queue: "warehouse"), PHP_EOL;
```

From the project root, run `php dispatch-order.php` once. The file queue now holds the job. Run the worker and inspect its output file in PowerShell.

```powershell
php atom queue:once --queue=warehouse
Get-Content storage/pick-42.txt
```

The worker prints `Job completed.`. Open the file to see the order and quantities.

```text
Order 42
2 x NOTEBOOK
5 x PENCIL
```

Run the worker once more. It prints `No jobs available.` because the first run completed the only queued order. To make a job available later, pass a delay in seconds, for example `$dispatcher->dispatch($job, delay: 300, queue: "warehouse")`.

### Job payloads and registration

The base `Job` class derives `payload()` and `fromPayload()` from constructor properties. Use JSON-safe values. Missing required fields, unknown fields and incompatible values raise a `QueueException`. A default for a new optional parameter lets workers read older payloads. Implement `JobInterface` directly when you need a custom payload format.

Modules register their job classes with `$context->jobs(...)` during module registration. Application and module jobs share one registry. Choose a distinct, stable type for each class.

### Add a packing station to orders already in the queue

Suppose new picking lists need a packing station, and orders dispatched yesterday should use the main desk. In a disposable copy of the [picking-list example](#dispatch-and-process-one-order), start with an empty `warehouse` file queue and the original `WritePickingList` class. Run `php dispatch-order.php` once. Leave order 42 pending while you change the worker code.

Replace the constructor in `app/Jobs/WritePickingList.php` with the following version. Keep the job type and the existing parameter names so the new worker recognizes the saved order.

```php
public function __construct(
    private int $orderId,
    private array $items,
    private string $station = "Main desk",
) {
}
```

In `handle()`, replace the line that initializes `$lines`.

```php
$lines = ["Order {$this->orderId}", "Station: {$this->station}"];
```

Run these commands from the project root. Order 42 was queued before `station` existed, so `Job::fromPayload()` supplies the constructor default. The second line of the file reads `Station: Main desk`.

```powershell
php atom queue:once --queue=warehouse
Get-Content storage/pick-42.txt
```

Now replace the `$job` assignment in `dispatch-order.php` to send a new order with an explicit station.

```php
$job = new WritePickingList(43, ["PENCIL" => 3], station: "North desk");
```

```powershell
php dispatch-order.php
php atom queue:once --queue=warehouse
Get-Content storage/pick-43.txt
```

Order 43 prints `Station: North desk` followed by `3 x PENCIL`. When rolling this change out, update and restart any long-running workers before dispatchers start sending `station`. Workers running the old class reject that field as unknown.

## Drivers

The default `sync` driver executes jobs in the current process and is suitable during development.
It intentionally ignores delays.

The file driver stores one JSON document per job under `storage/queue`. Atomic moves between
`pending`, `reserved`, and `failed` directories prevent two local workers from claiming the same
job:

```env
QUEUE_DRIVER=file
QUEUE_NAME=default
```

The database driver uses the conventional `atom_jobs` and `atom_failed_jobs` tables. It claims jobs
with guarded updates and reservation identifiers, which works with SQLite, MySQL, and PostgreSQL:

```env
QUEUE_DRIVER=database
QUEUE_NAME=default
```

Publish and run its migration once:

```powershell
php atom queue:publish
php atom migrate
```

Existing migration files are not overwritten unless `queue:publish --force` is used.

## Workers and Retries

Process one available job:

```powershell
php atom queue:once
```

Run a persistent CLI worker under a process supervisor:

```powershell
php atom queue:work
php atom queue:work --queue=reports
```

The worker releases a job for retry after `QUEUE_RETRY_DELAY` seconds when the handler fails and
attempts remain. If the final allowed attempt fails, the worker moves the job to failed storage.
A reservation older than `QUEUE_RETRY_AFTER` can be claimed by another worker. If that claim
exceeds `QUEUE_MAX_ATTEMPTS`, the worker moves the job straight to failed storage without
running the handler.

If a worker loses its reservation while handling a job, it reports `Job reservation lost.`
and continues processing the queue. The current reservation belongs to the worker that reclaimed it.

Jobs scheduled with `withoutOverlapping()` carry the task's lock key to the worker. A busy lock
defers the job without spending an attempt. Restart running workers on the updated version before
enabling overlap protection for scheduled jobs. Workers must share `storage/scheduler` for the lock
to coordinate execution.

```env
QUEUE_RETRY_AFTER=90
QUEUE_RETRY_DELAY=5
QUEUE_MAX_ATTEMPTS=3
QUEUE_SLEEP=1
```

List permanent failures with `php atom queue:failed`. `ArrayQueue` is available for isolated unit
tests where storage state needs to be inspected directly.

### Retry a picking list while it waits for stock

Suppose the picking list for order 42 must wait for a stock reservation. In a disposable Starter app, use the `WritePickingList` job and the `warehouse` file queue from the walkthrough above. Represent the reservation with a local marker file. Start with an empty queue and no `storage/stock-42.ready` file.

Add this check at the beginning of `WritePickingList::handle()`, before it writes the list.

```php
if (!is_file($paths->resolve("@root/storage/stock-{$this->orderId}.ready"))) {
    throw new RuntimeException("Waiting for stock reservation for order {$this->orderId}.");
}
```

Set these values in the demo app's `.env`. A zero delay makes the released job eligible for the very next worker invocation. Two attempts allow one retry after the initial attempt.

```env
QUEUE_RETRY_DELAY=0
QUEUE_MAX_ATTEMPTS=2
```

From the project root, dispatch one order and run the worker once.

```powershell
php dispatch-order.php
php atom queue:once --queue=warehouse
```

The worker prints `Job released for retry.` Create the reservation marker and run the worker again. The queue already has the order, so there is no second dispatch.

```powershell
New-Item -ItemType File -Path storage/stock-42.ready | Out-Null
php atom queue:once --queue=warehouse
Get-Content storage/pick-42.txt
```

The worker prints `Job completed.` and the file lists two notebooks and five pencils. To see what happens when stock stays unavailable, remove the demo marker and dispatch one more order. Run the worker twice and inspect the failed queue.

```powershell
Remove-Item -LiteralPath storage/stock-42.ready
php dispatch-order.php
php atom queue:once --queue=warehouse
php atom queue:once --queue=warehouse
php atom queue:failed --queue=warehouse
```

The second worker invocation prints `Job failed permanently.` and exits with code `1`. Match the ID printed by `dispatch-order.php` to the failure row. That row includes `warehouse.write-picking-list` and the stock reservation error.

File queues are intended for a single application server with persistent local storage. Use the
database driver when workers run on multiple servers or application filesystems are ephemeral.

Jobs can also be dispatched on recurring cron expressions through the [task scheduler](Scheduler.md).
