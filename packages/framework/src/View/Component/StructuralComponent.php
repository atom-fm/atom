<?php

declare(strict_types=1);

namespace Atom\View\Component;

use LogicException;

abstract class StructuralComponent implements StructuralComponentInterface
{
    final public function render(): never
    {
        throw new LogicException("Structural components must be rendered by the view renderer.");
    }
}
