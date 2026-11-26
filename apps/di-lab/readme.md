# Atom DI Lab

DI Lab is a standalone PHP 8.4 Composer project. Its only dependency is the monorepo's `atom-fm/di` package, linked through a Composer path repository. Run these commands from this directory.

```powershell
composer install
composer check
```

The sample prices two orders using constructor injection, a service provider, and a type factory that reads order data from the active scope. The checks also exercise named argument overrides, lifetimes, aliases, child injectors, and error paths. Sample classes live in [app](app) and checks live in [check.php](check.php).

The optional dependency checks verify that an absent binding can resolve to `null` and that errors from a registered factory, alias, or matching type factory propagate.

The type factory checks cover both registration paths, lookup order, and isolation when injectors share the same `Bindings`.

## API review

| Area | API to retain |
| --- | --- |
| Registration | `Bindings` for types, values, factories, aliases, and lifetimes. `Provider` also supports building an injector from explicit registrations. |
| Resolution | `Injector::get()` for tokens and concrete classes, `instantiate()` for construction with named arguments, and `invoke()` for calling functions, methods, and invokable objects with injection. `has()` reports a registered token or an existing class. Construction can still fail. |
| Scoping | `InjectionContext` holds per-operation values and scoped instances. `createChild()` adds local bindings and shares the parent's singletons for inherited bindings. |
| Composition | `ServiceProviderInterface` and `ServiceProviderRegistry` group registrations. `#[Inject]` selects an explicit token for a parameter. |
| Extension points | `TypeFactory::match()` and `TypeInfo` support matched construction, including Framework's DTO integration. `UnrecoverableDependencyException` lets an integration propagate domain-specific input errors unchanged. |
| Diagnostics | `DependencyResolutionException`, `CircularDependencyException`, and `ScopedDependencyException` describe resolution failures and their dependency paths. |

### Confirmed findings

- Replacing a binding after its singleton has resolved leaves the old value cached. The API needs a defined behavior for rebinding after first resolution. [Singleton lookup](../../packages/di/src/Injector.php#L86)
