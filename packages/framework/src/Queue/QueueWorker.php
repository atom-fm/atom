<?php

declare(strict_types=1);

namespace Atom\Queue;

use Atom\Support\FileOverlapLocks;
use Throwable;

final readonly class QueueWorker
{
    public function __construct(
        private QueueInterface $queue,
        private JobExecutor $executor,
        private QueueOptions $options,
        private ?FileOverlapLocks $taskLocks = null
    ) {
    }

    public function runOnce(?string $queue = null): WorkerResult
    {
        $reserved = $this->queue->reserve($queue ?? $this->options->queue, $this->options->retryAfter);
        if ($reserved === null) {
            return WorkerResult::Empty;
        }

        $maxAttempts = max(1, $this->options->maxAttempts);
        $lock = null;
        try {
            if ($reserved->job->attempts > $maxAttempts) {
                $this->queue->fail($reserved, new QueueException("Job exceeded its maximum of {$maxAttempts} attempts after a reservation expired."));
                return WorkerResult::Failed;
            }

            $key = $reserved->job->overlapKey;
            if ($key !== null) {
                if ($this->taskLocks === null || !$this->queue instanceof DeferrableQueueInterface) {
                    throw new QueueException("No compatible queue and overlap lock store are configured for '{$key}'.");
                }
                $lock = $this->taskLocks->acquire($key);
                if ($lock === null) {
                    $this->queue->defer($reserved, max(1, $this->options->retryDelay));
                    return WorkerResult::Deferred;
                }
            }

            try {
                $this->executor->execute($reserved->job);
            } catch (Throwable $exception) {
                if ($reserved->job->attempts >= $maxAttempts) {
                    $this->queue->fail($reserved, $exception);
                    return WorkerResult::Failed;
                }

                $this->queue->release($reserved, max(0, $this->options->retryDelay));
                return WorkerResult::Released;
            }

            $this->queue->complete($reserved);
            return WorkerResult::Completed;
        } catch (ReservationLostException) {
            return WorkerResult::ReservationLost;
        } finally {
            if ($lock !== null) {
                fclose($lock);
            }
        }
    }
}
