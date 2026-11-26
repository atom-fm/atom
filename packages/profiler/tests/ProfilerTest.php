<?php

declare(strict_types=1);

namespace Atom\Tests\Profiler;

use Atom\Profiler\Profile;
use Atom\Profiler\Profiler;
use PHPUnit\Framework\TestCase;

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
