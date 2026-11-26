# Dependency Injection

[Atom Framework](Index.md)

Atom uses a small constructor-injection container under `Atom\Di`, provided by the `atom-fm/di` package. Framework uses the same container to wire requests, pages, and modules.

The main pieces are:

- `Bindings` for registering providers
- `Injector` for resolving and invoking services
- `InjectionContext` for scoped services within a request/action
- `ServiceProviderInterface` and `ServiceProviderRegistry` for application-level registration

## Basic Usage

```php
use Atom\Di\Bindings;
use Atom\Di\Injector;

$bindings = Bindings::create();

$bindings->bind(UserRepositoryInterface::class)
    ->to(DatabaseUserRepository::class);

$bindings->bind(UserService::class)
    ->toSelf();

$injector = Injector::create($bindings);

$service = $injector->get(UserService::class);
```

If a concrete class is not registered, the injector still tries to autowire it by resolving constructor dependencies.

## Bindings

Register a type:

```php
$bindings->bind(UserRepositoryInterface::class)
    ->to(DatabaseUserRepository::class);

$bindings->bind(UserService::class)
    ->toSelf();
```

Short form:

```php
$bindings->type(UserService::class);
$bindings->type(UserRepositoryInterface::class, DatabaseUserRepository::class);
```

Register a value:

```php
$bindings->value("config", [
    "name" => "Atom",
]);
```

Register a factory:

```php
use Atom\Di\InjectionContext;
use Atom\Di\Injector;

$bindings->factory("app.name", function (Injector $injector, InjectionContext $context): string {
    $config = $injector->get("config", $context);

    return $config["name"];
});
```

Register an alias to an existing token:

```php
$bindings->existing("primaryDatabase", DatabaseConnection::class);
```

## Lifetimes

Providers are transient by default.

```php
$bindings->bind(RequestState::class)
    ->toSelf()
    ->transient();
```

Singleton services are shared for the lifetime of the injector:

```php
$bindings->bind(DatabaseConnection::class)
    ->toSelf()
    ->singleton();
```

Scoped services are shared inside one `InjectionContext`:

```php
use Atom\Di\InjectionContext;

$bindings->bind(RequestState::class)
    ->toSelf()
    ->scoped();

$injector = Injector::create($bindings);
$context = new InjectionContext();

$first = $injector->get(RequestState::class, $context);
$second = $injector->get(RequestState::class, $context);

// $first === $second
```

A different context receives a different scoped instance.

A singleton that resolves a scoped service, directly or through another dependency, raises `ScopedDependencyException`. The message names both services and includes the resolution path. This also applies to values supplied by the active context, such as the HTTP request and CLI output. Bind a service that needs those values as scoped or pass the values to its methods for each invocation.

## Constructor Injection

### Price an order line with a runtime product code

Each order line needs a price list and the product code selected for that order. Use `instantiate()` to pass the code by parameter name and let Atom resolve the typed price list.

Save `price-line.php` beside `composer.json` in an installed Starter app.

```php
<?php

declare(strict_types=1);

use Atom\Di\Injector;

require __DIR__ . "/vendor/autoload.php";

final class PriceList
{
    public function cents(string $sku): int
    {
        return match ($sku) {
            "notebook" => 450,
            "pencil" => 75,
            default => throw new InvalidArgumentException("Unknown product: {$sku}"),
        };
    }
}

final readonly class OrderLine
{
    public function __construct(private PriceList $prices, private string $sku)
    {
    }

    public function subtotal(int $quantity): int
    {
        return $this->prices->cents($this->sku) * $quantity;
    }
}

$injector = Injector::create();
$line = $injector->instantiate(OrderLine::class, ["sku" => $argv[1] ?? "notebook"]);
echo $line->subtotal(2), " cents", PHP_EOL;
```

Run `php price-line.php` to price two notebooks at `900 cents`. Run `php price-line.php pencil` to price two pencils at `150 cents`. The `sku` key matches the constructor's `$sku` parameter. Atom constructs `PriceList` from its class type.

## Invoke Methods and Closures

Use `invoke()` when your caller has a callable and a set of named inputs. In the same script, replace the final `echo` line with the following code to accept a quantity as the second CLI argument.

```php
$quantity = filter_var($argv[2] ?? "2", FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
if ($quantity === false) {
    fwrite(STDERR, "Enter a positive whole-number quantity." . PHP_EOL);
    exit(1);
}

$total = $injector->invoke([$line, "subtotal"], ["quantity" => $quantity]);
echo $total, " cents", PHP_EOL;
```

Run `php price-line.php pencil 4`. The named argument reaches `subtotal(int $quantity)` and the script prints `300 cents`. With `php price-line.php pencil 0`, the script reports the invalid quantity before invoking the method.

Explicit parameters win by name. The injector resolves any remaining class-typed parameters from the container.

## Named Tokens

### Supply the shop's price list

To use the same order calculation with a different shop's prices, pass the price table through the injector. Start with `price-line.php` from [Price an order line with a runtime product code](#price-an-order-line-with-a-runtime-product-code). Add `use Atom\Di\Attributes\Inject;` and `use Atom\Di\Bindings;` to the imports. Replace `PriceList` with this version.

```php
final readonly class PriceList
{
    /** @param array<string, int> $prices Prices in cents, keyed by product code. */
    public function __construct(#[Inject("shop.prices")] private array $prices)
    {
    }

    public function cents(string $sku): int
    {
        return $this->prices[$sku] ?? throw new InvalidArgumentException("Unknown product: {$sku}");
    }
}
```

Replace `$injector = Injector::create();` with the following lines. The token in `value()` matches the one on the constructor parameter. Atom resolves `PriceList` by class and supplies the registered array when it constructs it.

```php
$bindings = Bindings::create()->value("shop.prices", [
    "notebook" => 450,
    "pencil" => 60,
]);
$injector = Injector::create($bindings);
```

Run `php price-line.php pencil` to price two pencils at `120 cents`. Change the bound pencil price to `55` and run the command again. The same `OrderLine::subtotal()` now returns `110 cents` for the pair.

## Type Factories

Type factories create unregistered classes that match a predicate. This is useful for framework-level patterns such as DTOs.

```php
use Atom\Di\InjectionContext;
use Atom\Di\Injector;
use Atom\Di\TypeFactory;
use Atom\Di\TypeInfo;

$bindings->addTypeFactory(TypeFactory::match(
    fn(TypeInfo $type): bool => $type->hasAttribute(Dto::class),
    function (string $className, Injector $injector, InjectionContext $context): object {
        $request = $injector->get(Request::class, $context);

        return new $className($request->post()->string("name"));
    }
));
```

Explicit providers always win over type factories.

Dispatcher registers Atom's request DTO factory. It reads the active request and matched route, then supplies that input to Hydrator.

## Child Injectors

### Test a booking deadline with a fixed clock

A booking is open until its deadline and closes at the exact cutoff. Framework binds `Atom\Support\ClockInterface` to the system clock for application services, including Session and Scheduler. A child injector lets a test supply a fixed time for that same constructor dependency.

In a starter project with Composer dependencies installed, create `app/Bookings/BookingWindow.php`.

```php
<?php

declare(strict_types=1);

namespace App\Bookings;

use Atom\Support\ClockInterface;
use DateTimeImmutable;

final readonly class BookingWindow
{
    public function __construct(private ClockInterface $clock)
    {
    }

    public function isOpen(DateTimeImmutable $deadline): bool
    {
        return $this->clock->now() < $deadline;
    }
}
```

In the application, resolving `BookingWindow::class` uses the registered system clock. Atom can construct this concrete class from its typed constructor, so it needs no separate registration.

For the test, save `check-booking.php` in the project root. The child binds the clock interface to one object that always reports noon UTC. Resolve the booking service from the child so the injector passes that clock into its constructor.

```php
<?php

declare(strict_types=1);

use App\Application;
use App\Bookings\BookingWindow;
use Atom\Di\Provider;
use Atom\Support\ClockInterface;

require __DIR__ . "/vendor/autoload.php";

$app = new Application();
$app->initialize();

$clock = new class implements ClockInterface {
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable("2030-06-01 12:00:00 UTC");
    }
};

$child = $app->getInjector()->createChild([
    Provider::value(ClockInterface::class, $clock),
]);
$bookings = $child->get(BookingWindow::class);

foreach (["12:00:01" => true, "12:00:00" => false] as $time => $expected) {
    $deadline = new DateTimeImmutable("2030-06-01 {$time} UTC");
    $open = $bookings->isOpen($deadline);
    if ($open !== $expected) {
        throw new RuntimeException("Unexpected booking status for {$time}");
    }
    echo $time, " ", $open ? "open" : "closed", PHP_EOL;
}
```

Run `php check-booking.php` from the project root. The first deadline is one second away. The second is exactly noon.

```text
12:00:01 open
12:00:00 closed
```

The clock override belongs to the child. The application injector still supplies the system clock to its own callers.

## Service Providers

Service providers group bindings:

```php
use Atom\Di\Bindings;
use Atom\Di\ServiceProviderInterface;

final class BlogServices implements ServiceProviderInterface
{
    public function register(Bindings $bindings): void
    {
        $bindings->bind(PostRepository::class)
            ->toSelf()
            ->singleton();
    }
}
```

Register providers in the application:

```php
use Atom\Di\ServiceProviderRegistry;

final class Application extends \Atom\Application
{
    protected function services(ServiceProviderRegistry $providers): void
    {
        $providers->add(BlogServices::class);
    }
}
```

`ServiceProviderRegistry` accepts provider classes or provider instances.

## Errors

The injector throws `DependencyResolutionException` when it cannot resolve a dependency, and `CircularDependencyException` when it detects a cycle.

A missing provider for a nullable typed dependency can resolve to `null`. When a provider needs the caller to handle a specific resolution failure, its exception can extend `Atom\Di\Exception\UnrecoverableDependencyException`. The injector rethrows the same exception for required and nullable parameters.

