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

## Supported API

DI is in maintenance mode. Routine changes focus on fixes, compatibility, and documentation for the APIs below.

| Area | Contract |
| --- | --- |
| Registration | Use `Bindings` to register types, values, factories, and aliases. Type and factory bindings support transient, singleton, and scoped lifetimes. `Provider` also supports building an injector from an iterable of registrations. |
| Resolution | Use `Injector::get()` to look up a token or autowire a concrete class, `instantiate()` to construct a class with named arguments, and `invoke()` to inject arguments into a callable. `has()` checks for a registered token or an existing class. |
| Arguments | Named arguments take precedence over `#[Inject]` and type-based resolution. A missing dependency resolved by nullable type can fall back to `null`. Resolution failures propagate, and an explicit `#[Inject]` token must resolve. |
| Composition | Use `ServiceProviderInterface` and `ServiceProviderRegistry` to group registrations. A child injector can override bindings and inherits its parent's singleton when resolving an inherited binding. |
| Lifetimes | Transient services are resolved on each lookup. Singletons are cached per injector that owns the binding. Replacing a binding invalidates its singleton for future lookups. Use an `InjectionContext` to share scoped services across an operation and supply pre-resolved values. A singleton cannot capture a scoped service. |
| Type factories | Use `TypeFactory::match()` and `TypeInfo` to construct matching unregistered types. Lookup checks explicit providers first, then factories from local `Bindings`, factories registered directly on the injector, and finally inherited factories. |
| Errors | Catch `DependencyResolutionException` for dependency resolution errors. `CircularDependencyException` and `ScopedDependencyException` report invalid dependency graphs. Integrations can extend `UnrecoverableDependencyException` to propagate a domain-specific failure unchanged. |

Provider factories receive the owning injector and the active context. Forward the context when a factory resolves dependencies. DI manages the resolution stack and binding revision bookkeeping internally.

An inherited scoped binding shares its cached instance with the parent in the same context. A child override has its own scoped instance. Use the injector to resolve scoped services and `InjectionContext::set()`, `has()`, and `get()` to manage explicit context values.
