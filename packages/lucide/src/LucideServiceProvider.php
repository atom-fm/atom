<?php

declare(strict_types=1);

namespace Atom\Lucide;

use Atom\Di\Bindings;
use Atom\Di\ServiceProviderInterface;
use Atom\Modules\Icons\IconProviderInterface;
use Atom\Modules\Icons\IconRegistry;

final readonly class LucideServiceProvider implements ServiceProviderInterface, IconProviderInterface
{
    public function register(Bindings $bindings): void
    {
    }

    public function icons(IconRegistry $icons): void
    {
        $icons->register("lucide", new LucideIconResolver());
    }
}
