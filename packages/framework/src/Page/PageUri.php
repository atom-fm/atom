<?php

declare(strict_types=1);

namespace Atom\Page;

use Atom\Router\MatchedRoute;
use BackedEnum;
use ReflectionObject;
use ReflectionProperty;

final readonly class PageUri
{
    public function hasBindings(Page $page): bool
    {
        foreach ((new ReflectionObject($page))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getAttributes(Uri::class) !== []) return true;
        }
        return false;
    }

    /** @param array<string, mixed> $overrides */
    public function url(Page $page, MatchedRoute $route, array $overrides = []): string
    {
        $values = $route->getRouteParams();
        $defaults = [];
        $aliases = [];
        foreach ((new ReflectionObject($page))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $attribute = $property->getAttributes(Uri::class)[0] ?? null;
            if ($attribute === null || $property->isStatic() || !$property->isInitialized($page)) continue;
            $name = $attribute->newInstance()->name ?? $property->getName();
            $aliases[$property->getName()] = $name;
            $aliases[$name] = $name;
            $values[$name] = $this->value($property->getValue($page));
            if ($property->hasDefaultValue()) $defaults[$name] = $this->value($property->getDefaultValue());
        }
        foreach ($overrides as $name => $value) {
            $name = $aliases[$name] ?? $name;
            if (!array_key_exists($name, $values)) throw new PageActionException("Unknown URI parameter '{$name}'.");
            $values[$name] = $this->value($value);
        }
        $path = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)(\*)?\}/', function (array $match) use (&$values): string {
            $name = $match[1];
            $value = $values[$name] ?? null;
            unset($values[$name]);
            if (!is_scalar($value) || (string) $value === '') throw new PageActionException("Missing URI route parameter '{$name}'.");
            $segments = isset($match[2]) ? explode('/', (string) $value) : [(string) $value];
            if (array_intersect($segments, ['.', '..']) !== []) throw new PageActionException("URI route parameters cannot contain dot segments.");
            return implode('/', array_map(rawurlencode(...), $segments));
        }, $route->getRouteEntry()->getFullPath());
        $query = [];
        foreach ($values as $name => $value) {
            if ($value === null || $value === '' || (array_key_exists($name, $defaults) && $value === $defaults[$name])) continue;
            $query[$name] = $value;
        }
        $encoded = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        return ($path ?? $route->getRouteEntry()->getFullPath()).($encoded === '' ? '' : '?'.$encoded);
    }

    private function value(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) return $value->value;
        if (is_array($value)) return array_map($this->value(...), $value);
        if ($value === null || is_scalar($value)) return $value;
        throw new PageActionException("URI values must be scalar, arrays, or backed enums.");
    }
}
