<?php

declare(strict_types=1);

namespace Atom\Api\Responses;

use Atom\Api\ApiErrorResponse;
use Atom\Http\HttpStatus;

final class Conflict extends ApiErrorResponse
{
    public const HttpStatus STATUS = HttpStatus::Conflict;
}
