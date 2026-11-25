<?php

declare(strict_types=1);

namespace Atom\Queue;

use Throwable;

interface QueueInterface
{
    public function push(JobEnvelope $job): void;

    public function reserve(string $queue, int $retryAfter): ?ReservedJob;

    /** @throws ReservationLostException If the job is no longer owned by this reservation. */
    public function complete(ReservedJob $job): void;

    /** @throws ReservationLostException If the job is no longer owned by this reservation. */
    public function release(ReservedJob $job, int $delay = 0): void;

    /** @throws ReservationLostException If the job is no longer owned by this reservation. */
    public function fail(ReservedJob $job, Throwable $exception): void;

    /** @return FailedJob[] */
    public function failed(string $queue = "default"): array;
}
