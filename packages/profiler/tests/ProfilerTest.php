<?php

declare(strict_types=1);

namespace Atom\Tests\Profiler;

use Atom\Profiler\Profile;
use Atom\Profiler\Profiler;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProfilerTest extends TestCase
{
    protected function tearDown(): void
    {
        Profile::resolveUsing(null);
        Profile::reset();
    }

    public function testBeginEndRecordsSpan(): void
    {
        $profiler = new Profiler();

        $span = $profiler->begin("test.span", ["id" => 1]);
        $span->end();
        $span->end();

        $this->assertCount(1, $profiler->spans());
        $this->assertSame("test.span", $profiler->spans()[0]->name);
        $this->assertSame(["id" => 1], $profiler->spans()[0]->metadata);
        $this->assertGreaterThanOrEqual(0.0, $profiler->spans()[0]->durationMs());
    }

    public function testMeasureReturnsCallbackResultAndRecordsSpan(): void
    {
        $profiler = new Profiler();

        $result = $profiler->measure("work", static fn(): string => "done");

        $this->assertSame("done", $result);
        $this->assertCount(1, $profiler->spans());
        $this->assertSame("work", $profiler->spans()[0]->name);
    }

    public function testRecordIgnoresUnfinishedSpans(): void
    {
        $profiler = new Profiler();
        $span = $profiler->begin("work");
        $profiler->record($span);

        self::assertSame([], $profiler->spans());
        $span->end();
        self::assertSame([$span], $profiler->spans());
    }

    public function testRecordCountsEachCompletedSpanOnce(): void
    {
        $profiler = new Profiler();
        $first = $profiler->begin("work")->end();
        $second = $profiler->begin("work")->end();
        $profiler->record($first);

        self::assertSame([$first, $second], $profiler->spans());
        self::assertSame(2, $profiler->summary()["work"]->count);
    }

    public function testRecordIgnoresSpansOwnedByAnotherProfiler(): void
    {
        $owner = new Profiler();
        $other = new Profiler();
        $span = $owner->begin("work")->end();
        $other->record($span);

        self::assertSame([], $other->spans());
        self::assertSame([$span], $owner->spans());
    }

    public function testMeasureRecordsACompletedMonotonicSpanWhenCallbackThrows(): void
    {
        $profiler = new Profiler();
        $error = new RuntimeException("Work failed.");
        $before = hrtime(true) / 1_000_000_000;

        try {
            $profiler->measure("failed.work", static fn(): never => throw $error);
            self::fail("Expected the callback exception to propagate.");
        } catch (RuntimeException $exception) {
            self::assertSame($error, $exception);
        }

        $after = hrtime(true) / 1_000_000_000;
        self::assertCount(1, $profiler->spans());
        $span = $profiler->spans()[0];
        self::assertSame("failed.work", $span->name);
        self::assertGreaterThanOrEqual($before, $span->startedAt);
        self::assertNotNull($span->endedAt());
        self::assertLessThanOrEqual($after, $span->endedAt());
        self::assertGreaterThanOrEqual(0.0, $span->durationMs());
        self::assertGreaterThanOrEqual($span->durationMs(), $profiler->totalMs());
    }

    public function testSummarizesRepeatedSpans(): void
    {
        $profiler = new Profiler();

        $profiler->measure("view.render", static fn(): null => null);
        $profiler->measure("view.render", static fn(): null => null);
        $profiler->measure("view.parse", static fn(): null => null);

        $summary = $profiler->summary();

        $this->assertSame(2, $profiler->count("view.render"));
        $this->assertGreaterThanOrEqual(0.0, $profiler->total("view.render"));
        $this->assertSame(2, $summary["view.render"]->count);
        $this->assertSame(1, $summary["view.parse"]->count);
    }

    public function testProfileFacadeUsesStandaloneProfiler(): void
    {
        $result = Profile::measure("fallback.work", static fn(): string => "done");

        $this->assertSame("done", $result);
        $this->assertSame("fallback.work", Profile::profiler()->spans()[0]->name);
    }

    public function testProfileFacadeUsesProfilerFromResolverWhenAvailable(): void
    {
        $fallback = Profile::profiler();
        $profiler = new Profiler();
        Profile::resolveUsing(static fn(): Profiler => $profiler);

        Profile::begin("resolved.work")->end();

        $this->assertSame($profiler, Profile::profiler());
        $this->assertSame(1, $profiler->count("resolved.work"));
        $this->assertSame(0, $fallback->count("resolved.work"));

        Profile::resolveUsing(static fn(): ?Profiler => null);
        $this->assertSame($fallback, Profile::profiler());
    }
}
