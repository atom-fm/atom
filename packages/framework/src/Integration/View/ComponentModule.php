<?php

declare(strict_types=1);

namespace Atom\Integration\View;

use Atom\Module\ModuleContext;
use Atom\Module\ModuleInterface;
use Atom\View\Component\ComponentSet;

final readonly class ComponentModule implements ModuleInterface
{
    public function __construct(
        private ComponentSet $definitions,
        private string $resourceDirectory,
        private string $resourcePath
    ) {
    }

    public function register(ModuleContext $context): void
    {
        $context->exportComponents($this->definitions);
        $context->resources($this->resourcePath, $this->resourceDirectory);
    }
}
