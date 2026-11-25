<?php

declare(strict_types=1);

namespace Atom\Page;

use Atom\Http\Request;
use Atom\Hydrator\Exception\HydrationException;
use Atom\Hydrator\HydrationContext;
use Atom\Hydrator\HydrationTarget;
use Atom\Hydrator\ValueCoercer;
use Atom\Router\MatchedRoute;
use ReflectionClass;

/** Bind only explicitly attributed request inputs; leave services and defaults to DI. */
final readonly class PageConstructorParameterBinder
{
    public function __construct(private ValueCoercer $coercer = new ValueCoercer()) {}

    /**
     * @param class-string<Page> $pageClass
     * @return array<string, mixed>
     */
    public function bind(string $pageClass, Request $request, ?MatchedRoute $route = null): array
    {
        $constructor = (new ReflectionClass($pageClass))->getConstructor();
        if ($constructor === null) return [];
        $context = HydrationContext::fromRequest($request, $route?->getRouteParams() ?? []);
        $parameters = [];
        foreach ($constructor->getParameters() as $parameter) {
            $target = HydrationTarget::fromParameter($parameter);
            if ($target->source === null) continue;
            $input = $context->resolve($target->source, $target->sourceName);
            if (!$input['found'] && $target->hasDefaultValue) continue;
            try {
                $parameters[$target->name] = $this->coercer->coerce($input['value'], $target, $pageClass);
            } catch (HydrationException $exception) {
                throw new PageActionException(
                    "Unable to bind constructor parameter '{$target->name}' on {$pageClass}. " . $exception->getMessage(),
                    previous: $exception
                );
            }
        }
        return $parameters;
    }
}
