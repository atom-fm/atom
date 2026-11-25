<?php

declare(strict_types=1);

namespace Atom\Api\Responses;

use Atom\Api\ApiResponse;
use Atom\Di\InjectionContext;
use Atom\Di\Injector;
use Atom\Http\HttpStatus;
use Atom\Http\Response;

final class Created extends ApiResponse
{
    public const HttpStatus STATUS = HttpStatus::Created;

    public function __construct(
        private readonly string $location,
        private readonly mixed $payload
    ) {
    }

    public function toResponse(Injector $injector, InjectionContext $context): Response
    {
        return $injector->get(Response::class, $context)->created($this->location, $this->payload);
    }
}
