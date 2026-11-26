# Atom Profiler

Atom Profiler measures elapsed time for named spans and summarizes repeated work. It runs on PHP 8.4.

For local use, add this directory as a Composer path repository and require `atom-fm/profiler` at `^0.1@dev`. The bundled projects already configure the repository.

```php
use Atom\Profiler\Profile;

$result = Profile::measure("report.totals", static fn(): int => array_sum([12, 18, 7]));
echo Profile::profiler()->total("report.totals"), " ms\n";
```

Standalone scripts use a default profiler. Framework supplies the active profiler during application bootstrap and request handling.

Durations and totals are in milliseconds. Span start and end values are seconds relative to the monotonic clock's reference point.

## Supported API

Profiler is in maintenance mode. Routine changes focus on fixes, compatibility, and documentation for the APIs below.

| Area | Contract |
| --- | --- |
| Recording | Use `Profiler::begin()` and `ProfileSpan::end()` for manual timing. Ending a span twice records it once. `Profiler::measure()` closes the span on success or failure and preserves the callback's return value or exception. |
| Span data | Read `ProfileSpan::name`, `metadata`, `startedAt`, `endedAt()`, and `durationMs()`. An open span has no end time and its duration grows until it ends. |
| Results | `spans()` returns completed spans in completion order. `count()` and `total()` aggregate by name, and `summary()` returns grouped `ProfileSummary` values. `totalMs()` measures elapsed time since profiler construction. Nested spans contribute their full durations to their own groups. |
| Facade | `Profile::begin()`, `measure()`, and `profiler()` use the active profiler or the standalone fallback. `Profile::reset()` clears the fallback for subsequent calls. |

Create spans through `begin()`. Span construction, ownership checks, and `Profiler::record()` are internal.

## Framework integration

Framework supplies the active profiler through the internal `Profile::resolveUsing()` hook and exposes it through dependency injection. Bootstrap and individual requests use separate profilers. Framework instruments dispatch, page rendering, and View, and uses `totalMs()` and `summary()` to produce the `X-Atom-Time` and `X-Atom-Time-*` response headers.

When span names such as `cache.read` and `cache-read` map to the same response header, Framework keeps the first header and gives later summaries the next available numeric suffix, starting at `-2`.
