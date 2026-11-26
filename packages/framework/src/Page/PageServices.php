<?php

declare(strict_types=1);

namespace Atom\Page;

use Atom\Di\Bindings;
use Atom\Di\ServiceProviderInterface;
use Atom\Integration\View\ViewServices;

final readonly class PageServices implements ServiceProviderInterface
{
    public function register(Bindings $bindings): void
    {
        (new ViewServices())->register($bindings);

        $bindings->bind(PageViewLocator::class)
            ->toSelf()
            ->singleton();

        $bindings->bind(PageRenderer::class)
            ->toSelf()
            ->scoped();

        $bindings->bind(PageActionHandler::class)
            ->toSelf()
            ->scoped();

        $bindings->bind(PageInputHydratorInterface::class)
            ->to(PageInputHydrator::class)
            ->scoped();

        $bindings->bind(PageStateSerializerInterface::class)
            ->to(JsonPageStateSerializer::class)
            ->scoped();
    }
}
