<?php

declare(strict_types=1);

namespace Atom\Tests\Queue;

use Atom\Database\DatabaseConnection;
use Atom\Database\Driver\SqliteDriver;
use Atom\Database\Driver\MySqlDriver;
use Atom\Database\Driver\PostgresDriver;
use Atom\Database\Migration\Migration;
use Atom\Database\Schema\Schema;
use Atom\Console\BufferedConsoleOutput;
use Atom\Console\ConsoleApplication;
use Atom\Console\ConsoleServices;
use Atom\Di\Bindings;
use Atom\Di\Injector;
use Atom\Di\ServiceProviderRegistry;
use Atom\Publish\PublishServices;
use Atom\Queue\ArrayQueue;
use Atom\Queue\DatabaseQueue;
use Atom\Queue\FailedJob;
use Atom\Queue\FileQueue;
use Atom\Queue\JobEnvelope;
use Atom\Queue\Job;
use Atom\Queue\JobExecutor;
use Atom\Queue\JobDispatcherInterface;
use Atom\Queue\JobInterface;
use Atom\Queue\JobRegistry;
use Atom\Queue\QueueJobDispatcher;
use Atom\Queue\QueueInterface;
use Atom\Queue\QueueOptions;
use Atom\Queue\QueueException;
use Atom\Queue\QueueServices;
use Atom\Queue\QueueWorker;
use Atom\Queue\WorkerResult;
use Atom\Scheduler\ClockInterface;
use Atom\Scheduler\Schedule;
use Atom\Scheduler\ScheduleMinuteClaims;
use Atom\Scheduler\ScheduleRunner;
use Atom\Scheduler\ScheduleTaskLocks;
use DateTimeImmutable;
use Fiber;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Atom\Support\Paths;

final class QueueTest extends TestCase
{
    public function testJobConventionRoundTripsConstructorProperties(): void
    {
        $job = new ConventionalJob("hello", attempts: 2, label: null, metadata: ["source" => "test"]);

        $payload = $job->payload();
        $restored = ConventionalJob::fromPayload($payload);

        $this->assertSame([
            "value" => "hello",
            "attempts" => 2,
            "label" => null,
            "metadata" => ["source" => "test"],
        ], $payload);
        $this->assertSame("hello", $restored->value);
        $this->assertSame(2, $restored->attempts);
        $this->assertNull($restored->label);
        $this->assertSame(["source" => "test"], $restored->metadata);
    }

    public function testJobConventionUsesDefaultsForMissingOptionalFields(): void
    {
        $job = ConventionalJob::fromPayload(["value" => "hello"]);

        $this->assertSame(1, $job->attempts);
        $this->assertNull($job->label);
        $this->assertSame([], $job->metadata);
    }

    public function testJobConventionRejectsMissingUnknownAndInvalidFields(): void
    {
        foreach ([
            [[], "missing required payload field 'value'"],
            [["value" => "hello", "extra" => true], "unknown payload field 'extra'"],
            [["value" => 42], "must be string"],
        ] as [$payload, $message]) {
            try {
                ConventionalJob::fromPayload($payload);
                $this->fail("Expected invalid conventional job payload to fail.");
            } catch (QueueException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }
    }

    public function testJobConventionRejectsNonJsonSafeValues(): void
    {
        $this->expectException(QueueException::class);
        $this->expectExceptionMessage("must be JSON-safe");

        (new MixedPayloadJob(new \stdClass()))->payload();
    }

    public function testEnvelopeRoundTripsAsJsonWithoutSerializingObjects(): void
    {
        $envelope = JobEnvelope::for(new RecordingJob("hello"), delay: 10);
        $decoded = JobEnvelope::fromJson($envelope->toJson());

        $this->assertSame($envelope->id, $decoded->id);
        $this->assertSame("test.record", $decoded->type);
        $this->assertSame(["value" => "hello"], $decoded->payload);
        $this->assertGreaterThan(time(), $decoded->availableAt);
    }

    public function testArrayQueueWorkerExecutesRegisteredJobThroughInjector(): void
    {
        $handler = new RecordingJobHandler();
        [$queue, $dispatcher, $worker] = $this->runtime($handler);

        $id = $dispatcher->dispatch(new RecordingJob("hello"));
        $result = $worker->runOnce();

        $this->assertNotSame("", $id);
        $this->assertSame(WorkerResult::Completed, $result);
        $this->assertSame(["hello"], $handler->handled);
        $this->assertSame([], $queue->pending());
    }

    public function testWorkerReleasesThenPermanentlyFailsAJob(): void
    {
        $handler = new RecordingJobHandler(failuresRemaining: 2);
        [$queue, $dispatcher, $worker] = $this->runtime($handler, new QueueOptions(
            retryDelay: 0,
            maxAttempts: 2
        ));
        $dispatcher->dispatch(new RecordingJob("fail"));

        $this->assertSame(WorkerResult::Released, $worker->runOnce());
        $this->assertSame(WorkerResult::Failed, $worker->runOnce());
        $this->assertCount(1, $queue->failed());
        $this->assertSame(2, $queue->failed()[0]->job->attempts);
        $this->assertStringContainsString("Job failed", $queue->failed()[0]->exception);
    }

    public function testFileQueueAllowsOnlyOneWorkerToClaimAJob(): void
    {
        $directory = $this->tempDirectory();
        try {
            $first = new FileQueue($directory);
            $second = new FileQueue($directory);
            $job = JobEnvelope::for(new RecordingJob("file"));
            $first->push($job);

            $reservation = $first->reserve("default", 90);

            $this->assertNotNull($reservation);
            $this->assertNull($second->reserve("default", 90));
            $first->complete($reservation);
            $this->assertNull($first->reserve("default", 90));
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testFileQueueStoresPermanentFailures(): void
    {
        $directory = $this->tempDirectory();
        try {
            $queue = new FileQueue($directory);
            $queue->push(JobEnvelope::for(new RecordingJob("file")));
            $reservation = $queue->reserve("default", 90);
            $this->assertNotNull($reservation);

            $queue->fail($reservation, new RuntimeException("broken"));
            $failures = $queue->failed();

            $this->assertCount(1, $failures);
            $this->assertInstanceOf(FailedJob::class, $failures[0]);
            $this->assertStringContainsString("broken", $failures[0]->exception);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testDatabaseQueueClaimsCompletesAndFailsJobs(): void
    {
        $connection = new DatabaseConnection(SqliteDriver::memory());
        $this->migrateQueue($connection);
        $first = new DatabaseQueue($connection);
        $second = new DatabaseQueue($connection);

        $first->push(JobEnvelope::for(new RecordingJob("database")));
        $reservation = $first->reserve("default", 90);

        $this->assertNotNull($reservation);
        $this->assertNull($second->reserve("default", 90));
        $first->complete($reservation);
        $this->assertSame(0, (int) $connection->scalar("SELECT COUNT(*) FROM atom_jobs"));

        $first->push(JobEnvelope::for(new RecordingJob("failure")));
        $failedReservation = $first->reserve("default", 90);
        $this->assertNotNull($failedReservation);
        $first->fail($failedReservation, new RuntimeException("database broken"));

        $failures = $first->failed();
        $this->assertCount(1, $failures);
        $this->assertSame("test.record", $failures[0]->job->type);
        $this->assertStringContainsString("database broken", $failures[0]->exception);
        $this->assertSame(0, (int) $connection->scalar("SELECT COUNT(*) FROM atom_jobs"));
    }

    #[TestWith([false, 60], "another worker claims and releases with a delay")]
    #[TestWith([false, 0], "another worker claims and releases immediately")]
    #[TestWith([true, 60], "an expired owner releases with a delay without changing attempts")]
    public function testDatabaseQueueRechecksReleasedJobBeforeClaim(bool $alreadyReserved, int $delay): void
    {
        $path = "file:queue_" . bin2hex(random_bytes(8)) . "?mode=memory&cache=shared";
        $firstConnection = new DatabaseConnection(new SqliteDriver($path));
        $secondConnection = new DatabaseConnection(new SqliteDriver($path));
        $this->migrateQueue($firstConnection);
        $first = new DatabaseQueue($firstConnection);
        $second = new DatabaseQueue($secondConnection);
        $job = JobEnvelope::for(new RecordingJob("retry"));
        $first->push($job);

        $owner = null;
        if ($alreadyReserved) {
            $owner = $second->reserve("default", 90);
            $secondConnection->execute("UPDATE atom_jobs SET reserved_at = :expired WHERE id = :id", [
                "expired" => time() - 91, "id" => $job->id,
            ]);
        }

        // Pause after the candidate SELECT has released its cursor, before the claim UPDATE.
        $pdo = $firstConnection->pdo();
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PauseAfterQueueRead::class]);
        $claim = new Fiber(fn() => $first->reserve("default", 90));
        $claim->start();
        $this->assertTrue($claim->isSuspended());
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);

        $owner ??= $second->reserve("default", 90);
        $this->assertNotNull($owner);
        $this->assertSame(1, $owner->job->attempts);
        $releaseTime = time();
        $second->release($owner, $delay);
        $claim->resume();
        $reservation = $claim->getReturn();

        if ($delay > 0) {
            $this->assertNull($reservation);
            $row = $secondConnection->first("SELECT available_at, attempts, reservation_id FROM atom_jobs WHERE id = :id", ["id" => $job->id]);
            $this->assertGreaterThanOrEqual($releaseTime + $delay, (int) $row["available_at"]);
            $this->assertSame(1, (int) $row["attempts"]);
            $this->assertNull($row["reservation_id"]);

            $secondConnection->execute("UPDATE atom_jobs SET available_at = :now WHERE id = :id", ["now" => time(), "id" => $job->id]);
            $reservation = $first->reserve("default", 90);
        }

        $this->assertNotNull($reservation);
        $this->assertSame($job->id, $reservation->job->id);
        $this->assertSame(2, $reservation->job->attempts);
        $this->assertSame(2, (int) $secondConnection->scalar("SELECT attempts FROM atom_jobs WHERE id = :id", ["id" => $job->id]));
    }

    #[TestWith([1, WorkerResult::Completed], "final allowed attempt runs")]
    #[TestWith([2, WorkerResult::Failed], "attempt limit exceeded before execution")]
    public function testDatabaseQueueWorkerEnforcesAttemptLimitAfterAbandonedReservations(
        int $abandonedAttempts,
        WorkerResult $expected
    ): void {
        $connection = new DatabaseConnection(SqliteDriver::memory());
        $this->migrateQueue($connection);
        $queue = new DatabaseQueue($connection);
        $job = JobEnvelope::for(new RecordingJob("reclaimed"));
        $queue->push($job);

        for ($attempt = 1; $attempt <= $abandonedAttempts; $attempt++) {
            $reservation = $queue->reserve("default", 90);
            $this->assertNotNull($reservation);
            $this->assertSame($attempt, $reservation->job->attempts);
            $connection->execute("UPDATE atom_jobs SET reserved_at = :expired WHERE id = :id", [
                "expired" => time() - 91,
                "id" => $job->id,
            ]);
        }

        $handler = new RecordingJobHandler();
        $registry = (new JobRegistry())->register(RecordingJob::class);
        $bindings = Bindings::create()->value(RecordingJobHandler::class, $handler);
        $worker = new QueueWorker($queue, new JobExecutor($registry, Injector::create($bindings)), new QueueOptions(maxAttempts: 2));

        $this->assertSame($expected, $worker->runOnce());
        $this->assertSame(WorkerResult::Empty, $worker->runOnce());
        $failures = $queue->failed();
        if ($expected === WorkerResult::Completed) {
            $this->assertSame(["reclaimed"], $handler->handled);
            $this->assertSame([], $failures);
        } else {
            $this->assertSame([], $handler->handled);
            $this->assertCount(1, $failures);
            $this->assertSame($job->id, $failures[0]->job->id);
            $this->assertSame(3, $failures[0]->job->attempts);
            $this->assertStringContainsString("exceeded its maximum of 2 attempts", $failures[0]->exception);
        }
        $this->assertSame(0, (int) $connection->scalar("SELECT COUNT(*) FROM atom_jobs"));
    }

    #[TestWith([false, 3], "stale completion")]
    #[TestWith([true, 3], "stale release after handler failure")]
    #[TestWith([true, 1], "stale permanent failure")]
    public function testDatabaseQueueWorkerContinuesAfterLosingReservation(bool $handlerFails, int $maxAttempts): void
    {
        $path = "file:queue_" . bin2hex(random_bytes(8)) . "?mode=memory&cache=shared";
        $connection = new DatabaseConnection(new SqliteDriver($path));
        $otherConnection = new DatabaseConnection(new SqliteDriver($path));
        $this->migrateQueue($connection);
        $queue = new DatabaseQueue($connection);
        $other = new DatabaseQueue($otherConnection);
        $job = JobEnvelope::for(new PausingJob());
        $queue->push($job);
        $registry = (new JobRegistry())->register(PausingJob::class)->register(RecordingJob::class);
        $handler = new RecordingJobHandler();
        $bindings = Bindings::create()->value(RecordingJobHandler::class, $handler);
        $worker = new QueueWorker($queue, new JobExecutor($registry, Injector::create($bindings)), new QueueOptions(maxAttempts: $maxAttempts));

        $running = new Fiber(fn() => $worker->runOnce());
        $running->start();
        $this->assertTrue($running->isSuspended());
        $otherConnection->execute("UPDATE atom_jobs SET reserved_at = :expired WHERE id = :id", [
            "expired" => time() - 91,
            "id" => $job->id,
        ]);
        $current = $other->reserve("default", 90);
        $this->assertNotNull($current);
        $this->assertSame($job->id, $current->job->id);
        $this->assertSame(2, $current->job->attempts);

        $running->resume($handlerFails);
        $this->assertSame(WorkerResult::ReservationLost, $running->getReturn());
        $this->assertSame([], $queue->failed());
        $queue->push(JobEnvelope::for(new RecordingJob("next")));
        $this->assertSame(WorkerResult::Completed, $worker->runOnce());
        $this->assertSame(["next"], $handler->handled);
        $other->complete($current);
        $this->assertSame(WorkerResult::Empty, $worker->runOnce());
    }

    public function testScheduledDatabaseJobsDeferWithoutAnAttemptWhileAnotherWorkerHandlesTheTask(): void
    {
        $root = $this->tempDirectory();
        $uri = "file:queue_" . bin2hex(random_bytes(8)) . "?mode=memory&cache=shared";
        $connection = new DatabaseConnection(new SqliteDriver($uri));
        $otherConnection = new DatabaseConnection(new SqliteDriver($uri));
        $this->migrateQueue($connection);
        $queue = new DatabaseQueue($connection);
        $other = new DatabaseQueue($otherConnection);
        $options = new QueueOptions(driver: "database", maxAttempts: 1, retryDelay: 60);
        $bindings = Bindings::create()
            ->value(Paths::class, new Paths($root))
            ->value(QueueOptions::class, $options);
        (new QueueServices())->register($bindings);
        $bindings->value(QueueInterface::class, $queue);
        $injector = Injector::create($bindings);
        $injector->get(JobRegistry::class)->register(PausingJob::class);
        $firstWorker = $injector->get(QueueWorker::class);
        $secondWorker = new QueueWorker($other, $injector->get(JobExecutor::class), $options, new ScheduleTaskLocks(new Paths($root)));

        $clock = new class implements ClockInterface {
            public DateTimeImmutable $time;

            public function now(): DateTimeImmutable
            {
                return $this->time;
            }
        };
        $clock->time = new DateTimeImmutable("2026-11-23T09:00:00Z");
        $schedule = new Schedule();
        $schedule->job(new PausingJob())->oncePerMinute("poll")->withoutOverlapping("poll");
        $runner = new ScheduleRunner(
            $schedule,
            $clock,
            new ConsoleApplication($injector),
            $injector->get(JobDispatcherInterface::class),
            new ScheduleMinuteClaims(new Paths($root)),
            new ScheduleTaskLocks(new Paths($root))
        );

        try {
            $this->assertSame(1, $runner->run()->count());
            $first = new Fiber($firstWorker->runOnce(...));
            $first->start();
            $this->assertTrue($first->isSuspended());

            $clock->time = $clock->time->modify("+1 minute");
            $this->assertSame(1, $runner->run()->count());
            $beforeDeferral = time();
            $this->assertSame(WorkerResult::Deferred, $secondWorker->runOnce());
            $this->assertSame(WorkerResult::Empty, $secondWorker->runOnce());
            $deferred = $otherConnection->first("SELECT id, attempts, available_at FROM atom_jobs WHERE reserved_at IS NULL");
            $this->assertNotNull($deferred);
            $this->assertSame(0, (int) $deferred["attempts"]);
            $this->assertGreaterThanOrEqual($beforeDeferral + 60, (int) $deferred["available_at"]);

            $first->resume(false);
            $this->assertSame(WorkerResult::Completed, $first->getReturn());

            $otherConnection->execute("UPDATE atom_jobs SET available_at = :now WHERE id = :id", [
                "now" => time(),
                "id" => $deferred["id"],
            ]);
            $second = new Fiber($secondWorker->runOnce(...));
            $second->start();
            $this->assertTrue($second->isSuspended());
            $this->assertSame(1, (int) $otherConnection->scalar("SELECT attempts FROM atom_jobs WHERE id = :id", ["id" => $deferred["id"]]));
            $second->resume(false);
            $this->assertSame(WorkerResult::Completed, $second->getReturn());
            $this->assertSame([], $queue->failed());
            $this->assertSame(WorkerResult::Empty, $secondWorker->runOnce());
        } finally {
            unset($first, $second);
            $this->removeDirectory($root);
        }
    }

    public function testFileQueueDeferralPreservesTheOverlapKeyAndRefundsTheAttempt(): void
    {
        $root = $this->tempDirectory();
        try {
            $queue = new FileQueue($root);
            $queue->push(JobEnvelope::for(new RecordingJob("locked"), overlapKey: "poll"));
            $first = $queue->reserve("default", 90);
            $this->assertNotNull($first);
            $queue->defer($first, 0);

            $second = (new FileQueue($root))->reserve("default", 90);
            $this->assertNotNull($second);
            $this->assertSame("poll", $second->job->overlapKey);
            $this->assertSame(1, $second->job->attempts);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testPublishedMigrationCompilesForEverySupportedDatabase(): void
    {
        $migration = require __DIR__ . "/../../src/Queue/Resources/M0001_create_atom_queue_tables.php";
        $this->assertInstanceOf(Migration::class, $migration);

        foreach ([
            new SqliteDriver(":memory:"),
            new MySqlDriver("atom"),
            new PostgresDriver("atom"),
        ] as $driver) {
            $schema = new Schema();
            $migration->up($schema);
            $sql = implode("\n", $driver->schemaCompiler()->compile($schema)->sql());

            $this->assertStringContainsString("atom_jobs", $sql);
            $this->assertStringContainsString("atom_failed_jobs", $sql);
        }
    }

    public function testDelayedJobsAreNotReservedEarly(): void
    {
        $queue = new ArrayQueue();
        $queue->push(JobEnvelope::for(new RecordingJob("later"), delay: 60));

        $this->assertNull($queue->reserve("default", 90));
    }

    public function testQueueServicesExposeCommandsAndPublishDatabaseMigration(): void
    {
        $root = $this->tempDirectory();
        mkdir($root);
        $providers = ServiceProviderRegistry::create()
            ->add(ConsoleServices::class)
            ->add(PublishServices::class)
            ->add(QueueServices::class);
        $paths = (new Paths($root))
            ->alias("migrations", $root . "/app/Database/Migrations");
        $bindings = $providers->bindings()
            ->value(ServiceProviderRegistry::class, $providers)
            ->value(Paths::class, $paths)
            ->value(QueueOptions::class, new QueueOptions());
        $console = Injector::create($bindings)->get(ConsoleApplication::class);

        try {
            $this->assertTrue($console->commands()->has("queue:once"));
            $this->assertTrue($console->commands()->has("queue:work"));
            $this->assertTrue($console->commands()->has("queue:failed"));
            $this->assertTrue($console->commands()->has("queue:publish"));

            $output = new BufferedConsoleOutput();
            $code = $console->run(["atom", "queue:publish"], $output);

            $this->assertSame(0, $code);
            $this->assertFileExists($root . "/app/Database/Migrations/M0001_create_atom_queue_tables.php");
            $this->assertStringContainsString("Published:", $output->output());
        } finally {
            $this->removeDirectory($root);
        }
    }

    /** @return array{ArrayQueue, QueueJobDispatcher, QueueWorker} */
    private function runtime(
        RecordingJobHandler $handler,
        ?QueueOptions $options = null
    ): array {
        $options ??= new QueueOptions(retryDelay: 0);
        $registry = (new JobRegistry())->register(RecordingJob::class);
        $bindings = Bindings::create()->value(RecordingJobHandler::class, $handler);
        $executor = new JobExecutor($registry, Injector::create($bindings));
        $queue = new ArrayQueue();

        return [
            $queue,
            new QueueJobDispatcher($queue, $options),
            new QueueWorker($queue, $executor, $options),
        ];
    }

    private function migrateQueue(DatabaseConnection $connection): void
    {
        $migration = require __DIR__ . "/../../src/Queue/Resources/M0001_create_atom_queue_tables.php";
        $this->assertInstanceOf(Migration::class, $migration);
        $schema = new Schema($connection->driver()->schemaInspector($connection));
        $migration->up($schema);
        $plan = $connection->driver()->schemaCompiler()->compile($schema);
        foreach ($plan->commands() as $command) {
            $connection->execute($command);
        }
    }

    private function tempDirectory(): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . "atom_queue_" . bin2hex(random_bytes(6));
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }
}

final class PauseAfterQueueRead extends PDOStatement
{
    public function closeCursor(): bool
    {
        $closed = parent::closeCursor();
        Fiber::suspend();
        return $closed;
    }
}

final readonly class PausingJob extends Job
{
    public static function type(): string
    {
        return "test.pause";
    }

    public function handle(): void
    {
        if (Fiber::suspend()) {
            throw new RuntimeException("Handler failed after losing the reservation.");
        }
    }
}

final readonly class RecordingJob implements JobInterface
{
    public function __construct(private string $value)
    {
    }

    public static function type(): string
    {
        return "test.record";
    }

    public function payload(): array
    {
        return ["value" => $this->value];
    }

    public static function fromPayload(array $payload): self
    {
        return new self((string) ($payload["value"] ?? ""));
    }

    public function handle(RecordingJobHandler $handler): void
    {
        $handler->handle($this->value);
    }
}

final readonly class ConventionalJob extends Job
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $value,
        public int $attempts = 1,
        public ?string $label = null,
        public array $metadata = []
    ) {
    }

    public static function type(): string
    {
        return "test.conventional";
    }
}

final readonly class MixedPayloadJob extends Job
{
    public function __construct(public mixed $value)
    {
    }

    public static function type(): string
    {
        return "test.mixed-payload";
    }
}

final class RecordingJobHandler
{
    /** @var string[] */
    public array $handled = [];

    public function __construct(public int $failuresRemaining = 0)
    {
    }

    public function handle(string $value): void
    {
        if ($this->failuresRemaining > 0) {
            $this->failuresRemaining--;
            throw new RuntimeException("Job failed");
        }
        $this->handled[] = $value;
    }
}
