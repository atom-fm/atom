<?php

declare(strict_types=1);

use Atom\Di\Bindings;
use Atom\Di\Exception\CircularDependencyException;
use Atom\Di\Exception\DependencyResolutionException;
use Atom\Di\Exception\ScopedDependencyException;
use Atom\Di\InjectionContext;
use Atom\Di\Injector;
use Atom\Di\ServiceProviderRegistry;
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
verify(
    $injector->invoke($total, ["input" => new OrderInput("pen", 1)], $order) === 150,
    "An explicit callable argument did not override injection."
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

echo "DI Lab checks passed.\n";
