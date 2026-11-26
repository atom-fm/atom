<?php

declare(strict_types=1);

namespace Atom\Scheduler\Commands;

use Atom\Console\Attributes\ConsoleCommand;
use Atom\Console\ConsoleOutput;
use Atom\Scheduler\Schedule;
use Atom\Scheduler\ScheduleRunner;
use Atom\Support\ClockInterface;

final readonly class ScheduleCommands
{
    public function __construct(
        private Schedule $schedule,
        private ScheduleRunner $runner,
        private ClockInterface $clock,
        private ConsoleOutput $output
    ) {
    }

    #[ConsoleCommand("schedule:run", "Run scheduled tasks that are due")]
    public function run(): int
    {
        $result = $this->runner->run();
        if ($result->count() === 0) {
            $this->output->line("No scheduled tasks to run.");
            return 0;
        }

        foreach ($result->tasks as $task) {
            $status = $task->successful ? "completed" : "failed";
            $this->output->line($task->task->label() . "  " . $status);
            if ($task->output !== "") {
                $this->output->line("  " . str_replace("\n", "\n  ", $task->output));
            }
        }

        return $result->failed() === 0 ? 0 : 1;
    }

    #[ConsoleCommand("schedule:list", "List configured scheduled tasks")]
    public function list(): int
    {
        $tasks = $this->schedule->tasks();
        if ($tasks === []) {
            $this->output->line("No scheduled tasks configured.");
            return 0;
        }

        $now = $this->clock->now();
        foreach ($tasks as $task) {
            $this->output->line(
                $task->expression() . "  " .
                $task->timezoneName() . "  " .
                $task->label() . "  next: " .
                $task->nextRun($now)->format("Y-m-d H:i:s T")
            );
        }

        return 0;
    }
}
