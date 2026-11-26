# Task Scheduler

[Atom Framework](Index.md)

Use Atom's scheduler to prune expired cache entries every day at 06:15 in Sarajevo. First run the task locally so you can see what success looks like. Then set its daily time.

## Try the cleanup locally

In a fresh Atom Starter project with Composer dependencies installed, open `app/Application.php` and add this import alongside the others.

```php
use Atom\Scheduler\Schedule;
```

Add the following method inside the `Application` class. If the class already defines `schedule()`, add the command to the existing method. The built-in `cache:prune` command removes expired or corrupt entries in the active file-cache namespace.

```php
protected function schedule(Schedule $schedule): void
{
    $schedule->command("cache:prune")
        ->everyMinute()
        ->name("Prune file cache");
}
```

From the project root, list the tasks and run those due in the current minute.

```shell
php atom schedule:list
php atom schedule:run
```

The list should include `Prune file cache` with the expression `* * * * *` and the default timezone `UTC`. With a fresh cache, look for these lines in the run output.

```text
Prune file cache  completed
  Pruned 0 cache entries.
```

A zero count means the command ran and found nothing to remove. The count increases when the cache has expired entries to prune.

## Switch to daily cleanup

Replace the command inside the same method with this schedule.

```php
$schedule->command("cache:prune")
    ->daily()
    ->at("06:15")
    ->timezone("Europe/Sarajevo")
    ->name("Prune file cache");
```

Run `php atom schedule:list` again. The row should now start with `15 6 * * *`, show `Europe/Sarajevo`, and give the next run time. `schedule:run` executes the cleanup only when the current minute matches. Set up the recurring invocation described under [Running the Scheduler](#running-the-scheduler) to have the host check every minute.

Command arguments are passed as CLI tokens:

```php
$schedule->command("queue:failed", ["--queue=mail"])->hourly();
```

Use `$schedule->job($job)` to schedule a registered job. With the `sync` queue driver it runs during `schedule:run`. The `file` and `database` drivers dispatch it for a queue worker.

### Preview the cleanup time in winter and summer

The cleanup should run at 06:15 in Sarajevo even when the UTC offset changes. To check the registered schedule at a particular instant, save `preview-schedule.php` beside the Starter's `atom` command.

```php
<?php

declare(strict_types=1);

use App\Application;
use Atom\Scheduler\Schedule;

require __DIR__ . "/vendor/autoload.php";

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php preview-schedule.php 2026-01-15T05:15:00Z" . PHP_EOL);
    exit(1);
}

$instant = new DateTimeImmutable($argv[1]);
$app = new Application();
$app->initialize();
$schedule = $app->getInjector()->get(Schedule::class);

foreach ($schedule->tasks() as $task) {
    $local = $instant->setTimezone(new DateTimeZone($task->timezoneName()));
    printf("%s  %s  %s\n", $task->label(), $local->format("H:i P"), $task->isDue($instant) ? "due" : "not due");
}
```

Run these three checks from the project root. The `Z` suffix marks each input as UTC. `isDue()` checks the same condition the scheduler uses before executing a task, so the preview can inspect any minute without running the cleanup.

```powershell
php preview-schedule.php 2026-01-15T05:15:00Z
php preview-schedule.php 2026-07-15T04:14:00Z
php preview-schedule.php 2026-07-15T04:15:00Z
```

Look for the cleanup row in each run. The summer run is due one hour earlier in UTC, and the minute just before it is skipped.

```text
Prune file cache  06:15 +01:00  due
Prune file cache  06:14 +02:00  not due
Prune file cache  06:15 +02:00  due
```

## Hand a scheduled job to a queue worker

A scheduled picking list has two stages. The scheduler puts an order on the warehouse queue, and a worker writes the list. To see where a missing list is waiting, run each stage separately.

In a disposable Starter app, follow [Defining Jobs](Queue.md#defining-jobs) to create and register `WritePickingList` and set `QUEUE_DRIVER=file`. Start with an empty `warehouse` queue and no worker running. In `app/Application.php`, import `Atom\Scheduler\Schedule` and add this task inside `schedule(Schedule $schedule)`.

```php
$schedule->job(new WritePickingList(84, ["NOTEBOOK" => 2]), queue: "warehouse")
    ->everyMinute()
    ->name("Queue picking list for order 84");
```

From the project root, invoke the scheduler once and check for the file in PowerShell.

```powershell
php atom schedule:run
Test-Path storage/pick-84.txt
```

The scheduler prints `Queue picking list for order 84  completed`. Here `completed` means the file queue accepted the job. `Test-Path` returns `False` because the worker has yet to handle it.

```powershell
php atom queue:once --queue=warehouse
Get-Content storage/pick-84.txt
```

The worker prints `Job completed.` and the file contains two lines.

```text
Order 84
2 x NOTEBOOK
```

If the worker reports `No jobs available.`, check that its `--queue` value matches the name in `$schedule->job()`. Each due invocation of `schedule:run` dispatches the example order again unless the task uses `oncePerMinute()`.

## Frequencies

Tasks support explicit cron expressions and common fluent frequencies:

```php
$task->cron("15 6 * * 1-5");
$task->everyMinute();
$task->everyFiveMinutes();
$task->everyTenMinutes();
$task->everyFifteenMinutes();
$task->everyThirtyMinutes();
$task->hourly();
$task->daily();
$task->weekly();
$task->monthly();
$task->weekdays();
$task->weekends();
$task->at("14:30");
$task->timezone("Europe/Sarajevo");
$task->name("Readable diagnostic name");
```

`at()` uses 24-hour `HH:MM` format. Tasks default to UTC unless a timezone is selected explicitly.

## Module Schedules

Modules contribute their own tasks through `ModuleContext`:

```php
public function register(ModuleContext $context): void
{
    $context->schedule(static function (Schedule $schedule): void {
        $schedule->command("accounts:prune-reset-tokens")->daily();
    });
}
```

Application and module tasks appear together in `schedule:list`.

## Running the Scheduler

Show configured tasks, cron expressions, timezones, and next run times:

```powershell
php atom schedule:list
```

Run every task due in the current minute:

```powershell
php atom schedule:run
```

Configure one system cron entry to invoke it each minute:

```cron
* * * * * cd /path/to/application && php atom schedule:run
```

The command continues through all due tasks and returns a failure status when any scheduled task
fails. Command output is included beneath its task in the scheduler diagnostics.

### Claim a task once per due minute

Give a command or job a stable, unique key to deduplicate concurrent and repeated invocations in the same minute.

```php
$schedule->command("cache:prune")
    ->everyMinute()
    ->oncePerMinute("prune-file-cache");
```

Scheduler processes must share the application's `storage/scheduler` directory on a filesystem that supports file locks. Atom records the claim before running the command or dispatching the job. A failed task or a process exit after the claim consumes that minute's run.

### Skip overlapping runs

Use `withoutOverlapping()` with a stable key to hold a lock for the full execution of a scheduled command or a job using the `sync` queue driver.

```php
$schedule->command("cache:prune")
    ->everyMinute()
    ->withoutOverlapping("prune-file-cache");
```

Processes using the same key and shared `storage/scheduler` directory skip the task while the lock is held. Other due tasks can proceed. Combine this with `oncePerMinute()` to deduplicate repeated invocations. If an invocation skips a busy task, it leaves the minute unclaimed so a later invocation in the same minute can still run it.

Scheduler and Queue workers use the same lock files. Each filename is the SHA-256 digest of the overlap key followed by `.lock`.

For the built-in file and database queues, the scheduler passes the key to the worker. Each due occurrence can be queued while an earlier one runs. If the worker finds the key locked, it defers that job for `QUEUE_RETRY_DELAY` seconds with a minimum of one second. Deferral keeps the attempt count unchanged. All workers for the task must share the same lock directory on a filesystem that supports file locks.

A custom dispatcher can implement `Atom\Queue\OverlapAwareJobDispatcherInterface` to receive the overlap key and pass it to its worker. Scheduler holds the lock around the dispatch call for dispatchers that implement only `JobDispatcherInterface`.
