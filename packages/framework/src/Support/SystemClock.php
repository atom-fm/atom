<?php

declare(strict_types=1);

namespace Atom\Support;

use DateTimeImmutable;

final readonly class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
