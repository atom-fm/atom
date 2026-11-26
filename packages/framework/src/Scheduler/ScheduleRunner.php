<?php

declare(strict_types=1);

namespace Atom\Scheduler;

use Atom\Console\BufferedConsoleOutput;
use Atom\Console\ConsoleApplication;
use Atom\Queue\JobDispatcherInterface;
use Atom\Queue\OverlapAwareJobDispatcherInterface;
use Atom\Support\ClockInterface;
use Atom\Support\FileOverlapLocks;
use RuntimeException;
use Throwable;

final readonly class ScheduleRunner
{
    public function __construct(
        private Schedule $schedule,
        private ClockInterface $clock,
        private ConsoleApplication $console,
        private JobDispatcherInterface $jobs,
        private ?ScheduleMinuteClaims $minuteClaims = null,
        private ?FileOverlapLocks $taskLocks = null
    ) {
    }

    public function run(): ScheduleRunResult
    {
        $now = $this->clock->now();
        $results = [];

        foreach ($this->schedule->tasks() as $task) {
            if (!$task->isDue($now)) {
                continue;
            }

            $lock = null;
            try {
                $lockKey = $task->overlapLockKey();
                $lockInWorker = $task instanceof ScheduledJob && $this->jobs instanceof OverlapAwareJobDispatcherInterface;
                if ($lockKey !== null && !$lockInWorker) {
                    if ($this->taskLocks === null) {
                        throw new RuntimeException("No scheduler lock store is configured for '{$lockKey}'.");
                    }
                    $lock = $this->taskLocks->acquire($lockKey);
                    if ($lock === null) {
                        continue;
                    }
                }

                $key = $task->minuteClaimKey();
                if ($key !== null) {
                    if ($this->minuteClaims === null) {
                        throw new RuntimeException("No scheduler claim store is configured for '{$key}'.");
                    }
                    if (!$this->minuteClaims->claim($key, $now)) {
                        continue;
                    }
                }
                $results[] = $this->execute($task);
            } catch (Throwable $exception) {
                $results[] = new ScheduledTaskResult($task, false, $exception->getMessage());
            } finally {
                if ($lock !== null) {
                    fclose($lock);
                }
            }
        }

        return new ScheduleRunResult($results);
    }

    private function execute(ScheduledTask $task): ScheduledTaskResult
    {
        if ($task instanceof ScheduledJob) {
            if ($this->jobs instanceof OverlapAwareJobDispatcherInterface) {
                $this->jobs->dispatch($task->job, $task->delay, $task->queue, $task->overlapLockKey());
            } else {
                $this->jobs->dispatch($task->job, $task->delay, $task->queue);
            }
            return new ScheduledTaskResult($task, true);
        }

        if ($task instanceof ScheduledCommand) {
            $output = new BufferedConsoleOutput();
            $code = $this->console->run(["atom", $task->command, ...$task->arguments], $output);
            $contents = trim($output->output() . $output->errors());
            return new ScheduledTaskResult($task, $code === 0, $contents);
        }

        return new ScheduledTaskResult($task, false, "Unsupported scheduled task.");
    }
}
