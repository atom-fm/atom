<?php

declare(strict_types=1);

namespace Atom\Api;

use Atom\Di\InjectionContext;
use Atom\Di\Injector;
use Atom\Dispatcher\ResponseResultInterface;
use Atom\Http\HttpStatus;
use Atom\Http\Response;

abstract class ApiResponse implements ResponseResultInterface
{
    public const HttpStatus STATUS = HttpStatus::Ok;

    public function toResponse(Injector $injector, InjectionContext $context): Response
    {
        return $injector->get(Response::class, $context)->status(static::STATUS->value)->json($this);
    }
}
