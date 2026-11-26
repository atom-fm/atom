<?php

declare(strict_types=1);

namespace DiLab;

use Atom\Di\Bindings;
use Atom\Di\InjectionContext;
use Atom\Di\Injector;
use Atom\Di\ServiceProviderInterface;
use Atom\Di\TypeFactory;
use Atom\Di\TypeInfo;
use stdClass;

final class OrderServices implements ServiceProviderInterface
{
    public function register(Bindings $bindings): void
    {
        $bindings->bind(PriceCatalog::class)->to(DemoPriceCatalog::class)->singleton();
        $bindings->value("shop.currency", "EUR");
        $bindings->existing("shop.catalog", PriceCatalog::class);
        $bindings->factory("order.scope", static fn(): stdClass => new stdClass())->scoped();
        $bindings->addTypeFactory(TypeFactory::match(
            static fn(TypeInfo $type): bool => $type->name === OrderInput::class,
            static fn(string $className, Injector $injector, InjectionContext $context): OrderInput => new OrderInput(
                $injector->get("order.sku", $context),
                $injector->get("order.quantity", $context),
            ),
        ));
    }
}
