<?php

declare(strict_types=1);

namespace Atom\Support;

use DateTimeImmutable;

interface ClockInterface
{
    public function now(): DateTimeImmutable;
}
