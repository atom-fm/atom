<?php

declare(strict_types=1);

namespace Atom\Router;

use Atom\Http\MiddlewareInterface;
use InvalidArgumentException;

class Router
{
    private string $path = "";
    private ?Router $parent = null;
    /** @var array<class-string<MiddlewareInterface>|MiddlewareInterface> */
    private array $middlewares = [];
    /** @var array<RouteEntry|Router> */
    private array $items = [];

    public function __construct(string $path = "")
    {
        $this->path = $path;
    }

    /**
     * @return RouteEntry[]
     */
    public function getAllRoutes(): array
    {
        $result = [];

        foreach ($this->items as $item) {
            if ($item instanceof RouteEntry) {
                $result[] = $item;
            } else {
                foreach ($item->getAllRoutes() as $entry) {
                    $result[] = $entry;
                }
            }
        }

        return $result;
    }

    /**
     * @param array<string, string|int> $parameters
     * @param array<string, mixed> $query
     */
    public function url(string $name, array $parameters = [], array $query = []): string
    {
        $path = null;
        foreach ($this->getAllRoutes() as $entry) {
            if ($entry->getName() !== $name) {
                continue;
            }

            $candidate = $entry->getFullPath();
            if ($path !== null && $path !== $candidate) {
                throw new InvalidArgumentException("Route name '{$name}' matches more than one path.");
            }
            $path = $candidate;
        }

        if ($path === null) {
            throw new InvalidArgumentException("Unknown route name '{$name}'.");
        }

        $used = [];
        $segments = explode("/", $path);
        foreach ($segments as $index => $segment) {
            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)(\*)?\}$/D', $segment, $match) !== 1) {
                continue;
            }

            $key = $match[1];
            $value = $parameters[$key] ?? null;
            if ($value === null) {
                throw new InvalidArgumentException("Missing parameter '{$key}' for route '{$name}'.");
            }
            if (!is_string($value) && !is_int($value)) {
                throw new InvalidArgumentException("Parameter '{$key}' for route '{$name}' must be a string or integer.");
            }

            $parts = isset($match[2]) ? explode("/", (string) $value) : [(string) $value];
            if (array_intersect($parts, ["", ".", ".."]) !== []) {
                throw new InvalidArgumentException("Parameter '{$key}' for route '{$name}' contains an empty or dot segment.");
            }

            $segments[$index] = implode("/", array_map(rawurlencode(...), $parts));
            $used[$key] = true;
        }

        $unused = array_diff_key($parameters, $used);
        if ($unused !== []) {
            $key = array_key_first($unused);
            throw new InvalidArgumentException("Unknown parameter '{$key}' for route '{$name}'.");
        }

        $url = implode("/", $segments);
        $queryString = http_build_query($query, "", "&", PHP_QUERY_RFC3986);

        return $url . ($queryString === "" ? "" : "?" . $queryString);
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getFullPath(): string
    {
        $prefixPath = rtrim($this->parent?->getFullPath() ?? "", " /");
        $routerPath = ltrim($this->path, " /");

        if ($routerPath != "") {
            $routerPath = "/" . $routerPath;
        }

        $result = $prefixPath . $routerPath;

        return $result == "" ? "/" : $result;
    }

    public function middleware(string|MiddlewareInterface $middleware): self
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    public function getOwnMiddlewares(): array
    {
        return $this->middlewares;
    }

    public function getMiddlewares(): array
    {
        return array_merge($this->parent?->getMiddlewares() ?? [], $this->middlewares);
    }

    /**
     * @return array<RouteEntry|Router>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * @return RouteEntry[]
     */
    public function getRoutes(): array
    {
        return array_values(array_filter($this->items, function (RouteEntry|Router $item): bool {
            return $item instanceof RouteEntry;
        }));
    }

    public function add(RouteEntry|Router $item): RouteEntry|Router
    {
        if ($item instanceof RouteEntry) {
            $item->bindRouter($this);
        } else {
            $item->parent = $this;
        }

        $this->items[] = $item;
        return $item;
    }
}
