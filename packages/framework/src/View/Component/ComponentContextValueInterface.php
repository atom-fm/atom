<?php

declare(strict_types=1);

namespace Atom\View\Component;

use ReflectionType;

/** A context value that can supply a default for a component property of the same name. */
interface ComponentContextValueInterface
{
    public function supportsComponentProperty(string $name, ?ReflectionType $type): bool;
}
