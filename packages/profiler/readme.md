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
