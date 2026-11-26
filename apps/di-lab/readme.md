# Atom DI Lab

DI Lab is a standalone PHP 8.4 Composer project. Its only dependency is the monorepo's `atom-fm/di` package, linked through a Composer path repository. Run these commands from this directory.

```powershell
composer install
composer check
```

The sample prices two orders using constructor injection, a service provider, and a type factory that reads order data from the active scope. The checks also exercise named argument overrides, lifetimes, aliases, child injectors, and error paths. Sample classes live in [app](app) and checks live in [check.php](check.php).

## API review

| Area | API to retain |
| --- | --- |
| Registration | `Bindings` for types, values, factories, aliases, and lifetimes. `Provider` also supports building an injector from explicit registrations. |
| Resolution | `Injector::get()` for tokens and concrete classes, `instantiate()` for construction with named arguments, and `invoke()` for calling functions and methods with injection. `has()` reports a registered token or an existing class. Construction can still fail. |
| Scoping | `InjectionContext` holds per-operation values and scoped instances. `createChild()` adds local bindings and shares the parent's singletons for inherited bindings. |
| Composition | `ServiceProviderInterface` and `ServiceProviderRegistry` group registrations. `#[Inject]` selects an explicit token for a parameter. |
| Extension points | `TypeFactory::match()` and `TypeInfo` support matched construction, including Framework's DTO integration. `UnrecoverableDependencyException` lets an integration propagate domain-specific input errors unchanged. |
| Diagnostics | `DependencyResolutionException`, `CircularDependencyException`, and `ScopedDependencyException` describe resolution failures and their dependency paths. |

### Confirmed findings

- `Injector::invoke()` accepts `callable`, but an object with `__invoke()` reaches `ReflectionFunction` and raises `TypeError`. [Callable reflection](../../packages/di/src/Injector.php#L338)
- `Injector::addTypeFactory()` registers a factory that lookup ignores when the injector uses a `Bindings` instance. The lab registers its factory with `Bindings::addTypeFactory()`. [Type factory lookup](../../packages/di/src/Injector.php#L260)
- An ordinary factory failure becomes `null` when a constructor parameter is nullable. A missing binding and a broken registered service currently produce the same result in that case. [Parameter resolution](../../packages/di/src/Injector.php#L305)
- Replacing a binding after its singleton has resolved leaves the old value cached. The API needs a defined behavior for rebinding after first resolution. [Singleton lookup](../../packages/di/src/Injector.php#L74)
