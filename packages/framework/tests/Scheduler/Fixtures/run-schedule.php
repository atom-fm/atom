<?php

declare(strict_types=1);

use Atom\Console\CommandInterface;
use Atom\Console\ConsoleApplication;
use Atom\Console\ConsoleInjector;
use Atom\Console\ConsoleInput;
use Atom\Console\ConsoleOutput;
use Atom\Di\Bindings;
use Atom\Di\Injector;
use Atom\Queue\Job;
use Atom\Queue\JobDispatcherInterface;
use Atom\Queue\JobInterface;
use Atom\Queue\JobRegistry;
use Atom\Queue\QueueServices;
use Atom\Scheduler\Schedule;
use Atom\Scheduler\ScheduleServices;
use Atom\Support\ClockInterface;
use Atom\Support\Paths;

require dirname(__DIR__, 3) . "/vendor/autoload.php";

final readonly class SchedulerOverlapJob extends Job
{
    public static function type(): string
    {
        return "test.scheduler-overlap";
    }

    public function handle(SchedulerProbeExecution $execution): void
    {
        $execution->record("jobs");
    }
}

[, $root, $process, $instant] = $argv;
$mode = $argv[4] ?? "minute";
$schedule = new Schedule();
if ($mode === "job") {
    $schedule->job(new SchedulerOverlapJob())->withoutOverlapping("job");
} elseif ($mode === "command") {
    $schedule->command("test:record")->withoutOverlapping("command")->oncePerMinute("command");
} else {
    $schedule->job(new readonly class extends Job {
        public static function type(): string
        {
            return "test.scheduler";
        }
    })->oncePerMinute("job");
    $schedule->command("test:record")->oncePerMinute("command");
}

$clock = new class(new DateTimeImmutable($instant)) implements ClockInterface {
    public function __construct(private DateTimeImmutable $instant) {}

    public function now(): DateTimeImmutable
    {
        return $this->instant;
    }
};
$jobs = new class($root) implements JobDispatcherInterface {
    public function __construct(private string $root) {}

    public function dispatch(JobInterface $job, int $delay = 0, ?string $queue = null): string
    {
        file_put_contents($this->root . "/jobs", "dispatched\n", FILE_APPEND | LOCK_EX);
        return "test";
    }
};
$bindings = Bindings::create()->value(ClockInterface::class, $clock);
(new ScheduleServices())->register($bindings);
$bindings->value(Schedule::class, $schedule)
    ->value(JobDispatcherInterface::class, $jobs)
    ->value(Paths::class, new Paths($root));
$execution = new SchedulerProbeExecution($root, $process);
if ($mode === "job") {
    (new QueueServices())->register($bindings);
    $bindings->value(JobRegistry::class, (new JobRegistry())->register(SchedulerOverlapJob::class))
        ->value(SchedulerProbeExecution::class, $execution);
}
$bindings->bind(ConsoleApplication::class)->toFactory(
    static fn(Injector $injector): ConsoleApplication => new ConsoleApplication((new ConsoleInjector($injector))(...))
)->singleton();
$console = Injector::create($bindings)->get(ConsoleApplication::class);
$console->attach(Atom\Scheduler\Commands\ScheduleCommands::class)
    ->command("test:record", new class($execution) implements CommandInterface {
        public function __construct(private SchedulerProbeExecution $execution) {}

        public function handle(ConsoleInput $input, ConsoleOutput $output): int
        {
            $this->execution->record("commands");
            return 0;
        }
    });
final readonly class SchedulerProbeExecution
{
    public function __construct(private string $root, private string $process) {}

    public function record(string $file): void
    {
        file_put_contents($this->root . "/" . $file, $this->process . "\n", FILE_APPEND | LOCK_EX);
        if ($this->process === "first") {
            $deadline = microtime(true) + 15;
            while (!is_file($this->root . "/continue")) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException("Timed out waiting for the concurrent scheduler.");
                }
                usleep(10000);
            }
        }
    }
}

exit($console->run(["atom", "schedule:run"]));
