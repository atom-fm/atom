<?php

declare(strict_types=1);

namespace Atom\Tests\Scheduler;

use Atom\Application;
use Atom\Console\BufferedConsoleOutput;
use Atom\Console\CommandInterface;
use Atom\Console\ConsoleApplication;
use Atom\Console\ConsoleInput;
use Atom\Console\ConsoleOutput;
use Atom\Di\Injector;
use Atom\Queue\JobDispatcherInterface;
use Atom\Queue\JobInterface;
use Atom\Queue\JobRegistry;
use Atom\Scheduler\ClockInterface;
use Atom\Scheduler\Commands\ScheduleCommands;
use Atom\Scheduler\Schedule;
use Atom\Scheduler\ScheduleRunner;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class SchedulerTest extends TestCase
{
    protected function tearDown(): void
    {
        Application::$app = null;
    }

    public function testFluentFrequenciesBuildCronExpressions(): void
    {
        $schedule = new Schedule();

        $this->assertSame("*/5 * * * *", $schedule->command("one")->everyFiveMinutes()->expression());
        $this->assertSame("0 * * * *", $schedule->command("two")->hourly()->expression());
        $this->assertSame("30 8 * * 1-5", $schedule->command("three")->weekdays()->at("08:30")->expression());
        $this->assertSame("0 0 1 * *", $schedule->command("four")->monthly()->expression());
    }

    public function testInvalidScheduledTimeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Schedule())->command("test")->at("25:00");
    }

    public function testRunnerExecutesDueCommandsAndJobsButSkipsOtherTasks(): void
    {
        $schedule = new Schedule();
        $schedule->command("test:record", ["hello"])->weekdays()->at("08:00")->timezone("Europe/Sarajevo");
        $schedule->job(new ScheduledFixtureJob("mail"))->weekdays()->at("08:00")->timezone("Europe/Sarajevo");
        $schedule->command("test:record", ["later"])->daily()->at("09:00")->timezone("Europe/Sarajevo");

        $command = new RecordingCommand();
        $console = (new ConsoleApplication(Injector::create()))->command("test:record", $command);
        $jobs = new RecordingJobDispatcher();
        $clock = new FrozenClock(new DateTimeImmutable("2026-07-20 06:00:00 UTC"));
        $result = (new ScheduleRunner($schedule, $clock, $console, $jobs))->run();

        $this->assertSame(2, $result->count());
        $this->assertSame(0, $result->failed());
        $this->assertSame([["hello"]], $command->arguments);
        $this->assertCount(1, $jobs->jobs);
        $this->assertSame("mail", $jobs->jobs[0]->payload()["value"]);
    }

    public function testRunnerContinuesAfterACommandFailure(): void
    {
        $schedule = new Schedule();
        $schedule->command("test:fail");
        $schedule->command("test:record");
        $recording = new RecordingCommand();
        $console = (new ConsoleApplication(Injector::create()))
            ->command("test:fail", new FailingCommand())
            ->command("test:record", $recording);
        $runner = new ScheduleRunner(
            $schedule,
            new FrozenClock(new DateTimeImmutable("2026-07-20 12:00:00 UTC")),
            $console,
            new RecordingJobDispatcher()
        );

        $result = $runner->run();

        $this->assertSame(2, $result->count());
        $this->assertSame(1, $result->failed());
        $this->assertCount(1, $recording->arguments);
    }

    public function testScheduleCommandsRenderRunAndListDiagnostics(): void
    {
        $schedule = new Schedule();
        $schedule->command("test:record")->daily()->at("08:00")->name("Daily record");
        $clock = new FrozenClock(new DateTimeImmutable("2026-07-20 08:00:00 UTC"));
        $console = (new ConsoleApplication(Injector::create()))
            ->command("test:record", new RecordingCommand());
        $runner = new ScheduleRunner($schedule, $clock, $console, new RecordingJobDispatcher());

        $listOutput = new BufferedConsoleOutput();
        $this->assertSame(0, (new ScheduleCommands($schedule, $runner, $clock, $listOutput))->list());
        $this->assertStringContainsString("0 8 * * *  UTC  Daily record", $listOutput->output());
        $this->assertStringContainsString("next:", $listOutput->output());

        $runOutput = new BufferedConsoleOutput();
        $this->assertSame(0, (new ScheduleCommands($schedule, $runner, $clock, $runOutput))->run());
        $this->assertStringContainsString("Daily record  completed", $runOutput->output());
    }

    public function testProcessesClaimEachOptedInTaskOncePerMinute(): void
    {
        $root = sys_get_temp_dir() . "/atom-scheduler-" . bin2hex(random_bytes(8));
        mkdir($root);
        $minute = "2026-11-23T09:00:00Z";
        $first = $this->startSchedule($root, "first", $minute);
        try {
            $deadline = microtime(true) + 8;
            while (!is_file($root . "/commands") && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($root . "/commands", file_get_contents($root . "/first.log"));
            $second = $this->startSchedule($root, "second", "2026-11-23T10:00:30+01:00");
            $this->assertSame(0, proc_close($second), file_get_contents($root . "/second.log"));
            $this->assertSame("first\n", file_get_contents($root . "/commands"));
            $this->assertSame("dispatched\n", file_get_contents($root . "/jobs"));

            touch($root . "/continue");
            $this->assertSame(0, proc_close($first), file_get_contents($root . "/first.log"));
            $repeat = $this->startSchedule($root, "repeat", $minute);
            $this->assertSame(0, proc_close($repeat), file_get_contents($root . "/repeat.log"));
            $this->assertSame("first\n", file_get_contents($root . "/commands"));
            $this->assertSame("dispatched\n", file_get_contents($root . "/jobs"));

            $next = $this->startSchedule($root, "next", "2026-11-23T09:01:00Z");
            $this->assertSame(0, proc_close($next), file_get_contents($root . "/next.log"));
            $this->assertSame("first\nnext\n", file_get_contents($root . "/commands"));
            $this->assertSame("dispatched\ndispatched\n", file_get_contents($root . "/jobs"));
        } finally {
            if (is_resource($first)) {
                touch($root . "/continue");
                proc_close($first);
            }
            $this->removeScheduleFiles($root);
        }
    }

    #[TestWith(["command", "commands"])]
    #[TestWith(["job", "jobs"])]
    public function testProcessesSkipAnOverlappingTaskInTheNextDueMinute(string $mode, string $record): void
    {
        $root = sys_get_temp_dir() . "/atom-scheduler-" . bin2hex(random_bytes(8));
        mkdir($root);
        $first = $this->startSchedule($root, "first", "2026-11-23T09:00:00Z", $mode);
        try {
            $deadline = microtime(true) + 8;
            while (!is_file($root . "/" . $record) && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($root . "/" . $record, file_get_contents($root . "/first.log"));
            $second = $this->startSchedule($root, "second", "2026-11-23T09:01:00Z", $mode);
            $this->assertSame(0, proc_close($second), file_get_contents($root . "/second.log"));
            $this->assertStringContainsString("No scheduled tasks to run.", file_get_contents($root . "/second.log"));
            $this->assertSame("first\n", file_get_contents($root . "/" . $record));

            touch($root . "/continue");
            $this->assertSame(0, proc_close($first), file_get_contents($root . "/first.log"));
            $next = $this->startSchedule($root, "next", "2026-11-23T09:01:30Z", $mode);
            $this->assertSame(0, proc_close($next), file_get_contents($root . "/next.log"));
            $this->assertSame("first\nnext\n", file_get_contents($root . "/" . $record));
        } finally {
            if (is_resource($first)) {
                touch($root . "/continue");
                proc_close($first);
            }
            $this->removeScheduleFiles($root);
        }
    }

    private function removeScheduleFiles(string $root): void
    {
        foreach (glob($root . "/storage/scheduler/*") ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($root . "/storage/scheduler")) {
            rmdir($root . "/storage/scheduler");
            rmdir($root . "/storage");
        }
        foreach (glob($root . "/*") ?: [] as $file) {
            unlink($file);
        }
        rmdir($root);
    }

    /** @return resource */
    private function startSchedule(string $root, string $process, string $instant, string $mode = "minute")
    {
        $log = $root . "/" . $process . ".log";
        $child = proc_open(
            [PHP_BINARY, __DIR__ . "/Fixtures/run-schedule.php", $root, $process, $instant, $mode],
            [0 => ["pipe", "r"], 1 => ["file", $log, "w"], 2 => ["redirect", 1]],
            $pipes
        );
        $this->assertIsResource($child);
        fclose($pipes[0]);
        return $child;
    }

    public function testApplicationHookRegistersScheduleAndConsoleCommands(): void
    {
        ScheduledFixtureJob::$handled = false;
        $app = new SchedulerApplication();
        $app->initialize();

        $this->assertTrue($app->getConsole()->commands()->has("schedule:run"));
        $this->assertTrue($app->getConsole()->commands()->has("schedule:list"));

        $list = new BufferedConsoleOutput();
        $this->assertSame(0, $app->getConsole()->run(["atom", "schedule:list"], $list));
        $this->assertStringContainsString("Scheduled fixture", $list->output());

        $run = new BufferedConsoleOutput();
        $this->assertSame(0, $app->getConsole()->run(["atom", "schedule:run"], $run));
        $this->assertTrue(ScheduledFixtureJob::$handled);
    }
}

final readonly class FrozenClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $time)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }
}

final class RecordingCommand implements CommandInterface
{
    /** @var array<int, string[]> */
    public array $arguments = [];

    public function handle(ConsoleInput $input, ConsoleOutput $output): int
    {
        $this->arguments[] = $input->arguments();
        $output->line("recorded");
        return 0;
    }
}

final readonly class FailingCommand implements CommandInterface
{
    public function handle(ConsoleInput $input, ConsoleOutput $output): int
    {
        $output->errorLine("failed");
        return 1;
    }
}

final class ScheduledFixtureJob implements JobInterface
{
    public static bool $handled = false;

    public function __construct(private string $value)
    {
    }

    public static function type(): string
    {
        return "schedule.fixture";
    }

    public function payload(): array
    {
        return ["value" => $this->value];
    }

    public static function fromPayload(array $payload): self
    {
        return new self((string) ($payload["value"] ?? ""));
    }

    public function handle(): void
    {
        self::$handled = true;
    }
}

final class SchedulerApplication extends Application
{
    protected function jobs(JobRegistry $jobs): void
    {
        $jobs->register(ScheduledFixtureJob::class);
    }

    protected function schedule(Schedule $schedule): void
    {
        $schedule->job(new ScheduledFixtureJob("application"))
            ->everyMinute()
            ->name("Scheduled fixture");
    }
}

final class RecordingJobDispatcher implements JobDispatcherInterface
{
    /** @var JobInterface[] */
    public array $jobs = [];

    public function dispatch(JobInterface $job, int $delay = 0, ?string $queue = null): string
    {
        $this->jobs[] = $job;
        return "scheduled-job";
    }
}
