# Atom DI

Atom DI provides constructor injection, scoped services, and service provider registration for PHP 8.4.

For local use, add this directory as a Composer path repository and require `atom-fm/di` at `^0.1@dev`. The bundled projects already configure the repository.

```php
use Atom\Di\Bindings;
use Atom\Di\Injector;

$bindings = Bindings::create()->value(Clock::class, new SystemClock());
$report = Injector::create($bindings)->get(Report::class);
```

In this example, `Report` declares a `Clock` constructor parameter. Use an `InjectionContext` to share scoped services across resolutions in one operation.
