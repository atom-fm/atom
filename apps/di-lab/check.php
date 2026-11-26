<?php

declare(strict_types=1);

use Atom\Di\Bindings;
use Atom\Di\Exception\CircularDependencyException;
use Atom\Di\Exception\DependencyResolutionException;
use Atom\Di\Exception\ScopedDependencyException;
use Atom\Di\InjectionContext;
use Atom\Di\Injector;
use Atom\Di\ServiceProviderRegistry;
use Atom\Di\TypeFactory;
use Atom\Di\TypeInfo;
use Composer\InstalledVersions;
use DiLab\OrderInput;
use DiLab\OrderQuote;
use DiLab\OrderServices;
use DiLab\PriceCatalog;

require __DIR__ . "/vendor/autoload.php";

function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$packages = array_diff(InstalledVersions::getInstalledPackages(), [InstalledVersions::getRootPackage()["name"]]);
verify(array_values($packages) === ["atom-fm/di"], "DI must be the sole installed dependency.");

$bindings = ServiceProviderRegistry::create()->add(OrderServices::class)->bindings();
$injector = Injector::create($bindings);
$quote = $injector->get(OrderQuote::class);
verify($quote->currency === "EUR", "Autowiring did not inject the configured token.");
verify($injector->get(OrderQuote::class) !== $quote, "Unbound concrete classes should resolve as transient.");

$order = new InjectionContext();
$order->set("order.sku", "notebook");
$order->set("order.quantity", 3);
$nextOrder = new InjectionContext();
$nextOrder->set("order.sku", "pen");
$nextOrder->set("order.quantity", 2);

$total = static fn(OrderQuote $quote, OrderInput $input): int => $quote->totalInCents($input);
verify($injector->invoke($total, context: $order) === 1200, "The type factory did not receive the order context.");
verify($injector->invoke($total, context: $nextOrder) === 300, "Order inputs leaked between contexts.");

$customInjector = Injector::create($bindings)
    ->addTypeFactory(TypeFactory::match(
        static fn(TypeInfo $type): bool => $type->name === OrderQuote::class,
        static fn(string $className, Injector $injector, InjectionContext $context): OrderQuote => new OrderQuote(
            $injector->get(PriceCatalog::class, $context),
            "GBP",
        ),
    ))
    ->addTypeFactory(TypeFactory::match(
        static fn(TypeInfo $type): bool => $type->name === OrderInput::class,
        static fn(): OrderInput => new OrderInput("pen", 1),
    ));
verify($customInjector->get(OrderQuote::class)->currency === "GBP", "A Bindings-backed injector ignored its own type factory.");
verify($customInjector->invoke($total, context: $order) === 1200, "A directly registered type factory took precedence over Bindings.");
verify($injector->get(OrderQuote::class)->currency === "EUR", "A directly registered type factory leaked into shared Bindings.");

verify(
    $injector->invoke($total, ["input" => new OrderInput("pen", 1)], $order) === 150,
    "An explicit callable argument did not override injection."
);

$totalWithFee = new class(50) {
    public function __construct(private readonly int $feeInCents)
    {
    }

    public function __invoke(OrderQuote $quote, OrderInput $input): int
    {
        return $quote->totalInCents($input) + $this->feeInCents;
    }
};
verify(
    $injector->invoke($totalWithFee, context: $order) === 1250,
    "An invokable object did not receive injected arguments or retain its instance state."
);

verify(
    $injector->instantiate(OrderQuote::class, ["currency" => "GBP"])->currency === "GBP",
    "An explicit constructor argument did not take precedence over the Inject attribute."
);

$scope = $injector->get("order.scope", $order);
verify($injector->get("order.scope", $order) === $scope, "A scoped factory did not reuse its instance.");
verify($injector->get("order.scope", $nextOrder) !== $scope, "A scoped instance leaked between orders.");

$catalog = $injector->get(PriceCatalog::class, $order);
verify($injector->get("shop.catalog", $nextOrder) === $catalog, "The alias did not reuse the singleton.");
$child = $injector->createChild(Bindings::create()->value("shop.currency", "USD"));
verify($child->get(PriceCatalog::class) === $catalog, "The child did not reuse its parent's singleton.");
verify($child->get(OrderQuote::class)->currency === "USD", "The child did not apply its local binding.");
verify($child->invoke($total, context: $order) === 1200, "The child did not inherit its parent's type factory.");
verify($injector->get(OrderQuote::class)->currency === "EUR", "The child changed its parent's binding.");

$bindings->factory("invalid.singleton", static function (Injector $injector, InjectionContext $context): object {
    return $injector->get("order.scope", $context);
})->singleton();
try {
    $injector->get("invalid.singleton", $order);
    throw new RuntimeException("A singleton captured a scoped object.");
} catch (ScopedDependencyException $exception) {
    verify(str_contains($exception->getMessage(), "invalid.singleton -> order.scope"), "Missing scoped resolution path.");
}
verify($injector->get("order.scope", $order) === $scope, "A failed resolution changed the order scope.");

$cycle = Injector::create(Bindings::create()->existing("first", "second")->existing("second", "first"));
try {
    $cycle->get("first");
    throw new RuntimeException("The injector did not detect an alias cycle.");
} catch (CircularDependencyException $exception) {
    verify(str_contains($exception->getMessage(), "first -> second -> first"), "Missing circular resolution path.");
}

try {
    $injector->get("order.missing");
    throw new RuntimeException("The injector accepted an unbound token.");
} catch (DependencyResolutionException $exception) {
    verify(str_contains($exception->getMessage(), "order.missing"), "The error did not identify the missing token.");
}

$optionalCatalog = static fn(?PriceCatalog $catalog): ?PriceCatalog => $catalog;
verify(Injector::create()->invoke($optionalCatalog) === null, "An absent optional dependency did not resolve to null.");
verify(
    Injector::create()->invoke(static fn(?ReflectionFunctionAbstract $function): ?ReflectionFunctionAbstract => $function) === null,
    "An unbound abstract type did not resolve to null."
);

$catalogContext = new InjectionContext();
$catalogContext->set(PriceCatalog::class, $catalog);
verify(
    Injector::create()->invoke($optionalCatalog, context: $catalogContext) === $catalog,
    "An optional dependency ignored the value supplied by the context."
);

$factoryFailure = new RuntimeException("Catalog could not be opened.");
$brokenCatalog = Bindings::create();
$brokenCatalog->factory(PriceCatalog::class, static fn(): never => throw $factoryFailure);
try {
    Injector::create($brokenCatalog)->invoke($optionalCatalog);
    throw new RuntimeException("A failing optional factory was replaced with null.");
} catch (DependencyResolutionException $exception) {
    verify(str_contains($exception->getMessage(), $factoryFailure->getMessage()), "The factory failure was lost.");
    $cause = $exception;
    while ($cause->getPrevious() !== null) {
        $cause = $cause->getPrevious();
    }
    verify($cause === $factoryFailure, "The original factory exception was lost.");
}

$brokenAlias = Bindings::create()->existing(PriceCatalog::class, "shop.missing_catalog");
try {
    Injector::create($brokenAlias)->invoke($optionalCatalog);
    throw new RuntimeException("A broken alias was replaced with null.");
} catch (DependencyResolutionException $exception) {
    verify(str_contains($exception->getMessage(), "shop.missing_catalog"), "The alias failure hid its target.");
}

try {
    $injector->invoke(static fn(?OrderInput $input): ?OrderInput => $input);
    throw new RuntimeException("A failing optional type factory was replaced with null.");
} catch (DependencyResolutionException $exception) {
    verify(str_contains($exception->getMessage(), "order.sku"), "The type factory failure hid the missing input.");
}

$replacementBindings = Bindings::create();
$replacementBindings->factory("catalog", static fn(): object => new stdClass())->singleton();
$owner = Injector::create($replacementBindings);
$consumer = $owner->createChild();
$original = $consumer->get("catalog");
$replacementBindings->factory("catalog", static fn(): object => new stdClass())->singleton();
$replacement = $consumer->get("catalog");
verify($replacement !== $original, "Rebinding kept the original singleton.");
verify($owner->get("catalog") === $replacement, "The child and parent did not share the replacement.");

$replacementBindings->factory("catalog", static fn(): object => new stdClass())->transient();
verify($owner->get("catalog") !== $consumer->get("catalog"), "The replacement ignored its transient lifetime.");

$childBindings = Bindings::create();
$childBindings->factory("order.scope", static fn(): object => new stdClass())->scoped();
$scopedChild = $injector->createChild($childBindings);
$childScope = $scopedChild->get("order.scope", $order);
verify($childScope !== $scope, "A child override reused the parent's cached scoped instance.");
verify($scopedChild->get("order.scope", $order) === $childScope, "The child did not reuse its own scoped instance.");
verify($injector->get("order.scope", $order) === $scope, "The child replaced the parent's scoped instance.");
verify($injector->createChild()->get("order.scope", $order) === $scope, "An inherited binding did not share its scoped instance.");

set_error_handler(static function (int $severity, string $message): never {
    throw new ErrorException($message, severity: $severity);
}, E_DEPRECATED);
try {
    verify($injector->invoke(Bindings::class . "::create") instanceof Bindings, "Static method string invocation failed.");
} finally {
    restore_error_handler();
}

echo "DI Lab checks passed.\n";
