<?php

declare(strict_types=1);

namespace Atom\Scheduler;

use Atom\Console\ConsoleCommandProviderInterface;
use Atom\Console\ConsoleCommandSources;
use Atom\Di\Bindings;
use Atom\Di\ServiceProviderInterface;
use Atom\Support\ClockServices;
use Atom\Support\OverlapLockServices;

final readonly class ScheduleServices implements ServiceProviderInterface, ConsoleCommandProviderInterface
{
    public function register(Bindings $bindings): void
    {
        (new ClockServices())->register($bindings);
        (new OverlapLockServices())->register($bindings);
        $bindings->bind(Schedule::class)->toSelf()->singleton();
        $bindings->bind(ScheduleMinuteClaims::class)->toSelf()->singleton();
        $bindings->bind(ScheduleRunner::class)->toSelf()->singleton();
    }

    public function consoleCommands(ConsoleCommandSources $commands): void
    {
        $commands->add(__DIR__ . "/Commands", __NAMESPACE__ . "\\Commands");
    }
}
