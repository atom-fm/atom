<?php

declare(strict_types=1);

namespace Atom\Queue;

interface OverlapAwareJobDispatcherInterface extends JobDispatcherInterface
{
    /** A non-null overlap key must be carried to the worker for locking during execution. */
    public function dispatch(JobInterface $job, int $delay = 0, ?string $queue = null, ?string $overlapKey = null): string;
}
