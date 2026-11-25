<?php

declare(strict_types=1);

namespace Atom\Queue;

interface DeferrableQueueInterface extends QueueInterface
{
    /** Return a reservation to the queue without charging an attempt. */
    public function defer(ReservedJob $job, int $delay): void;
}
