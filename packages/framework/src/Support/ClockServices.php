<?php

declare(strict_types=1);

namespace Atom\Support;

use Atom\Di\Bindings;
use Atom\Di\ServiceProviderInterface;

final readonly class ClockServices implements ServiceProviderInterface
{
    public function register(Bindings $bindings): void
    {
        if (!$bindings->has(ClockInterface::class)) {
            $bindings->bind(ClockInterface::class)->to(SystemClock::class)->singleton();
        }
    }
}
