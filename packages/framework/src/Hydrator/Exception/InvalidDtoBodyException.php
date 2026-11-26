<?php

declare(strict_types=1);

namespace Atom\Hydrator\Exception;

use Atom\Di\Exception\UnrecoverableDependencyException;

final class InvalidDtoBodyException extends UnrecoverableDependencyException
{
    public function __construct(string $fieldPath, HydrationException $previous)
    {
        parent::__construct("Invalid or missing body field: {$fieldPath}", $previous);
    }
}
