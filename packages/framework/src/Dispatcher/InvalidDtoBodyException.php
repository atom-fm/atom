<?php

declare(strict_types=1);

namespace Atom\Dispatcher;

use Atom\Di\Exception\UnrecoverableDependencyException;
use Atom\Hydrator\Exception\HydrationException;

final class InvalidDtoBodyException extends UnrecoverableDependencyException
{
    public function __construct(string $fieldPath, HydrationException $previous)
    {
        parent::__construct("Invalid or missing body field: {$fieldPath}", $previous);
    }
}
