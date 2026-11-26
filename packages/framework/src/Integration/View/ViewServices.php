<?php

declare(strict_types=1);

namespace Atom\Integration\View;

use Atom\Di\Bindings;
use Atom\Di\InjectionContext;
use Atom\Di\Injector;
use Atom\Di\ServiceProviderInterface;
use Atom\View\Component\ComponentFactoryInterface;
use Atom\View\Component\ComponentHydrator;
use Atom\View\Component\ComponentRegistry;
use Atom\View\Parser\ViewParser;
use Atom\View\Render\ExpressionEvaluatorInterface;
use Atom\View\Render\PhpExpressionEvaluator;
use Atom\View\Render\ViewRenderer;
use Atom\View\Style\ComponentStyleScanner;
use Atom\View\TemplateCache;

final readonly class ViewServices implements ServiceProviderInterface
{
    public function register(Bindings $bindings): void
    {
        $bindings->bind(ExpressionEvaluatorInterface::class)
            ->to(PhpExpressionEvaluator::class)
            ->singleton();

        $bindings->bind(ComponentStyleScanner::class)
            ->toSelf()
            ->singleton();

        $bindings->bind(ComponentRegistry::class)
            ->toSelf()
            ->singleton();

        $bindings->bind(ComponentFactoryInterface::class)
            ->toFactory(static fn(Injector $injector, InjectionContext $context): ComponentFactoryInterface =>
                new InjectorComponentFactory($injector, $context)
            )
            ->scoped();

        $bindings->bind(ComponentHydrator::class)
            ->toSelf()
            ->scoped();

        $bindings->bind(ViewParser::class)
            ->toSelf()
            ->singleton();

        $bindings->bind(TemplateCache::class)
            ->toSelf()
            ->singleton();

        $bindings->bind(ViewRenderer::class)
            ->toSelf()
            ->scoped();
    }
}
