<?php

declare(strict_types=1);

namespace Atom\Api\Responses;

use Atom\Api\ApiErrorResponse;
use Atom\Http\HttpStatus;

final class NotFound extends ApiErrorResponse
{
    public const HttpStatus STATUS = HttpStatus::NotFound;
}
