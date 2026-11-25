<?php

declare(strict_types=1);

namespace Atom\Hydrator;

use Atom\Di\InjectionContext;
use Atom\Di\Injector;
use Atom\Di\TypeFactory;
use Atom\Di\TypeInfo;
use Atom\Http\Request;
use Atom\Hydrator\Attributes\Dto;
use Atom\Hydrator\Exception\HydrationException;
use Atom\Hydrator\Exception\InvalidDtoBodyException;
use Atom\Router\MatchedRoute;

final readonly class DtoTypeFactory
{
    public static function create(): TypeFactory
    {
        return TypeFactory::match(
            static fn(TypeInfo $type): bool => $type->hasAttribute(Dto::class),
            static function (string $className, Injector $injector, InjectionContext $context): object {
                $request = $injector->get(Request::class, $context);
                $route = $context->get(MatchedRoute::class);
                $routeParams = $route instanceof MatchedRoute ? $route->getRouteParams() : [];

                $hydrator = $injector->get(RequestHydrator::class, $context);
                try {
                    return $hydrator->hydrate($className, HydrationContext::fromRequest($request, $routeParams));
                } catch (HydrationException $exception) {
                    if ($exception->source !== "body" || $exception->fieldPath === null) {
                        throw $exception;
                    }

                    throw new InvalidDtoBodyException($exception->fieldPath, $exception);
                }
            }
        );
    }
}
