<?php

declare(strict_types=1);

namespace Atom\Modules\ComponentStyles;

use Atom\Module\ModuleContext;
use Atom\Module\ModuleInterface;
use Atom\Router\RouteEntry;

final readonly class ComponentStylesModule implements ModuleInterface
{
    public function __construct(private bool $development = false, private string $path = "/assets/components.css")
    {
    }

    public function register(ModuleContext $context): void
    {
        if ($this->development) {
            $context->route(RouteEntry::get($this->path, [ComponentStylesController::class, "styles"]));
        }
    }
}
