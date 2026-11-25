<?php

declare(strict_types=1);

namespace Atom\Router\Attributes;

use Atom\Http\MiddlewareInterface;

abstract readonly class HttpVerbRoute extends HttpRoute
{
    protected const string METHOD = "";

    /** @param class-string<MiddlewareInterface>|MiddlewareInterface|array<class-string<MiddlewareInterface>|MiddlewareInterface> $middleware */
    public function __construct(
        string $path,
        ?string $name = null,
        string|MiddlewareInterface|array $middleware = [],
        ?string $title = null,
        ?string $description = null
    ) {
        parent::__construct(static::METHOD, $path, $name, $middleware, $title, $description);
    }
}
