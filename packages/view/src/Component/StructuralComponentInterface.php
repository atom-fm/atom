<?php

declare(strict_types=1);

namespace Atom\View\Component;

use Atom\View\Ast\ElementNode;

interface StructuralComponentInterface extends ComponentInterface
{
    public function renderStructure(ElementNode $node, StructuralComponentContext $context): string;
}
