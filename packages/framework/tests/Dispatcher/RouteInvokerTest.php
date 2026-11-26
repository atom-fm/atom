<?php

declare(strict_types=1);

namespace Atom\Tests\Dispatcher;

use Atom\Di\Bindings;
use Atom\Di\InjectionContext;
use Atom\Di\Injector;
use Atom\Dispatcher\RouteInvoker;
use Atom\Router\MatchedRoute;
use Atom\Router\RouteAction;
use Atom\Router\RouteEntry;
use PHPUnit\Framework\TestCase;

final class RouteInvokerTest extends TestCase
{
    public function testInvokesClosureWithRouteParameters(): void
    {
        $route = RouteEntry::create(
            "GET",
            "/users/{id}",
            RouteAction::closure(function (string $id) {
                return "user-" . $id;
            })
        );

        $this->assertSame("user-42", $this->invoke($route));
    }

    public function testInvokesControllerMethodWithRouteParameters(): void
    {
        $route = RouteEntry::create(
            "GET",
            "/users/{id}",
            RouteAction::method(RouteInvokerTestController::class, "show")
        );

        $this->assertSame("user-42", $this->invoke($route));
    }

    public function testInvokesControllerInvokeMethodByDefault(): void
    {
        $route = RouteEntry::create(
            "GET",
            "/users/{id}",
            RouteAction::method(InvokableRouteController::class)
        );

        $this->assertSame("invoked-42", $this->invoke($route));
    }

    public function testRouteEntryCreateAcceptsCallableAndArrayAndInvokableClass(): void
    {
        $closure = RouteEntry::get("/users/{id}", fn(string $id): string => "closure-" . $id);
        $method = RouteEntry::get("/users/{id}", [RouteInvokerTestController::class, "show"]);
        $invoke = RouteEntry::get("/users/{id}", InvokableRouteController::class);

        $this->assertSame("closure-42", $this->invoke($closure));
        $this->assertSame("user-42", $this->invoke($method));
        $this->assertSame("invoked-42", $this->invoke($invoke));
    }

    public function testRouteEntryCanReplaceRouteAction(): void
    {
        $route = RouteEntry::create(
            "GET",
            "/users/{id}",
            RouteAction::closure(fn () => "old")
        )->action(RouteAction::method(RouteInvokerTestController::class, "show"));

        $this->assertSame("user-42", $this->invoke($route));
    }

    public function testReusesScopedDependenciesAcrossControllerAndMethod(): void
    {
        $route = RouteEntry::create(
            "GET",
            "/users/{id}",
            RouteAction::method(ActionScopedController::class, "show")
        );

        $bindings = Bindings::create();
        $bindings->bind(ActionScopedDependency::class)
            ->toSelf()
            ->scoped();

        $this->assertSame("same", $this->invoke($route, $bindings));
    }

    private function invoke(RouteEntry $route, ?Bindings $bindings = null): mixed
    {
        $invoker = new RouteInvoker(new Injector($bindings ?? []));

        return $invoker->invoke(new MatchedRoute($route, ["id" => "42"]), new InjectionContext());
    }
}

final class RouteInvokerTestController
{
    public function show(string $id): string
    {
        return "user-" . $id;
    }
}

final class InvokableRouteController
{
    public function __invoke(string $id): string
    {
        return "invoked-" . $id;
    }
}

final readonly class ActionScopedController
{
    public function __construct(private ActionScopedDependency $dependency)
    {
    }

    public function show(ActionScopedDependency $dependency): string
    {
        return $this->dependency === $dependency ? "same" : "different";
    }
}

final class ActionScopedDependency
{
}
