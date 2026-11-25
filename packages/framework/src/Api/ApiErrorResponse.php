<?php

declare(strict_types=1);

namespace Atom\Api;

use Atom\Di\InjectionContext;
use Atom\Di\Injector;
use Atom\Dispatcher\ResponseResultInterface;
use Atom\Http\HttpStatus;
use Atom\Http\Response;
use RuntimeException;

abstract class ApiErrorResponse extends RuntimeException implements ResponseResultInterface
{
    public const HttpStatus STATUS = HttpStatus::InternalServerError;

    public function __construct(public readonly string $error)
    {
        parent::__construct($error);
    }

    public function toResponse(Injector $injector, InjectionContext $context): Response
    {
        return $injector->get(Response::class, $context)->status(static::STATUS->value)->json($this);
    }
}
