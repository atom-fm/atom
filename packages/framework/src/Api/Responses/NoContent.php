<?php

declare(strict_types=1);

namespace Atom\Api\Responses;

use Atom\Api\ApiResponse;
use Atom\Di\InjectionContext;
use Atom\Di\Injector;
use Atom\Http\HttpStatus;
use Atom\Http\Response;

final class NoContent extends ApiResponse
{
    public const HttpStatus STATUS = HttpStatus::NoContent;

    public function toResponse(Injector $injector, InjectionContext $context): Response
    {
        return $injector->get(Response::class, $context)->noContent();
    }
}
