<?php

declare(strict_types=1);

namespace Atom\Hydrator\Exception;

use RuntimeException;
use Throwable;

final class HydrationException extends RuntimeException
{
    public function __construct(
        string $message = "",
        int $code = 0,
        ?Throwable $previous = null,
        public readonly ?string $fieldPath = null,
        public readonly ?string $source = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
