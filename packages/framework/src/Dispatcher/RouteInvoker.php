<?php

declare(strict_types=1);

namespace Atom\Dispatcher;

use Atom\Api\Responses\UnprocessableContent;
use Atom\Di\InjectionContext;
use Atom\Di\Injector;
use Atom\Http\Request;
use Atom\Hydrator\Attributes\FromBody;
use Atom\Hydrator\Attributes\FromQuery;
use Atom\Hydrator\Attributes\FromRoute;
use Atom\Hydrator\Exception\HydrationException;
use Atom\Hydrator\HydrationContext;
use Atom\Hydrator\HydrationTarget;
use Atom\Hydrator\RequestHydrator;
use Atom\Router\MatchedRoute;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use RuntimeException;

final readonly class RouteInvoker
{
    public function __construct(private Injector $injector)
    {
    }

    public function invoke(MatchedRoute $route, InjectionContext $context): mixed
    {
        $action = $route->getRouteAction();
        if ($action->closure !== null) {
            $arguments = $this->bindArguments(new ReflectionFunction($action->closure), $route, $context);

            return $this->injector->invoke($action->closure, $arguments, $context);
        }

        $controllerType = $action->controllerType;
        $methodName = $action->methodName;
        if ($controllerType === null || $methodName === null) {
            throw new RuntimeException("Controller action must define controller type and method name.");
        }

        $reflection = new ReflectionClass($controllerType);
        if (!$reflection->hasMethod($methodName)) {
            throw new RuntimeException("Controller {$reflection->getName()} does not have method {$methodName}.");
        }

        $handler = $reflection->getMethod($methodName);
        $arguments = $this->bindArguments($handler, $route, $context);
        $controller = $this->injector->instantiate($controllerType, $route->getParams(), $context);

        return $this->injector->invoke([$controller, $handler->getName()], $arguments, $context);
    }

    /** @return array<string, mixed> */
    private function bindArguments(
        ReflectionFunctionAbstract $handler,
        MatchedRoute $route,
        InjectionContext $context
    ): array {
        $arguments = $route->getParams();
        $request = $context->get(Request::class);
        $routeParams = $route->getRouteParams();
        $input = $request instanceof Request
            ? HydrationContext::fromRequest($request, $routeParams)
            : new HydrationContext(query: $route->getQueryParams(), route: $routeParams);
        $jsonBody = $request instanceof Request && $request->hasJsonContentType();
        foreach ($handler->getParameters() as $parameter) {
            $fromRoute = $parameter->getAttributes(FromRoute::class) !== [];
            $fromQuery = $parameter->getAttributes(FromQuery::class) !== [];
            if (!$fromRoute && !$fromQuery && ($parameter->getAttributes(FromBody::class) === [] || !$request instanceof Request)) {
                continue;
            }

            $target = HydrationTarget::fromParameter($parameter);
            try {
                $arguments[$parameter->getName()] = $this->injector->get(RequestHydrator::class, $context)
                    ->bindParameter(
                        $target,
                        $input,
                        $handler->getName(),
                        preserveQueryStrings: $fromQuery,
                        strictJsonIntegers: $jsonBody
                    );
            } catch (HydrationException $exception) {
                if ($fromQuery) {
                    throw new InvalidQueryParameterException($exception->getMessage(), previous: $exception);
                }

                if (!$fromRoute) {
                    throw new UnprocessableContent("Invalid or missing body field: {$target->sourceName}");
                }

                throw new InvalidRouteParameterException($exception->getMessage(), previous: $exception);
            }
        }

        return $arguments;
    }
}
