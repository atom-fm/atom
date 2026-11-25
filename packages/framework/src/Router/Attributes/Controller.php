<?php

declare(strict_types=1);

namespace Atom\Router\Attributes;

use Atom\Http\MiddlewareInterface;
use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
readonly class Controller
{
    /** @var array<class-string<MiddlewareInterface>|MiddlewareInterface> */
    public array $middlewares;

    /** @param class-string<MiddlewareInterface>|MiddlewareInterface|array<class-string<MiddlewareInterface>|MiddlewareInterface> $middleware */
    public function __construct(
        public string $path = "",
        string|MiddlewareInterface|array $middleware = []
    ) {
        $this->middlewares = is_array($middleware) ? array_values($middleware) : [$middleware];
    }
}
