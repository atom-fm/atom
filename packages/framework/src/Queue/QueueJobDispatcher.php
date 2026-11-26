<?php

declare(strict_types=1);

namespace Atom\Queue;

final readonly class QueueJobDispatcher implements OverlapAwareJobDispatcherInterface
{
    public function __construct(private QueueInterface $queue, private QueueOptions $options)
    {
    }

    public function dispatch(JobInterface $job, int $delay = 0, ?string $queue = null, ?string $overlapKey = null): string
    {
        if ($overlapKey !== null && !$this->queue instanceof DeferrableQueueInterface) {
            throw new QueueException("The queue cannot defer jobs when an overlap lock is held.");
        }
        $envelope = JobEnvelope::for($job, $queue ?? $this->options->queue, $delay, $overlapKey);
        $this->queue->push($envelope);

        return $envelope->id;
    }
}
