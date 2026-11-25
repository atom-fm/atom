<?php

declare(strict_types=1);

namespace Atom\Api\Attributes;

use Atom\Http\HttpStatus;
use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class ErrorResponse
{
    public int $status;

    public function __construct(
        int|HttpStatus $status,
        public string $type,
        public ?string $description = null
    ) {
        $this->status = $status instanceof HttpStatus ? $status->value : $status;
    }
}
