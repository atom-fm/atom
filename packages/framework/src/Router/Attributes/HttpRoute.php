<?php

declare(strict_types=1);

namespace Atom\Router\Attributes;

use Atom\Http\MiddlewareInterface;
use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
readonly class HttpRoute
{
    /** @var array<class-string<MiddlewareInterface>|MiddlewareInterface> */
    public array $middlewares;

    /** @param class-string<MiddlewareInterface>|MiddlewareInterface|array<class-string<MiddlewareInterface>|MiddlewareInterface> $middleware */
    public function __construct(
        public string|array $method,
        public string $path,
        public ?string $name = null,
        string|MiddlewareInterface|array $middleware = [],
        public ?string $title = null,
        public ?string $description = null
    ) {
        $this->middlewares = is_array($middleware) ? array_values($middleware) : [$middleware];
    }
}
